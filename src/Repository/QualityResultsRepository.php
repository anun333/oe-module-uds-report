<?php

/**
 * Stores the eCQM calculation for Tables 6B and 7: one run per year (the
 * patients being calculated, how far it has got, the measures missing) and
 * each patient's population counts. A batch's rows and its progress are saved
 * in one transaction, so a run that stops part-way resumes without counting a
 * patient twice.
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

final class QualityResultsRepository
{
    private const POPULATIONS = ['IPP' => 'ipp', 'DENOM' => 'denom', 'DENEX' => 'denex', 'DENEXCEP' => 'denexcep', 'NUMER' => 'numer', 'NUMEX' => 'numex'];

    /**
     * Starts a year again: its earlier results are removed.
     *
     * @param list<int> $pids
     * @param list<string> $measures
     * @param list<string> $missing
     */
    public function start(int $year, array $pids, array $measures, array $missing, ?int $userId): void
    {
        QueryUtils::startTransaction();
        try {
            QueryUtils::sqlStatementThrowException('DELETE FROM `uds_quality_result` WHERE `year` = ?', [$year]);
            QueryUtils::sqlStatementThrowException(
                'REPLACE INTO `uds_quality_run` (`year`, `status`, `patients`, `done`, `measures`, `missing`, `seconds`, `error`, `refused`, `started_by`, `started_at`, `finished_at`)'
                . " VALUES (?, 'running', ?, 0, ?, ?, 0, NULL, '[]', ?, NOW(), NULL)",
                [$year, json_encode($pids, JSON_THROW_ON_ERROR), json_encode($measures, JSON_THROW_ON_ERROR), json_encode($missing, JSON_THROW_ON_ERROR), $userId],
            );
            QueryUtils::commitTransaction();
        } catch (\Throwable $e) {
            QueryUtils::rollbackTransaction();
            throw $e;
        }
    }

    /**
     * @return array{year: int, status: string, patients: list<int>, done: int, measures: list<string>, missing: list<string>, seconds: float, error: ?string, refused: list<array{pid: int, reason: string}>, startedAt: string, finishedAt: ?string}|null
     */
    public function run(int $year): ?array
    {
        $rows = QueryUtils::fetchRecords('SELECT * FROM `uds_quality_run` WHERE `year` = ?', [$year]);
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];
        $list = static function (mixed $json): array {
            $v = json_decode(is_string($json) ? $json : '[]', true);
            return is_array($v) ? array_values($v) : [];
        };
        return [
            'year' => (int)$r['year'],
            'status' => (string)$r['status'],
            'patients' => array_map('intval', $list($r['patients'])),
            'done' => (int)$r['done'],
            'measures' => array_map('strval', $list($r['measures'])),
            'missing' => array_map('strval', $list($r['missing'])),
            'seconds' => (float)$r['seconds'],
            'error' => is_string($r['error'] ?? null) ? $r['error'] : null,
            'refused' => self::refusedList($r['refused'] ?? null),
            'startedAt' => (string)$r['started_at'],
            'finishedAt' => is_string($r['finished_at'] ?? null) ? $r['finished_at'] : null,
        ];
    }

    /**
     * Saves one batch's results and the patients the engine refused in it, and
     * moves the run on to $done patients.
     *
     * @param array<string, list<array<string, int|string>>> $byMeasure measure => rows (pid, set, populations)
     * @param list<array{pid: int, reason: string}> $refused
     */
    public function saveBatch(int $year, array $byMeasure, int $done, float $seconds, array $refused = []): void
    {
        QueryUtils::startTransaction();
        try {
            if ($refused !== []) {
                $stored = QueryUtils::fetchRecords('SELECT `refused` FROM `uds_quality_run` WHERE `year` = ? FOR UPDATE', [$year]);
                QueryUtils::sqlStatementThrowException(
                    'UPDATE `uds_quality_run` SET `refused` = ? WHERE `year` = ?',
                    [json_encode(array_merge(self::refusedList($stored[0]['refused'] ?? null), $refused), JSON_THROW_ON_ERROR), $year],
                );
            }
            foreach ($byMeasure as $measure => $rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    $values = [];
                    $binds = [];
                    foreach ($chunk as $row) {
                        $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        array_push($binds, $year, $measure, (string)$row['set'], (int)$row['pid']);
                        foreach (array_keys(self::POPULATIONS) as $population) {
                            $binds[] = (int)($row[$population] ?? 0);
                        }
                    }
                    QueryUtils::sqlStatementThrowException(
                        'REPLACE INTO `uds_quality_result` (`year`, `measure`, `population_set`, `pid`, `ipp`, `denom`, `denex`, `denexcep`, `numer`, `numex`) VALUES '
                        . implode(', ', $values),
                        $binds,
                    );
                }
            }
            QueryUtils::sqlStatementThrowException(
                'UPDATE `uds_quality_run` SET `done` = ?, `seconds` = `seconds` + ? WHERE `year` = ?',
                [$done, $seconds, $year],
            );
            QueryUtils::commitTransaction();
        } catch (\Throwable $e) {
            QueryUtils::rollbackTransaction();
            throw $e;
        }
    }

    public function finish(int $year, ?string $error = null): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `uds_quality_run` SET `status` = ?, `error` = ?, `finished_at` = NOW() WHERE `year` = ?',
            [$error === null ? 'done' : 'failed', $error, $year],
        );
    }

    /**
     * One measure's stored results, in the shape QualityMeasures reads.
     *
     * @return list<array<string, int|string>>
     */
    public function rows(int $year, string $measure): array
    {
        $out = [];
        foreach (
            QueryUtils::fetchRecords(
                'SELECT `pid`, `population_set`, `ipp`, `denom`, `denex`, `denexcep`, `numer`, `numex` FROM `uds_quality_result` WHERE `year` = ? AND `measure` = ?',
                [$year, $measure],
            ) as $r
        ) {
            $row = ['pid' => (int)$r['pid'], 'set' => (string)$r['population_set']];
            foreach (self::POPULATIONS as $population => $column) {
                $row[$population] = (int)$r[$column];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @return list<array{pid: int, reason: string}>
     */
    private static function refusedList(mixed $json): array
    {
        $v = json_decode(is_string($json) ? $json : '[]', true);
        $out = [];
        foreach (is_array($v) ? $v : [] as $r) {
            if (is_array($r) && is_numeric($r['pid'] ?? null) && is_string($r['reason'] ?? null)) {
                $out[] = ['pid' => (int)$r['pid'], 'reason' => $r['reason']];
            }
        }
        return $out;
    }
}
