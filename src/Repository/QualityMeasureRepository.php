<?php

/**
 * Runs OpenEMR's eCQM engine for the UDS patients and measures of a year, and
 * returns per-patient results in the shape QualityMeasures reads. It makes the
 * same calls as OpenEMR's own openemr:cqm-calculate command, so the two can be
 * compared.
 *
 * Needs the PHP engine (openemr/openemr#14469). The measures are read from the
 * installed oe-cqm-parsers folder for the year; a measure that isn't installed
 * at the version the manual names is reported by name, not guessed at.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\Qdm\CqmCalculator;
use OpenEMR\Services\Qdm\IndividualResult;
use OpenEMR\Services\Qdm\QdmBuilder;
use OpenEMR\Services\Qdm\QdmRequestSome;
use OpenEMR\Services\Qrda\ExportCat3Service;

final class QualityMeasureRepository
{
    /** Patients per engine call: the engine builds every patient's QDM record in memory. */
    public const BATCH = 200;

    /**
     * @param list<int> $pids the UDS patients (a countable visit in the year)
     * @param list<string> $measures measure folders, such as CMS165v14
     * @return array{results: array<string, list<array<string, int|string>>>, missing: list<string>, seconds: float}
     */
    public function calculate(int $year, array $pids, array $measures): array
    {
        if (!EcqmReadinessRepository::phpEngine()) {
            throw new \RuntimeException('this OpenEMR has no PHP eCQM engine (openemr/openemr#14469)');
        }
        $dir = self::measureDir($year);
        $present = [];
        $missing = [];
        foreach ($measures as $measure) {
            if ($dir !== null && is_file($dir . DIRECTORY_SEPARATOR . $measure . DIRECTORY_SEPARATOR . $measure . '.json')) {
                $present[$measure] = $dir . DIRECTORY_SEPARATOR . $measure;
            } else {
                $missing[] = $measure;
            }
        }
        $results = array_fill_keys(array_keys($present), []);
        $started = microtime(true);
        if ($present !== [] && $pids !== []) {
            $globals = OEGlobalsBag::getInstance();
            $previous = $globals->get('cqm_performance_period');
            $globals->set('cqm_performance_period', (string)$year);
            try {
                foreach (array_chunk($pids, self::BATCH) as $batch) {
                    $export = new ExportCat3Service(new QdmBuilder(), new CqmCalculator(), new QdmRequestSome(array_map('strval', $batch)));
                    foreach (self::rows($export->export(array_values($present), true)) as $measure => $rows) {
                        $results[$measure] = array_merge($results[$measure] ?? [], $rows);
                    }
                }
            } finally {
                $globals->set('cqm_performance_period', $previous);
            }
        }
        return ['results' => $results, 'missing' => $missing, 'seconds' => round(microtime(true) - $started, 2)];
    }

    /**
     * The engine's results as rows: pid, set, and each population count.
     *
     * @return array<string, list<array<string, int|string>>>
     */
    public static function rows(mixed $exported): array
    {
        $out = [];
        foreach (is_array($exported) ? $exported : [] as $hqmfId => $individual) {
            foreach (is_array($individual) ? $individual : [] as $result) {
                if (!$result instanceof IndividualResult) {
                    continue;
                }
                $measure = is_object($result->measure) && isset($result->measure->cms_id) && is_string($result->measure->cms_id)
                    ? $result->measure->cms_id : (string)$hqmfId;
                $pid = $result->patient_id->value ?? null;
                if (!is_numeric($pid) || !is_string($result->population_set_key)) {
                    continue;
                }
                $row = ['pid' => (int)$pid, 'set' => $result->population_set_key];
                foreach (['IPP', 'DENOM', 'DENEX', 'DENEXCEP', 'NUMER', 'NUMEX'] as $population) {
                    $row[$population] = is_int($result->{$population}) ? $result->{$population} : 0;
                }
                $out[$measure][] = $row;
            }
        }
        return $out;
    }

    private static function measureDir(int $year): ?string
    {
        if (!class_exists(\OpenEMR\Services\Qdm\MeasureService::class) || !method_exists(\OpenEMR\Services\Qdm\MeasureService::class, 'fetchMeasureSourceOptions')) {
            return null;
        }
        $globals = OEGlobalsBag::getInstance();
        $previous = $globals->get('cqm_performance_period');
        $globals->set('cqm_performance_period', (string)$year);
        try {
            $options = \OpenEMR\Services\Qdm\MeasureService::fetchMeasureSourceOptions();
        } finally {
            $globals->set('cqm_performance_period', $previous);
        }
        $relative = is_array($options) ? ($options['openemr/oe-cqm-parsers'] ?? null) : null;
        $dir = is_string($relative) ? $globals->getProjectDir() . $relative : null;
        return $dir !== null && is_dir($dir) ? $dir : null;
    }
}
