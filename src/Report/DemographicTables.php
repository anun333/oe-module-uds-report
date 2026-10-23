<?php

/**
 * Builds the UDS Patients by ZIP Code table and Tables 3A, 3B and 4 from UDS
 * patients. No database access, so every counting rule is unit tested, and the
 * expected cells are shared data (data/cases/demographic-tables.json).
 *
 * Rules are from the 2026 UDS Manual; the line numbers below are its own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceData;

/**
 * Line and ZIP keys such as '8' or '10001' are int array keys in PHP, so the
 * keyed parts of each table are typed array<int|string, ...>.
 *
 * @phpstan-type ZipRow array{none: int, public: int, medicare: int, private: int, total: int}
 * @phpstan-type ZipTable array{rows: array<int|string, ZipRow>, unmapped: int, total: int}
 * @phpstan-type Table3A array{lines: array<int, array{label: string, male: int, female: int}>, totalMale: int, totalFemale: int, unplaced: int}
 * @phpstan-type Table3B array{cells: array<int|string, array<string, int>>, line12: int}
 * @phpstan-type Table4 array{income: array<int|string, int>, insurance: array<int|string, array{child: int, adult: int}>, insuranceUnmapped: int, insuranceAgeUnknown: int, special: array<int|string, int>, povertyTableAvailable: bool}
 * @phpstan-type CrossCheck array{patients: int, zip: int, table3A: int, table3B: int, table4Income: int, table4Insurance: int, consistent: bool}
 */
