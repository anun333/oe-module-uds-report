<?php

/**
 * The year's countable visits for Table 5, each with its procedure codes,
 * modifiers and first diagnosis from the fee sheet (the billing table's active
 * rows, in the order they were entered), and the providers who saw them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\Model\Provider;
use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final readonly class Table5VisitRepository
{
    /** OpenEMR billing code types by the code systems Table 5 reads. */
    private const PROCEDURE_SYSTEMS = ['CPT4' => 'cpt', 'HCPCS' => 'hcpcs'];

    public function __construct(private UdsPatientRepository $patients)
    {
    }

    /** @return list<Table5Visit> */
    public function forYear(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return [];
        }
        [$where, $binds] = $filter->where($year);
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.pid, fe.encounter, fe.date, fe.facility_id, fe.provider_id, fe.pc_catid, fe.pos_code, fe.class_code,
                    b.code_type, b.code, b.modifier
               FROM form_encounter AS fe
               JOIN patient_data AS pd ON pd.pid = fe.pid
          LEFT JOIN billing AS b ON b.pid = fe.pid AND b.encounter = fe.encounter AND b.activity = 1
              WHERE $where
              ORDER BY fe.pid, fe.encounter, b.id",
            $binds,
        );

        $byEncounter = [];
        foreach ($rows as $row) {
            $key = Row::int($row, 'pid') . ':' . Row::int($row, 'encounter');
            $byEncounter[$key] ??= ['row' => $row, 'codes' => [], 'modifiers' => [], 'diagnosis' => null];
            $type = strtoupper(trim(Row::nullableText($row, 'code_type') ?? ''));
            $code = Table6aCode::normalize(Row::nullableText($row, 'code') ?? '');
            if ($code === '') {
                continue;
            }
            if ($type === 'ICD10') {
                $byEncounter[$key]['diagnosis'] ??= $code;
            } elseif (isset(self::PROCEDURE_SYSTEMS[$type])) {
                $byEncounter[$key]['codes'][self::PROCEDURE_SYSTEMS[$type]][] = $code;
                foreach (self::modifiers(Row::nullableText($row, 'modifier') ?? '') as $modifier) {
                    $byEncounter[$key]['modifiers'][$modifier] = true;
                }
            }
        }

        $visits = [];
        foreach ($byEncounter as $entry) {
            $row = $entry['row'];
            $day = self::day(Row::text($row, 'date'));
            if ($day === null) {
                continue;
            }
            $pos = Row::nullableInt($row, 'pos_code');
            $visits[] = new Table5Visit(
                Row::int($row, 'pid'),
                Row::int($row, 'encounter'),
                $day,
                Row::nullableInt($row, 'facility_id') ?? 0,
                Row::int($row, 'provider_id'),
                Row::int($row, 'pc_catid'),
                $pos === null ? null : sprintf('%02d', $pos),
                strtoupper(trim(Row::nullableText($row, 'class_code') ?? '')),
                array_map(static fn(array $codes): array => array_values(array_unique($codes)), $entry['codes']),
                array_keys($entry['modifiers']),
                $entry['diagnosis'],
            );
        }
        return $visits;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Provider>
     */
    public function providers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = QueryUtils::fetchRecords(
            'SELECT `id`, `fname`, `lname`, `taxonomy` FROM `users` WHERE `id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
        $providers = [];
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $name = trim((Row::nullableText($row, 'lname') ?? '') . ', ' . (Row::nullableText($row, 'fname') ?? ''), ', ');
            $providers[$id] = new Provider($id, $name === '' ? "#$id" : $name, trim(Row::nullableText($row, 'taxonomy') ?? ''));
        }
        return $providers;
    }

    /**
     * Modifiers as the fee sheet stores them: up to four, separated by colons or spaces.
     *
     * @return list<string>
     */
    public static function modifiers(string $value): array
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', strtoupper($value)) ?: [];
        return array_values(array_filter($parts, static fn(string $m): bool => strlen($m) === 2));
    }

    private static function day(string $value): ?string
    {
        $day = substr($value, 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $date === false || $date->format('Y-m-d') !== $day ? null : $day;
    }
}
