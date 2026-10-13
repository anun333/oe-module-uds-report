<?php

/**
 * UDS Report: Tables 6B and 7 from OpenEMR's eCQM engine. Shows the stored
 * calculation for the year; an administrator can start or resume it here, in
 * batches the page requests one after another, or run bin/uds-quality.php on
 * the server for a large health center. Aggregate counts only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\QualityCalculation;
use OpenEMR\Modules\UdsReport\Report\QualityMeasures;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Repository\EcqmReadinessRepository;
use OpenEMR\Modules\UdsReport\Repository\QualityMeasureRepository;
use OpenEMR\Modules\UdsReport\Repository\QualityResultsRepository;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS Tables 6B and 7', xl('UDS Report'));
}
// Calculating writes the module's tables and is heavy: the same permission as the module's settings.
$canRun = AclMain::aclCheckCore('admin', 'super');
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop($canRun);
$request = Request::get();
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$now = ServiceContainer::getClock()->now();
$isPost = $request->getMethod() === 'POST';
$year = ReportYear::fromQuery($isPost ? $request->request->getString('year') : $request->query->getString('year'), $now);
$entries = $reference->ecqmMeasures->forYear($year);
$measureIds = array_values(array_unique(array_column($entries, 'measure')));
$store = new QualityResultsRepository();
$patientRepository = new UdsPatientRepository($reference->insuranceCategories);

// 0.7.0 adds two tables; a site that updated the files but not the database gets told how.
if (!QueryUtils::existsTable('uds_quality_run') || !QueryUtils::existsTable('uds_quality_result')) {
    $upgrade = xl('This version of the UDS Report module adds two tables. Run its database upgrade: Modules > Manage Modules, then Upgrade next to UDS Report.');
    if ($isPost) {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['error' => $upgrade]);
        exit;
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS Tables 6B and 7'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4 mb-3"><?php echo xlt('UDS Tables 6B and 7'); ?></h1>
    <div class="alert alert-warning"><?php echo text($upgrade); ?></div>
</div>
</body>
</html>
    <?php
    exit;
}

// ------------------------------------------------------------ start or continue (JSON)
if ($isPost) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!$canRun) {
        http_response_code(403);
        echo json_encode(['error' => xl('Only an administrator can calculate the measures.')]);
        exit;
    }
    if (!CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $sessionUserId = $session->get('authUserID');
    $userId = is_numeric($sessionUserId) ? (int)$sessionUserId : null;
    $calculation = new QualityCalculation(new QualityMeasureRepository(), $store);
    try {
        if ($request->request->getString('action') === 'start') {
            $pids = array_map(static fn($p): int => $p->pid, $patientRepository->forYear($year, (new SettingsRepository())->load()));
            $calculation->start($year, $pids, $measureIds, $userId);
            echo json_encode(['done' => 0, 'total' => count($pids), 'finished' => false]);
        } else {
            // Batches of 50 keep each request to a few seconds and well inside a 512 MB memory limit.
            echo json_encode($calculation->step($year, 50));
        }
    } catch (\Throwable $e) {
        ServiceContainer::getLogger()->error('UDS quality calculation failed', ['exception' => $e]);
        http_response_code(500);
        echo json_encode(['error' => xl('The calculation stopped with an error. The PHP error log has the details.')]);
    }
    exit;
}

// ------------------------------------------------------------ the tables
$run = $store->run($year);
$finished = $run !== null && $run['status'] === 'done';
$udsPids = $run['patients'] ?? [];

/** Table 6B lines, in the manual's order, with the population sets each reports. */
$lines6B = [];
foreach ($entries as $e) {
    if ($e['table'] !== '6B') {
        continue;
    }
    if ($e['measure'] === 'CMS137v14') {
        $lines6B[] = ['line' => '23a', 'title' => $e['title'] . ': ' . xl('initiation'), 'measure' => $e['measure'], 'sets' => QualityMeasures::LINE_SETS['23a'][1]];
        $lines6B[] = ['line' => '23b', 'title' => $e['title'] . ': ' . xl('engagement'), 'measure' => $e['measure'], 'sets' => QualityMeasures::LINE_SETS['23b'][1]];
        continue;
    }
    $sets = (isset(QualityMeasures::LINE_SETS[$e['line']]) && QualityMeasures::LINE_SETS[$e['line']][0] === $e['measure'])
        ? QualityMeasures::LINE_SETS[$e['line']][1] : ['PopulationSet_1'];
    $lines6B[] = ['line' => $e['line'], 'title' => $e['title'], 'measure' => $e['measure'], 'sets' => $sets];
}
$byMeasure = [];
$measuresFor = static function (string $measure) use (&$byMeasure, $store, $year, $udsPids): QualityMeasures {
    return $byMeasure[$measure] ??= new QualityMeasures($store->rows($year, $measure), $udsPids);
};
$cells6B = [];
foreach ($lines6B as $i => $l) {
    $cells6B[$i] = $finished && !in_array($l['measure'], $run['missing'], true) ? $measuresFor($l['measure'])->line($l['sets']) : null;
}