final readonly class DemographicTables
{
    public const ZIP_COLUMNS = ['none', 'public', 'medicare', 'private'];
    public const OTHER_ZIPS = 'Other ZIP Codes';
    public const UNKNOWN_RESIDENCE = 'Unknown Residence';
    /** "Combine and report patients from ZIP codes that have 10 or fewer patients." */
    private const SMALL_ZIP_MAX = 10;
    private const INCOME_LINES = ['1', '2', '3', '4', '5'];
    private const INSURANCE_LINES = ['7', '8a', '8b', '8', '9a', '9', '10a', '10b', '10', '11', '12'];
    private const SPECIAL_LINES = ['14', '15', '16', '17', '18', '19', '20', '21a', '21', '22', '23', '24', '25', '26'];

    /**
     * @param list<UdsPatient> $patients
     */
    public function __construct(
        private array $patients,
        private int $year,
        private UdsSettings $settings,
        private ReferenceData $reference,
    ) {
    }

    /** @return ZipTable rows keyed by ZIP, then Other ZIP Codes and Unknown Residence */
    public function zipTable(): array
    {
        $byZip = [];
        $unmapped = 0;
        foreach ($this->patients as $p) {
            $column = $p->insurance->zipColumn();
            if ($column === null) {
                $unmapped++;
                continue;
            }
            // People experiencing homelessness with no residence ZIP: the visit site's ZIP stands in.
            $zip = $p->zip ?? ($p->isHomeless() ? $p->visitSiteZip : null) ?? self::UNKNOWN_RESIDENCE;
            $byZip[$zip][$column] = ($byZip[$zip][$column] ?? 0) + 1;
        }

        $rows = [];
        $other = [];
        ksort($byZip, SORT_STRING);
        foreach ($byZip as $zip => $counts) {
            if ($zip === self::UNKNOWN_RESIDENCE) {
                continue;
            }
            if (array_sum($counts) <= self::SMALL_ZIP_MAX) {
                foreach ($counts as $c => $n) {
                    $other[$c] = ($other[$c] ?? 0) + $n;
                }
            } else {
                $rows[$zip] = self::zipRow($counts);
            }
        }
        $rows[self::OTHER_ZIPS] = self::zipRow($other);
        $rows[self::UNKNOWN_RESIDENCE] = self::zipRow($byZip[self::UNKNOWN_RESIDENCE] ?? []);

        $total = 0;
        foreach ($rows as $row) {
            $total += $row['total'];
        }
        return ['rows' => $rows, 'unmapped' => $unmapped, 'total' => $total];
    }

    /**
     * Every residence ZIP code and its patients, for the service area (ServiceArea): the ZIP code the
     * table would place each patient in, the visit site's for people experiencing homelessness with
     * none. Unlike the table, ZIP codes with 10 or fewer patients aren't folded together, and
     * patients whose insurance has no UDS column still count: they live somewhere.
     *
     * @return array{zips: array<string, int>, unknown: int}
     */
    public function zipCounts(): array
    {
        $zips = [];
        $unknown = 0;
        foreach ($this->patients as $p) {
            $zip = $p->zip ?? ($p->isHomeless() ? $p->visitSiteZip : null);
            if ($zip === null) {
                $unknown++;
                continue;
            }
            $zips[$zip] = ($zips[$zip] ?? 0) + 1;
        }
        return ['zips' => $zips, 'unknown' => $unknown];
    }

    /**
     * Lines 1-38 by age on December 31, columns male (A) and female (B).
     * The table has no unknown column, so patients without a recorded birth
     * sex or date of birth can't be placed; they are counted separately.
     *
     * @return Table3A
     */
    public function table3A(): array
    {
        $male = [];
        $female = [];
        $unplaced = 0;
        foreach ($this->patients as $p) {
            $age = $p->ageAtYearEnd($this->year);
            if ($age === null || $p->sexAtBirth === null) {
                $unplaced++;
                continue;
            }
            $line = self::ageLine($age);
            match ($p->sexAtBirth) {
                SexAtBirth::Male => $male[$line] = ($male[$line] ?? 0) + 1,
                SexAtBirth::Female => $female[$line] = ($female[$line] ?? 0) + 1,
            };
        }
        $lines = [];
        foreach (self::ageLines() as $line => $label) {
            $lines[$line] = ['label' => $label, 'male' => $male[$line] ?? 0, 'female' => $female[$line] ?? 0];
        }
        return [
            'lines' => $lines,
            'totalMale' => array_sum($male),
            'totalFemale' => array_sum($female),
            'unplaced' => $unplaced,
        ];
    }

    /**
     * Race (Lines 1a-7) by ethnicity (Columns A1-A5, B, C), with the subtotal
     * lines (1, 2, 8) and columns (A, D), plus Line 12 (limited English proficiency).
     *
     * @return Table3B
     */
    public function table3B(): array
    {
        $counts = [];
        $line12 = 0;
        foreach ($this->patients as $p) {
            $at = $this->reference->raceEthnicity->place($p->raceCodes, $p->ethnicityCodes);
            $counts[$at['line']][$at['column']] = ($counts[$at['line']][$at['column']] ?? 0) + 1;
            if ($p->limitedEnglish) {
                $line12++;
            }
        }
        $cells = [];
        foreach (RaceEthnicity::LINES as $line) {
            $row = [];
            foreach (RaceEthnicity::COLUMNS as $col) {
                $row[$col] = $counts[$line][$col] ?? 0;
            }
            $hispanic = 0;
            foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $col) {
                $hispanic += $row[$col] ?? 0;
            }
            $row['A'] = $hispanic;
            $row['D'] = $hispanic + ($row['B'] ?? 0) + ($row['C'] ?? 0);
            $cells[$line] = $row;
        }
        $cells['1'] = self::sumLines($cells, ['1a', '1b', '1c', '1d', '1e', '1f', '1g']);
        $cells['2'] = self::sumLines($cells, ['2a', '2b', '2c', '2d']);
        $cells['8'] = self::sumLines($cells, ['1', '2', '3', '4', '5', '6', '7']);
        return ['cells' => $cells, 'line12' => $line12];
    }

    /** @return Table4 */
    public function table4(): array
    {
        $income = [];
        $child = [];
        $adult = [];
        $unmapped = 0;
        $ageUnknown = 0;
        $special = [];
        $poverty = $this->reference->povertyGuidelines;
        $region = $this->settings->povertyRegion;

        foreach ($this->patients as $p) {
            // Lines 1-5: income relative to the guidelines, only if verified for the period.
            $line = $poverty->incomeLine(
                $p->incomeVerified ? $p->annualIncome : null,
                $p->incomeVerified ? $p->familySize : null,
                $this->year,
                $region,
            );
            $income[$line] = ($income[$line] ?? 0) + 1;

            // Lines 7-12: primary medical insurance at the last visit, by age on December 31.
            $age = $p->ageAtYearEnd($this->year);
            $line = $p->insurance->table4Line();
            if ($line === null) {
                $unmapped++;
            } elseif ($age === null) {
                $ageUnknown++;
            } else {
                $dual = $line === '9' && $p->dualEligible;
                if ($age <= 17) {
                    $child[$line] = ($child[$line] ?? 0) + 1;
                    $child['9a'] = ($child['9a'] ?? 0) + ($dual ? 1 : 0);
                } else {
                    $adult[$line] = ($adult[$line] ?? 0) + 1;
                    $adult['9a'] = ($adult['9a'] ?? 0) + ($dual ? 1 : 0);
                }
            }

            // Lines 14-26: special populations; a patient can be in several.
            $lines = [];
            $agricultureLine = $p->agriculturalWorker?->table4Line();
            if ($agricultureLine !== null) {
                array_push($lines, $agricultureLine, '16');
            }
            $housingLine = $p->housingStatus?->table4Line();
            if ($housingLine !== null) {
                array_push($lines, $housingLine, '23');
            }
            if (array_intersect($p->visitSiteIds, $this->settings->schoolBasedFacilityIds) !== []) {
                $lines[] = '24';
            }
            if ($p->veteranStatus === VeteranStatus::Veteran) {
                $lines[] = '25';
            }
            // Line 26 is site-based: everyone seen at a site in or next to public housing.
            if (array_intersect($p->visitSiteIds, $this->settings->publicHousingFacilityIds) !== []) {
                $lines[] = '26';
            }
            foreach ($lines as $specialLine) {
                $special[$specialLine] = ($special[$specialLine] ?? 0) + 1;
            }
        }

        $incomeLines = [];
        foreach (self::INCOME_LINES as $line) {
            $incomeLines[$line] = $income[$line] ?? 0;
        }
        $incomeLines['6'] = array_sum($incomeLines);
        $child = self::insuranceTotals($child);
        $adult = self::insuranceTotals($adult);
        $insurance = [];
        foreach (self::INSURANCE_LINES as $line) {
            $insurance[$line] = ['child' => $child[$line] ?? 0, 'adult' => $adult[$line] ?? 0];
        }
        $specialLines = [];
        foreach (self::SPECIAL_LINES as $line) {
            $specialLines[$line] = $special[$line] ?? 0;
        }

        return [
            'income' => $incomeLines,
            'insurance' => $insurance,
            'insuranceUnmapped' => $unmapped,
            'insuranceAgeUnknown' => $ageUnknown,
            'special' => $specialLines,
            'povertyTableAvailable' => $poverty->isAvailable($this->year, $region),
        ];
    }

    /**
     * The manual's cross-table rule: Table 3A Line 39 = Table 3B Line 8 Column D =
     * Patients by ZIP Code total = Table 4 Lines 6 and 12. A mismatch means
     * records that need fixing before submission.
     *
     * @return CrossCheck
     */
    public function crossCheck(): array
    {
        $t3a = $this->table3A();
        $t4 = $this->table4();
        $insurance = $t4['insurance']['12'] ?? ['child' => 0, 'adult' => 0];
        $totals = [
            'patients' => count($this->patients),
            'zip' => $this->zipTable()['total'],
            'table3A' => $t3a['totalMale'] + $t3a['totalFemale'],
            'table3B' => $this->table3B()['cells']['8']['D'] ?? 0,
            'table4Income' => $t4['income']['6'] ?? 0,
            'table4Insurance' => $insurance['child'] + $insurance['adult'],
        ];
        return $totals + ['consistent' => count(array_unique($totals)) === 1];
    }

    /** @return array<int, string> line => label, Lines 1-38 */
    public static function ageLines(): array
    {
        $lines = [1 => 'Under age 1'];
        for ($age = 1; $age <= 24; $age++) {
            $lines[$age + 1] = 'Age ' . $age;
        }
        $line = 26;
        for ($from = 25; $from <= 80; $from += 5) {
            $lines[$line++] = 'Ages ' . $from . '–' . ($from + 4);
        }
        $lines[38] = 'Age 85 and over';
        return $lines;
    }

    public static function ageLine(int $age): int
    {
        return match (true) {
            $age < 25 => $age + 1,
            $age >= 85 => 38,
            default => 26 + intdiv($age - 25, 5),
        };
    }

    /**
     * @param array<string, int> $counts
     * @return ZipRow
     */
    private static function zipRow(array $counts): array
    {
        $none = $counts['none'] ?? 0;
        $public = $counts['public'] ?? 0;
        $medicare = $counts['medicare'] ?? 0;
        $private = $counts['private'] ?? 0;
        return [
            'none' => $none,
            'public' => $public,
            'medicare' => $medicare,
            'private' => $private,
            'total' => $none + $public + $medicare + $private,
        ];
    }

    /**
     * Adds the subtotal lines of Table 4's insurance block (8, 10 and 12) for one age band.
     *
     * @param array<int|string, int> $band
     * @return array<int|string, int>
     */
    private static function insuranceTotals(array $band): array
    {
        $band['8'] = ($band['8a'] ?? 0) + ($band['8b'] ?? 0);
        $band['10'] = ($band['10a'] ?? 0) + ($band['10b'] ?? 0);
        $band['12'] = ($band['7'] ?? 0) + $band['8'] + ($band['9'] ?? 0) + $band['10'] + ($band['11'] ?? 0);
        return $band;
    }

    /**
     * Column-by-column sum of some Table 3B lines.
     *
     * @param array<int|string, array<string, int>> $cells
     * @param list<string>                          $lines
     * @return array<string, int>
     */
    private static function sumLines(array $cells, array $lines): array
    {
        $sum = [];
        foreach ([...RaceEthnicity::COLUMNS, 'A', 'D'] as $col) {
            $sum[$col] = 0;
            foreach ($lines as $line) {
                $sum[$col] += $cells[$line][$col] ?? 0;
            }
        }
        return $sum;
    }
}
