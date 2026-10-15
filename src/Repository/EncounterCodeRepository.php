<?php

/**
 * Encounters in the report year with the billing codes recorded on them, for
 * Table 6A. "Countable" is the same test UdsPatientRepository uses: a provider,
 * a countable visit category, and an in-scope site.
 *
 * The codes are the fee sheet's (the billing table's active rows). The manual
 * names billing systems and health records as the sources, and its FAQ prefers
 * coding a service with a zero charge over finding it elsewhere. Lines defined
 * by value sets of medications and screenings also need the codes recorded in
 * prescriptions, screenings and results, which come in as events.
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
use OpenEMR\Modules\UdsReport\Model\CodedEvent;
use OpenEMR\Modules\UdsReport\Model\EncounterCodes;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final readonly class EncounterCodeRepository
{
    /** OpenEMR billing code types by Table 6A code system. CDT codes are found by their shape instead. */
    private const SYSTEM_BY_TYPE = [
        'ICD10' => 'icd10',
        'CPT4' => 'cpt',
        'HCPCS' => 'hcpcs',
        'SNOMED' => 'snomed',
        'SNOMED-CT' => 'snomed',
        'SNOMED-PR' => 'snomed',
    ];

    public function __construct(private UdsPatientRepository $patients)
    {
    }

    /**
     * @param list<CodedEvent> $events codes recorded outside the fee sheet (ClinicalCodeRepository).
     *                                 One with an encounter joins that encounter's codes; one without
     *                                 is a visit of its own on its date, not countable and in scope.
     * @return list<EncounterCodes>
     */
    public function forYear(int $year, UdsSettings $settings, array $events = []): array
    {
        $categories = $settings->countableCategoryIds ?? $this->patients->defaultCountableCategories();
        $scope = $settings->inScopeFacilityIds;
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.pid, fe.encounter, fe.date, fe.provider_id, fe.pc_catid, fe.facility_id, pd.DOB,
                    b.code_type, b.code
               FROM form_encounter AS fe
               JOIN patient_data AS pd ON pd.pid = fe.pid
               JOIN billing AS b ON b.pid = fe.pid AND b.encounter = fe.encounter AND b.activity = 1
              WHERE fe.date >= ? AND fe.date < ?
              ORDER BY fe.pid, fe.encounter",
            [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)],
        );

        $byEncounter = [];
        foreach ($rows as $row) {
            $key = Row::int($row, 'pid') . ':' . Row::int($row, 'encounter');
            $byEncounter[$key] ??= ['row' => $row, 'codes' => []];
            foreach (self::systems(Row::text($row, 'code_type'), Row::text($row, 'code')) as $system) {
                $byEncounter[$key]['codes'][$system][] = Table6aCode::normalize(Row::text($row, 'code'));
            }
        }

        // Encounters that have events but no billing codes.
        $missing = [];
        foreach ($events as $event) {
            if ($event->encounter > 0 && !isset($byEncounter[$event->pid . ':' . $event->encounter])) {
                $missing[$event->encounter] = true;
            }
        }
        foreach (array_chunk(array_keys($missing), 500) as $chunk) {
            $found = QueryUtils::fetchRecords(
                'SELECT fe.pid, fe.encounter, fe.date, fe.provider_id, fe.pc_catid, fe.facility_id, pd.DOB
                   FROM form_encounter AS fe
                   JOIN patient_data AS pd ON pd.pid = fe.pid
                  WHERE fe.encounter IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk,
            );
            foreach ($found as $row) {
                $byEncounter[Row::int($row, 'pid') . ':' . Row::int($row, 'encounter')] ??= ['row' => $row, 'codes' => []];
            }
        }

        $out = [];
        $loose = [];
        foreach ($events as $event) {
            $key = $event->pid . ':' . $event->encounter;
            if ($event->encounter > 0 && isset($byEncounter[$key])) {
                $byEncounter[$key]['codes'][$event->system][] = $event->code;
                continue;
            }
            $day = $event->date->format('Y-m-d');
            $loose[$event->pid][$day][$event->system][] = $event->code;
        }

        foreach ($byEncounter as $entry) {
            $row = $entry['row'];
            $date = self::date(Row::text($row, 'date'));
            if ($date === null) {
                continue;
            }
            $facility = Row::nullableInt($row, 'facility_id') ?? 0;
            $inScope = $scope === null || in_array($facility, $scope, true);
            $out[] = new EncounterCodes(
                Row::int($row, 'pid'),
                Row::int($row, 'encounter'),
                $date,
                $inScope && Row::int($row, 'provider_id') > 0 && in_array(Row::int($row, 'pc_catid'), $categories, true),
                $inScope,
                self::date(Row::text($row, 'DOB')),
                self::unique($entry['codes']),
                Row::nullableInt($row, 'provider_id') ?? 0,
                Row::nullableInt($row, 'pc_catid') ?? 0,
            );
        }

        $births = [];
        foreach (array_chunk(array_keys($loose), 500) as $chunk) {
            $found = QueryUtils::fetchRecords(
                'SELECT pid, DOB FROM patient_data WHERE pid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk,
            );
            foreach ($found as $row) {
                $births[Row::int($row, 'pid')] = self::date(Row::text($row, 'DOB'));
            }
        }
        foreach ($loose as $pid => $days) {
            foreach ($days as $day => $codes) {
                $date = self::date($day);
                if ($date !== null) {
                    $out[] = new EncounterCodes($pid, 0, $date, false, true, $births[$pid] ?? null, self::unique($codes));
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, list<string>> $codes
     * @return array<string, list<string>>
     */
    private static function unique(array $codes): array
    {
        return array_map(static fn(array $list): array => array_values(array_unique($list)), $codes);
    }

    /**
     * The Table 6A code systems a billing row belongs to.
     *
     * @return list<string>
     */
    public static function systems(string $codeType, string $code): array
    {
        $systems = [];
        $type = strtoupper(trim($codeType));
        if (isset(self::SYSTEM_BY_TYPE[$type])) {
            $systems[] = self::SYSTEM_BY_TYPE[$type];
        }
        // A dental (CDT) code is a HCPCS Level II D-code; OpenEMR has no CDT type. ICD codes
        // starting with D (neoplasms, D00-D89) are never read as one.
        if (!str_starts_with($type, 'ICD') && preg_match('/^D\d{4}$/', Table6aCode::normalize($code)) === 1) {
            $systems[] = 'cdt';
        }
        return $systems;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $day = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $date === false || $date->format('Y-m-d') !== $day ? null : $date;
    }
}