/** Table 7 sections B and C, grouped by each patient's Table 3B race and ethnicity cell. */
$sections7 = [];
if ($finished) {
    $placement = [];
    foreach ($patientRepository->forYear($year, (new SettingsRepository())->load()) as $p) {
        $placement[$p->pid] = $reference->raceEthnicity->place($p->raceCodes, $p->ethnicityCodes);
    }
    foreach ($entries as $e) {
        if ($e['table'] === '7' && !in_array($e['measure'], $run['missing'], true)) {
            $sections7[$e['measure']] = ['entry' => $e, 'cells' => $measuresFor($e['measure'])->table7($placement)];
        }
    }
}
$raceLabels = Labels::raceLines();
$ethnicityLabels = Labels::ethnicityColumns();
$label7 = static function (string $line) use ($raceLabels, $ethnicityLabels): string {
    $cell = QualityMeasures::table7Cell($line);
    if ($cell === null) {
        return $line;
    }
    if ($cell['column'] === 'C') {
        return xl('Unreported/Chose not to disclose race and ethnicity');
    }
    return $raceLabels[$cell['line']] . ', ' . $ethnicityLabels[$cell['column']];
};
$rate = static fn(array $c): string => $c['B'] > 0 ? number_format(100 * $c['C'] / $c['B'], 1) . '%' : '';
$noEcqm = [
    '1-9' => xl('Prenatal care: age of patients and trimester of entry'),
    '20' => xl('HIV linkage to care'),
    '22a, 22b' => xl('Dental sealants for children (SFM-CH-A)'),
];

// ------------------------------------------------------------ CSV
if ($request->query->getString('format') === 'csv') {
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Cannot open the output stream');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uds-' . $year . '-tables-6b-7.csv"');
    header('Cache-Control: no-store');
    CsvRow::write($out, ['UDS Tables 6B and 7', (string)$year, $finished ? 'calculated ' . ($run['finishedAt'] ?? '') : 'not calculated']);
    CsvRow::write($out, []);
    CsvRow::write($out, ['Table 6B', 'Line', 'Measure', 'eCQM', 'Patients in the denominator (A)', 'Records reviewed (B)', 'Patients meeting the measure (C)']);
    foreach ($lines6B as $i => $l) {
        $c = $cells6B[$i];
        CsvRow::write($out, ['', $l['line'], $l['title'], $l['measure'], ...($c === null ? ['', '', ''] : [$c['A'], $c['B'], $c['C']])]);
    }
    foreach ($sections7 as $measure => $s) {
        CsvRow::write($out, []);
        $numerator = $measure === 'CMS122v14' ? '3F' : '2C';
        CsvRow::write($out, ['Table 7', 'Line', $s['entry']['title'] . ' (' . $measure . ')', 'Denominator (' . ($measure === 'CMS122v14' ? '3A' : '2A') . ')', 'Records reviewed (' . ($measure === 'CMS122v14' ? '3B' : '2B') . ')', "Numerator ($numerator)"]);
        foreach ($s['cells'] as $line => $c) {
            CsvRow::write($out, ['', (string)$line, $label7((string)$line), $c['A'], $c['B'], $c['C']]);
        }
    }
    exit;
}

