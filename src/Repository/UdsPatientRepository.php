<?php

/**
 * Loads the UDS patients for a calendar year from OpenEMR's tables.
 *
 * A UDS patient has at least one countable visit in the year (2026 UDS Manual,
 * "Instructions for Counting"). An EHR can't judge every part of that definition,
 * so a visit here is an encounter that:
 * - falls in the year,
 * - has a provider,
 * - is in a visit category the clinic marked countable (default: every patient
 *   category except No Show), and
 * - is at a facility in the scope of project (default: all).
 * Labs-only, injections, screenings and outreach should be recorded under
 * categories the clinic leaves unticked.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\Enum\AgriculturalWorker;
use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\LastVisitInsurance;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\InsuranceCategoryMap;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final readonly class UdsPatientRepository
{
    private const CHUNK = 500;
    private const NOT_LIMITED_ENGLISH = ['', 'english', 'decline_to_specify', 'declne_to_specfy'];

    public function __construct(private InsuranceCategoryMap $insuranceCategories)
    {
    }

    /**
     * @return list<UdsPatient>
     */
    public function forYear(int $year, UdsSettings $settings): array
    {
        $visits = $this->countableVisits($year, $settings);
        if ($visits === []) {
            return [];
        }
        $raceCodes = $this->listCodes('race');
        $ethnicityCodes = $this->listCodes('ethnicity');
        $siteZips = $this->facilityZips();
        $companies = $this->insuranceCompanies($settings);

        $patients = [];
        foreach (array_chunk(array_keys($visits), self::CHUNK) as $pids) {
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $insurance = $this->insuranceRows($pids, $placeholders);
            $rows = QueryUtils::fetchRecords(
                "SELECT pid, DOB, sex, race, ethnicity, language, interpreter_needed, postal_code,
                        monthly_income, family_size, financial_review,
                        uds_housing_status, uds_agricultural_worker, uds_veteran_status
                   FROM patient_data WHERE pid IN ($placeholders)",
                $pids,
            );
            foreach ($rows as $row) {
                $pid = Row::int($row, 'pid');
                $visit = $visits[$pid] ?? null;
                if ($visit === null) {
                    continue;
                }
                $atVisit = self::insuranceAt($insurance[$pid] ?? [], $visit['last'], $companies);
                $language = strtolower(trim(Row::text($row, 'language')));

                $patients[] = new UdsPatient(
                    pid: $pid,
                    dob: self::date(Row::text($row, 'DOB')),
                    sexAtBirth: SexAtBirth::fromPatientData(Row::text($row, 'sex')),
                    raceCodes: self::codes(Row::text($row, 'race'), $raceCodes),
                    ethnicityCodes: self::codes(Row::text($row, 'ethnicity'), $ethnicityCodes),
                    limitedEnglish: !in_array($language, self::NOT_LIMITED_ENGLISH, true)
                        || strtolower(Row::text($row, 'interpreter_needed')) === 'yes',
                    zip: self::zip(Row::text($row, 'postal_code')),
                    visitSiteZip: $siteZips[$visit['lastSite']] ?? null,
                    annualIncome: self::annualIncome(Row::text($row, 'monthly_income')),
                    familySize: self::positiveInt(Row::text($row, 'family_size')),
                    incomeVerified: self::incomeVerified(Row::text($row, 'financial_review'), $visit['last']),
                    insurance: $atVisit->category,
                    dualEligible: $atVisit->dualEligible,
                    insuranceRecorded: $atVisit->recorded,
                    housingStatus: HousingStatus::tryFrom(Row::text($row, 'uds_housing_status')),
                    agriculturalWorker: AgriculturalWorker::tryFrom(Row::text($row, 'uds_agricultural_worker')),
                    veteranStatus: VeteranStatus::tryFrom(Row::text($row, 'uds_veteran_status')),
                    visitSiteIds: array_keys($visit['sites']),
                    lastVisit: $visit['last'],
                    incomeGaps: self::incomeGaps(
                        Row::text($row, 'monthly_income'),
                        Row::text($row, 'family_size'),
                        Row::text($row, 'financial_review'),
                        $visit['last'],
                    ),
                );
            }
        }
        usort($patients, static fn(UdsPatient $a, UdsPatient $b): int => $a->pid <=> $b->pid);
        return $patients;
    }

    /**
     * Visit categories counted by default: every patient appointment category except No Show.
     *
     * @return list<int>
     */
    public function defaultCountableCategories(): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_cattype = 0 AND pc_constant_id <> 'no_show'",
        );
        return array_map(static fn(array $row): int => Row::int($row, 'pc_catid'), $rows);
    }

    /**
     * Primary medical insurance in effect at the last visit (latest-starting if several),
     * and whether Medicaid was also in effect behind a Medicare primary (dually eligible).
     *
     * @param list<array<mixed>>             $rows      insurance_data rows: type, provider, date, date_end
     * @param array<int, InsuranceCategory>  $companies insurance company id => category
     */
    public static function insuranceAt(array $rows, DateTimeImmutable $lastVisit, array $companies): LastVisitInsurance
    {
        $day = $lastVisit->format('Y-m-d');
        $active = array_values(array_filter($rows, static function (array $r) use ($day): bool {
            $start = self::date(Row::text($r, 'date'))?->format('Y-m-d');
            $end = self::date(Row::text($r, 'date_end'))?->format('Y-m-d');
            return ($start === null || $start <= $day) && ($end === null || $end >= $day);
        }));
        $primary = array_values(array_filter($active, static fn(array $r): bool => Row::text($r, 'type') === 'primary'));
        if ($primary === []) {
            return new LastVisitInsurance(InsuranceCategory::None, false, false);
        }
        usort($primary, static fn(array $a, array $b): int => strcmp(Row::text($b, 'date'), Row::text($a, 'date')));
        $category = $companies[Row::nullableInt($primary[0], 'provider') ?? 0] ?? InsuranceCategory::Unmapped;

        $dual = false;
        if ($category === InsuranceCategory::Medicare) {
            foreach ($active as $r) {
                $other = $companies[Row::nullableInt($r, 'provider') ?? 0] ?? null;
                if (Row::text($r, 'type') !== 'primary' && $other !== null && $other->isMedicaid()) {
                    $dual = true;
                }
            }
        }
        return new LastVisitInsurance($category, $dual, true);
    }

    public static function zip(string $value): ?string
    {
        return preg_match('/^\s*(\d{5})(?:-?\d{4})?\s*$/D', $value, $m) === 1 ? $m[1] : null;
    }

    /** Monthly income as entered (it may carry "$" and ","), times 12; null when it isn't a number. */
    /**
     * One monthly amount, optionally with a dollar sign and thousands
     * separators, as yearly income. Anything else is not guessed at: stripping
     * every non-digit once read "$2,000 - $2,500" as 20002500 a month and
     * "1.5k" as $1.50, and both landed on a real poverty line.
     */
    public static function annualIncome(string $monthly): ?int
    {
        if (preg_match('/^\s*\$?\s*(\d{1,3}(?:,\d{3})+|\d+)(\.\d{1,2})?\s*$/D', $monthly, $m) !== 1) {
            return null;
        }
        return (int)floor((float)(str_replace(',', '', $m[1]) . ($m[2] ?? '')) * 12);
    }

    /**
     * Why the income can't be used, one reason per input, in DataGap order.
     * OpenEMR's Financial Review Date is a text box on a datetime column, and
     * OpenEMR saves with strict mode off, so a date typed any way other than
     * YYYY-MM-DD is stored as 0000-00-00: a date that was not saved, not one
     * that was never entered, and staff need to be told which.
     *
     * @return list<DataGap>
     */
    public static function incomeGaps(string $monthly, string $familySize, string $reviewDate, DateTimeImmutable $lastVisit): array
    {
        $gaps = [];
        if (trim($monthly) === '') {
            $gaps[] = DataGap::IncomeNotRecorded;
        } elseif (self::annualIncome($monthly) === null) {
            $gaps[] = DataGap::IncomeNotUnderstood;
        }
        if (trim($familySize) === '') {
            $gaps[] = DataGap::FamilySizeNotRecorded;
        } elseif (self::positiveInt($familySize) === null) {
            $gaps[] = DataGap::FamilySizeNotUnderstood;
        }
        if (trim($reviewDate) === '') {
            $gaps[] = DataGap::ReviewDateMissing;
        } elseif (self::date($reviewDate) === null) {
            $gaps[] = DataGap::ReviewDateNotSaved;
        } elseif (!self::incomeVerified($reviewDate, $lastVisit)) {
            $gaps[] = DataGap::ReviewDateOutsideWindow;
        }
        return $gaps;
    }

    /**
     * Income counts only if it was collected at the last visit or within the
     * 12 months before it (Table 4, Lines 1-5).
     */
    public static function incomeVerified(string $reviewDate, DateTimeImmutable $lastVisit): bool
    {
        $reviewed = self::date($reviewDate);
        if ($reviewed === null) {
            return false;
        }
        return $reviewed <= $lastVisit->setTime(23, 59, 59)
            && $reviewed >= $lastVisit->setTime(0, 0)->modify('-12 months');
    }

    /**
     * @return array<int, array{last: DateTimeImmutable, lastSite: int, sites: array<int, true>}>
     */
    private function countableVisits(int $year, UdsSettings $settings): array
    {
        $categories = $settings->countableCategoryIds ?? $this->defaultCountableCategories();
        if ($categories === []) {
            return [];
        }
        $binds = [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1), ...$categories];
        $categoryIn = implode(',', array_fill(0, count($categories), '?'));
        $facilityFilter = '';
        if ($settings->inScopeFacilityIds !== null) {
            if ($settings->inScopeFacilityIds === []) {
                return [];
            }
            $facilityFilter = 'AND fe.facility_id IN (' . implode(',', array_fill(0, count($settings->inScopeFacilityIds), '?')) . ')';
            array_push($binds, ...$settings->inScopeFacilityIds);
        }

        $rows = QueryUtils::fetchRecords(
            "SELECT fe.pid, fe.date, fe.facility_id
               FROM form_encounter AS fe
               JOIN patient_data AS pd ON pd.pid = fe.pid
              WHERE fe.date >= ? AND fe.date < ?
                AND fe.provider_id > 0
                AND fe.pc_catid IN ($categoryIn)
                $facilityFilter
              ORDER BY fe.date",
            $binds,
        );
        $visits = [];
        foreach ($rows as $row) {
            $date = self::date(Row::text($row, 'date'));
            if ($date === null) {
                continue;
            }
            $pid = Row::int($row, 'pid');
            $site = Row::nullableInt($row, 'facility_id') ?? 0;
            // Rows are in date order, so the last one seen is the last visit.
            $visits[$pid] = [
                'last' => $date,
                'lastSite' => $site,
                'sites' => ($visits[$pid]['sites'] ?? []) + [$site => true],
            ];
        }
        return $visits;
    }

    /**
     * @return array<string, string> list option_id => CDC code (from list_options.notes)
     */
    private function listCodes(string $listId): array
    {
        $map = [];
        foreach (QueryUtils::fetchRecords('SELECT option_id, notes FROM list_options WHERE list_id = ?', [$listId]) as $row) {
            $code = Row::text($row, 'notes');
            if (preg_match('/^\d{4}-\d$/D', $code) === 1) {
                $map[Row::text($row, 'option_id')] = $code;
            }
        }
        return $map;
    }

    /** @return array<int, string> facility id => ZIP */
    private function facilityZips(): array
    {
        $zips = [];
        foreach (QueryUtils::fetchRecords('SELECT id, postal_code FROM facility') as $row) {
            $zip = self::zip(Row::text($row, 'postal_code'));
            if ($zip !== null) {
                $zips[Row::int($row, 'id')] = $zip;
            }
        }
        return $zips;
    }

    /**
     * @return array<int, InsuranceCategory> insurance company id => category
     */
    private function insuranceCompanies(UdsSettings $settings): array
    {
        $categories = [];
        foreach (QueryUtils::fetchRecords('SELECT id, cqm_sop, ins_type_code FROM insurance_companies') as $row) {
            $id = Row::int($row, 'id');
            $categories[$id] = $this->insuranceCategories->forCompany(
                $settings->insuranceOverrides[$id] ?? null,
                Row::nullableText($row, 'cqm_sop'),
                Row::nullableInt($row, 'ins_type_code'),
            );
        }
        return $categories;
    }

    /**
     * @param list<int> $pids
     * @return array<int, list<array<mixed>>>
     */
    private function insuranceRows(array $pids, string $placeholders): array
    {
        $byPid = [];
        $rows = QueryUtils::fetchRecords(
            "SELECT pid, type, provider, date, date_end FROM insurance_data
              WHERE pid IN ($placeholders) AND provider IS NOT NULL AND provider <> '' AND provider <> '0'",
            $pids,
        );
        foreach ($rows as $row) {
            $byPid[Row::int($row, 'pid')][] = $row;
        }
        return $byPid;
    }

    /**
     * @param array<string, string> $map option_id => CDC code
     * @return list<string>
     */
    private static function codes(string $value, array $map): array
    {
        $codes = [];
        foreach (preg_split('/[|,]/', $value) ?: [] as $optionId) {
            $optionId = trim($optionId);
            if ($optionId !== '' && isset($map[$optionId])) {
                $codes[] = $map[$optionId];
            }
        }
        return $codes;
    }

    private static function positiveInt(string $value): ?int
    {
        if (preg_match('/^\s*\d{1,9}\s*$/D', $value) !== 1) {
            return null;
        }
        $n = (int)$value;
        return $n >= 1 ? $n : null;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
