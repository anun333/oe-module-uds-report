<?php

/**
 * Keeps a calculation going when OpenEMR's eCQM engine refuses some patients.
 *
 * The engine calculates a batch of patients in one call and gives up on the
 * whole call when it can't read one patient's record: for example a lab result
 * whose unit isn't UCUM ("Invalid UCUM unit: 'mEq/L'"), which it refuses for
 * every measure. Tried again one patient at a time, everyone else is calculated
 * and the patients it refuses are kept with its reason, so the report can say
 * who is left out instead of failing.
 *
 * Only refusals are retried. Any other error (the database, a missing measure)
 * is thrown on, so a real failure can't pass for refused patients.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class RefusedPatients
{
    /** OpenEMR's exception for a measure the engine couldn't calculate (master after 8.4). */
    public const ENGINE_REFUSAL = 'OpenEMR\\Services\\Qdm\\MeasureCalculationException';

    /**
     * @param list<int> $pids
     * @param callable(list<int>): array<string, list<array<string, int|string>>> $calculate one engine call for these patients
     * @param callable(\Throwable): bool $isRefusal
     * @return array{results: array<string, list<array<string, int|string>>>, refused: list<array{pid: int, reason: string}>}
     */
    public static function calculate(array $pids, callable $calculate, callable $isRefusal): array
    {
        try {
            return ['results' => $calculate($pids), 'refused' => []];
        } catch (\Throwable $e) {
            if (!$isRefusal($e)) {
                throw $e;
            }
            if (count($pids) === 1) {
                return ['results' => [], 'refused' => [['pid' => $pids[0], 'reason' => self::reason($e)]]];
            }
        }
        $results = [];
        $refused = [];
        foreach ($pids as $pid) {
            $one = self::calculate([$pid], $calculate, $isRefusal);
            foreach ($one['results'] as $measure => $rows) {
                $results[$measure] = array_merge($results[$measure] ?? [], $rows);
            }
            array_push($refused, ...$one['refused']);
        }
        return ['results' => $results, 'refused' => $refused];
    }

    public static function isEngineRefusal(\Throwable $e): bool
    {
        return is_a($e, self::ENGINE_REFUSAL);
    }

    /**
     * The engine's own words: the innermost cause, which names the record's
     * problem ("Invalid UCUM unit: 'UNK'."), not the wrapper's "Measure ...
     * cannot be calculated".
     */
    public static function reason(\Throwable $e): string
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }
        $message = trim($e->getMessage());
        return mb_substr($message !== '' ? $message : $e::class, 0, 200);
    }

    /**
     * @param list<array{pid: int, reason: string}> $refused
     * @return array<string, list<int>> reason => patients, the most common reason first
     */
    public static function byReason(array $refused): array
    {
        $out = [];
        foreach ($refused as $r) {
            $out[$r['reason']][$r['pid']] = $r['pid'];
        }
        $out = array_map(static fn(array $pids): array => array_values($pids), $out);
        uasort($out, static fn(array $a, array $b): int => count($b) <=> count($a));
        return $out;
    }
}