$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
$csrf = CsrfUtils::collectCsrfToken(session: $session);
$n = static fn(int $v): string => number_format($v);
$engine = EcqmReadinessRepository::phpEngine();
$openEnds = EcqmReadinessRepository::openEndsCount();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS Tables 6B and 7'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-section { margin-top: 2rem; }
        .uds-subtotal td { font-weight: 600; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS Tables 6B and 7'); ?>: <?php echo text((string)$year); ?> <small class="text-muted"><?php echo xlt('Quality of care and health outcomes, from the eCQM engine'); ?></small></h1>
        <form method="get" class="form-inline mb-2">
            <label class="mr-2" for="uds-year"><?php echo xlt('Calendar year'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-year" name="year" onchange="this.form.submit()">
                <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                    <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/index.php?year=' . $year); ?>"><?php echo xlt('UDS Report'); ?></a>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/ecqm.php?year=' . $year); ?>"><?php echo xlt('eCQM readiness'); ?></a>
            <?php if ($finished) { ?>
                <a class="btn btn-sm btn-outline-primary" href="<?php echo attr($moduleUrl . '/public/quality.php?format=csv&year=' . $year); ?>"><?php echo xlt('Download CSV'); ?></a>
            <?php } ?>
        </form>
    </div>

    <?php if ($measureIds === []) { ?>
        <div class="alert alert-info"><?php echo xlt('The module has no measure list for this year. Tables 6B and 7 are calculated from 2026 on.'); ?></div>
    <?php } elseif (!$engine) { ?>
        <div class="alert alert-warning"><?php echo xlt('This OpenEMR has no PHP eCQM engine, which these tables need (OpenEMR after 8.4). The eCQM readiness page lists what else to check.'); ?></div>
    <?php } else { ?>
        <div class="card mb-3"><div class="card-body py-2">
            <?php if ($run === null) { ?>
                <p class="mb-2"><?php echo xlt('Not calculated yet for this year.'); ?></p>
            <?php } elseif ($run['status'] === 'running') { ?>
                <p class="mb-2"><?php echo xlt('Calculation stopped part-way'); ?>: <?php echo text($n($run['done'])); ?> <?php echo xlt('of'); ?> <?php echo text($n(count($run['patients']))); ?> <?php echo xlt('patients'); ?>.</p>
            <?php } elseif ($run['status'] === 'failed') { ?>
                <p class="mb-2 text-danger"><?php echo xlt('The last calculation stopped with an error'); ?>: <?php echo text((string)$run['error']); ?></p>
            <?php } else { ?>
                <p class="mb-2"><?php echo xlt('Calculated'); ?> <?php echo text((string)$run['finishedAt']); ?> <?php echo xlt('for'); ?> <?php echo text($n(count($run['patients']))); ?> <?php echo xlt('UDS patients'); ?> (<?php echo text(number_format($run['seconds'], 0)); ?> <?php echo xlt('seconds'); ?>). <?php echo xlt('Records changed since then are not reflected until it is calculated again.'); ?></p>
            <?php } ?>
            <?php if (($run['missing'] ?? []) !== []) { ?>
                <p class="mb-2 text-warning"><?php echo xlt('Not installed at the version the manual names, so not calculated'); ?>: <?php echo text(implode(', ', $run['missing'])); ?></p>
            <?php } ?>
            <?php if ($openEnds['problems'] === false || $openEnds['visits'] === false) { ?>
                <p class="mb-2 text-warning"><?php echo xlt('This OpenEMR does not count ongoing problems or visits without an end time toward the measures (openemr/openemr#14526), so many results will be too low. The eCQM readiness page shows how many records it affects.'); ?></p>
            <?php } ?>
            <?php if ($canRun) { ?>
                <button type="button" class="btn btn-sm btn-primary" id="uds-calc" data-action="<?php echo attr($run !== null && $run['status'] === 'running' ? 'step' : 'start'); ?>">
                    <?php echo $run === null ? xlt('Calculate') : ($run['status'] === 'running' ? xlt('Resume') : xlt('Calculate again')); ?>
                </button>
                <span class="ml-2 small text-muted" id="uds-calc-status"></span>
                <p class="small text-muted mt-2 mb-0"><?php echo xlt('Keep this page open while it runs; if it closes, Resume continues where it stopped. For a large health center, run bin/uds-quality.php from the module folder on the server instead.'); ?></p>
            <?php } ?>
        </div></div>
    <?php } ?>

    <div class="uds-section">
        <h2 class="h5"><?php echo xlt('Table 6B: Quality of Care Measures'); ?></h2>
        <table class="table table-sm table-striped uds-table">
            <thead><tr>
                <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Measure'); ?></th><th><?php echo xlt('eCQM'); ?></th>
                <th class="num"><?php echo xlt('Denominator (A)'); ?></th><th class="num"><?php echo xlt('Records reviewed (B)'); ?></th>
                <th class="num"><?php echo xlt('Meeting the measure (C)'); ?></th><th class="num"><?php echo xlt('Rate'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lines6B as $i => $l) { $c = $cells6B[$i]; ?>
                <tr>
                    <td><?php echo text($l['line']); ?></td><td><?php echo text($l['title']); ?></td><td><?php echo text($l['measure']); ?></td>
                    <?php if ($c === null) { ?>
                        <td class="num text-muted" colspan="4"><?php echo in_array($l['measure'], $run['missing'] ?? [], true) ? xlt('not calculated: measure not installed') : xlt('not calculated'); ?></td>
                    <?php } else { ?>
                        <td class="num"><?php echo text($n($c['A'])); ?></td><td class="num"><?php echo text($n($c['B'])); ?></td>
                        <td class="num"><?php echo text($n($c['C'])); ?></td><td class="num"><?php echo text($rate($c)); ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <p class="small text-muted"><?php echo xlt('These lines have no eCQM and are not calculated here; report them from your records'); ?>:
            <?php echo text(implode('; ', array_map(static fn(string $line, string $label): string => xl('Line') . ' ' . $line . ', ' . $label, array_keys($noEcqm), $noEcqm))); ?>.</p>
    </div>

    <?php foreach ($sections7 as $measure => $s) { $isDiabetes = $measure === 'CMS122v14'; ?>
        <div class="uds-section">
            <h2 class="h5"><?php echo xlt('Table 7'); ?>: <?php echo text($s['entry']['title']); ?> <small class="text-muted"><?php echo text($measure); ?></small></h2>
            <table class="table table-sm uds-table">
                <thead><tr>
                    <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Race and ethnicity'); ?></th>
                    <th class="num"><?php echo text($isDiabetes ? '3A' : '2A'); ?> <?php echo xlt('Denominator'); ?></th>
                    <th class="num"><?php echo text($isDiabetes ? '3B' : '2B'); ?> <?php echo xlt('Records reviewed'); ?></th>
                    <th class="num"><?php echo text($isDiabetes ? '3F' : '2C'); ?> <?php echo $isDiabetes ? xlt('Glycemic status above 9% or not tested') : xlt('Blood pressure under control'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($s['cells'] as $line => $c) {
                    $sub = in_array($line, ['subtotal_hispanic', 'subtotal_not_hispanic', 'total'], true);
                    $label = match ((string)$line) {
                        'subtotal_hispanic' => xl('Subtotal Hispanic, Latino/a, or Spanish origin'),
                        'subtotal_not_hispanic' => xl('Subtotal not Hispanic, Latino/a, or Spanish origin'),
                        'total' => xl('Total'),
                        default => $label7((string)$line),
                    }; ?>
                    <tr class="<?php echo $sub ? 'uds-subtotal' : ''; ?>">
                        <td><?php echo $sub ? '' : text((string)$line); ?></td><td><?php echo text($label); ?></td>
                        <td class="num"><?php echo text($n($c['A'])); ?></td><td class="num"><?php echo text($n($c['B'])); ?></td><td class="num"><?php echo text($n($c['C'])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <?php if ($finished) { ?>
        <p class="small text-muted"><?php echo xlt('Table 7 lists only the race and ethnicity lines that have patients. Section A (deliveries and birth weight) has no eCQM and is not calculated here.'); ?></p>
    <?php } ?>
</div>
<?php if ($canRun) { ?>
<script>
(function () {
    const button = document.getElementById('uds-calc');
    if (!button) {
        return;
    }
    const status = document.getElementById('uds-calc-status');
    const year = <?php echo js_escape((string)$year); ?>;
    const csrf = <?php echo js_escape($csrf); ?>;
    async function post(action) {
        if (typeof top.restoreSession === 'function') {
            top.restoreSession();
        }
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams({action: action, year: year, csrf_token_form: csrf})
        });
        const data = await response.json();
        if (!response.ok || data.error) {
            throw new Error(data.error || response.statusText);
        }
        return data;
    }
    button.addEventListener('click', async function () {
        button.disabled = true;
        try {
            let at = button.dataset.action === 'start' ? await post('start') : {finished: false};
            while (!at.finished) {
                at = await post('step');
                status.textContent = at.done + ' ' + <?php echo xlj('of'); ?> + ' ' + at.total + ' ' + <?php echo xlj('patients'); ?>;
            }
            window.location.reload();
        } catch (e) {
            status.textContent = e.message;
            button.disabled = false;
            button.dataset.action = 'step';
        }
    });
})();
</script>
<?php } ?>
</body>
</html>
