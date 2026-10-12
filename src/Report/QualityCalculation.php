<?php

/**
 * Calculates a year's eCQMs for Tables 6B and 7 in steps a request or a command
 * can take one at a time: start() fixes the patients and measures, each step()
 * calculates the next batch and saves it, and the run can stop and resume
 * anywhere.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Repository\QualityMeasureRepository;
use OpenEMR\Modules\UdsReport\Repository\QualityResultsRepository;

final class QualityCalculation
{
    public function __construct(
        private QualityMeasureRepository $engine,
        private QualityResultsRepository $store,
    ) {
    }

    /**
     * @param list<int> $pids the year's UDS patients
     * @param list<string> $measures the measures the manual names for the year
     */
    public function start(int $year, array $pids, array $measures, ?int $userId): void
    {
        // Ask the engine with no patients: it reports which measures aren't installed.
        $check = $this->engine->calculate($year, [], $measures);
        $present = array_values(array_diff($measures, $check['missing']));
        $this->store->start($year, array_values(array_unique($pids)), $present, $check['missing'], $userId);
    }

    /**
     * Calculates the next batch. Returns the run as it stands afterwards.
     *
     * @return array{done: int, total: int, finished: bool}
     */
    public function step(int $year, int $batch = QualityMeasureRepository::BATCH): array
    {
        $run = $this->store->run($year);
        if ($run === null || $run['status'] !== 'running') {
            throw new \RuntimeException("no calculation is running for $year");
        }
        $total = count($run['patients']);
        $next = array_slice($run['patients'], $run['done'], max(1, $batch));
        if ($next !== [] && $run['measures'] !== []) {
            try {
                $out = $this->engine->calculate($year, $next, $run['measures']);
            } catch (\Throwable $e) {
                $this->store->finish($year, $e->getMessage());
                throw $e;
            }
            $this->store->saveBatch($year, $out['results'], $run['done'] + count($next), $out['seconds']);
        } elseif ($next !== []) {
            $this->store->saveBatch($year, [], $run['done'] + count($next), 0.0);
        }
        $done = min($total, $run['done'] + count($next));
        if ($done >= $total) {
            $this->store->finish($year);
        }
        return ['done' => $done, 'total' => $total, 'finished' => $done >= $total];
    }
}
