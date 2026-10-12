<?php

/**
 * Calculates a year's eCQMs for UDS Tables 6B and 7 from the command line, as
 * the web server's user, so a large health center isn't limited by a request's
 * time. It resumes a run that stopped part-way.
 *
 *   HTTP_HOST=localhost php interface/modules/custom_modules/oe-module-uds-report/bin/uds-quality.php \
 *       --year=2026 [--site=default] [--batch=200] [--restart] [--steps=N]
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opts = getopt('', ['year:', 'site:', 'batch:', 'restart', 'steps:']);
$year = (int)($opts['year'] ?? 0);
if ($year < 2026) {
    fwrite(STDERR, "usage: php uds-quality.php --year=2026 [--site=default] [--batch=200] [--restart] [--steps=N]\n");
    exit(2);
}
$ignoreAuth = true;
$sessionAllowWrite = true;
$_GET['site'] = is_string($opts['site'] ?? null) ? $opts['site'] : 'default';
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\QualityCalculation;
use OpenEMR\Modules\UdsReport\Repository\QualityMeasureRepository;
use OpenEMR\Modules\UdsReport\Repository\QualityResultsRepository;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

$reference = (new ReferenceDataLoader())->load();
$measures = array_values(array_unique(array_column($reference->ecqmMeasures->forYear($year), 'measure')));
if ($measures === []) {
    fwrite(STDERR, "the module has no measure list for $year\n");
    exit(1);
}
$store = new QualityResultsRepository();
$calculation = new QualityCalculation(new QualityMeasureRepository(), $store);
$run = $store->run($year);
try {
    if ($run === null || $run['status'] !== 'running' || isset($opts['restart'])) {
        $settings = (new SettingsRepository())->load();
        $pids = array_map(static fn($p): int => $p->pid, (new UdsPatientRepository($reference->insuranceCategories))->forYear($year, $settings));
        $calculation->start($year, $pids, $measures, null);
        $run = $store->run($year);
        echo "started $year: " . count($pids) . ' UDS patients, ' . count($run['measures'] ?? []) . ' measures'
            . (($run['missing'] ?? []) !== [] ? '; not installed: ' . implode(', ', $run['missing']) : '') . "\n";
    } else {
        echo "resuming $year at {$run['done']} of " . count($run['patients']) . " patients\n";
    }
    $batch = max(1, (int)($opts['batch'] ?? QualityMeasureRepository::BATCH));
    $limit = isset($opts['steps']) ? max(1, (int)$opts['steps']) : PHP_INT_MAX;
    for ($i = 0; $i < $limit; $i++) {
        $at = $calculation->step($year, $batch);
        echo "  {$at['done']} of {$at['total']} patients\n";
        if ($at['finished']) {
            $run = $store->run($year);
            echo "done in " . round($run['seconds'] ?? 0, 1) . " seconds of calculation\n";
            break;
        }
    }
} catch (\Throwable $e) {
    ServiceContainer::getLogger()->error('UDS quality calculation failed', ['exception' => $e]);
    fwrite(STDERR, 'failed: ' . $e->getMessage() . "\n");
    exit(1);
}
