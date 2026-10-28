<?php

/**
 * UDS Report: HRSA's edit checks on this year's figures, and the cells that changed
 * most from last year's report as HRSA published it.
 *
 * Shows counts only, never patients, like the report itself.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../floor-guard.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Page;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\HrsaChecks;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\PriorYearComparison;
use OpenEMR\Modules\UdsReport\Report\PublishedUds;
use OpenEMR\Modules\UdsReport\Report\QualityMeasures;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\UdsFigures;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\QualityResultsRepository;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS HRSA checks', xl('UDS Report'));
}
$isAdmin = AclMain::aclCheckCore('admin', 'super');
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop($isAdmin);

$request = Request::get();
$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery($request->query->getString('year'), $now);
$grant = GrantReport::tryFrom($request->query->getString('grant'));
$query = 'year=' . $year . ($grant === null ? '' : '&grant=' . $grant->value);
$reportKey = HrsaChecks::reportKey($grant);
$settings = (new SettingsRepository())->load();
$report = new UdsReportService($year, $settings, $reference, new UdsPatientRepository($reference->insuranceCategories), $grant);
$tables = $report->tables();

// Tables 6B and 7 come from the stored eCQM calculation, once it has finished for the year.
// The Grant Reports have neither table.
$t6b = [];
$t7 = [];
$qualityDone = false;
if ($grant === null && QueryUtils::existsTable('uds_quality_run') && QueryUtils::existsTable('uds_quality_result')) {
    $store = new QualityResultsRepository();
    $run = $store->run($year);
    if ($run !== null && $run['status'] === 'done') {
        $qualityDone = true;
        $udsPids = $run['patients'];
        $byMeasure = [];
        $measuresFor = static function (string $measure) use (&$byMeasure, $store, $year, $udsPids): QualityMeasures {
            return $byMeasure[$measure] ??= new QualityMeasures($store->rows($year, $measure), $udsPids);
        };
        $entries = $reference->ecqmMeasures->forYear($year);
        foreach (QualityMeasures::lines6B($entries) as $l) {
            if (!in_array($l['measure'], $run['missing'], true)) {
                $t6b[$l['line']] = $measuresFor($l['measure'])->line($l['sets']);
            }
        }
        $placement = [];
        foreach ($report->patients() as $p) {
            $placement[$p->pid] = $reference->raceEthnicity->place($p->raceCodes, $p->ethnicityCodes);
        }
        foreach ($entries as $e) {
            if ($e['table'] === '7' && !in_array($e['measure'], $run['missing'], true)) {
                $t7[$e['measure']] = $measuresFor($e['measure'])->table7($placement)['total'];
            }
        }
    }
}

$figures = UdsFigures::from(
    $tables->table3A(),
    $tables->table3B(),
    $tables->table4(),
    Labels::raceLines(),
    array_replace(Labels::table4Income(), Labels::table4Insurance(), Labels::table4Special()),
    $report->table5()->result()['rows'],
    $report->table6A()->rows(),
    $t6b,
    $t7,
);

// Last year's report as HRSA published it, when the module has that year and the grant number is set.
$publishedFiles = PublishedUds::files();
$publishedYear = array_key_first($publishedFiles);
$published = null;
if ($settings->hrsaGrantNumber !== null && $publishedYear === $year - 1) {
    $published = PublishedUds::find($publishedFiles[$publishedYear], $settings->hrsaGrantNumber, $reportKey);
}
$checks = HrsaChecks::load();
$result = $checks->run($reportKey, $year, $figures->values, $published?->values, $figures->partial);
$comparison = $published === null ? null : PriorYearComparison::compare($figures->values, $published->values);

$reasons = [
    HrsaChecks::NOT_COMPUTED => xl('need a figure the module does not compute'),
    HrsaChecks::NO_PRIOR_YEAR => xl('need last year as HRSA published it'),
    HrsaChecks::HIDDEN => xl('need a figure from last year that HRSA hides because it is 1 to 15'),
    HrsaChecks::NOT_THIS_YEAR => sprintf(xl('compare with a national average, which the module has for %1$s, so they run on the %2$s report'), (string)$checks->nationalYear, (string)($checks->nationalYear + 1)),
];
$notRunByReason = [];
foreach ($result['notRun'] as $r) {
    $notRunByReason[$r['reason']] = ($notRunByReason[$r['reason']] ?? 0) + 1;
}
$changeText = static fn(?float $c): string => $c === null ? xl('from 0') : ($c >= 0 ? '+' : '') . number_format(100 * $c, 0) . '%';
$number = static fn(int|float $v): string => number_format((float)$v, is_float($v) && floor($v) != $v ? 2 : 0);

// ------------------------------------------------------------ CSV
if ($request->query->getString('format') === 'csv') {
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Cannot open the output stream');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uds-' . $year . '-hrsa-checks.csv"');
    header('Cache-Control: no-store');
    CsvRow::write($out, ['UDS HRSA checks', (string)$year, Labels::report($grant), 'generated ' . $now->format('Y-m-d H:i')]);
    CsvRow::write($out, []);
    CsvRow::write($out, ['Raised', 'HRSA edit', 'Message', 'HRSA raised it in 2025 for', 'Agreed with HRSA', 'Reads a line counted in part']);
    foreach ($result['raised'] as $r) {
        CsvRow::write($out, ['', implode(' ', $r['edits']), $r['message'], (string)($r['fit']['hrsaRaised'] ?? ''),
            isset($r['fit']['precision']) ? number_format(100 * (float)$r['fit']['precision'], 0) . '%' : '', $r['partial'] ? 'yes' : '']);
    }
    CsvRow::write($out, []);
    CsvRow::write($out, ['Not run', 'Check', 'Why']);
    foreach ($result['notRun'] as $r) {
        CsvRow::write($out, ['', $r['id'], $reasons[$r['reason']] ?? $r['reason']]);
    }
    if ($comparison !== null && $published !== null) {
        CsvRow::write($out, []);
        CsvRow::write($out, ['Changed from last year', 'Cell', (string)$published->year, (string)$year, 'Change']);
        foreach ($comparison['changed'] as $c) {
            CsvRow::write($out, ['', $figures->describe($c['code']), $number($c['prior']), $number($c['current']), $changeText($c['change'])]);
        }
    }
    fclose($out);
    exit;
}

$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('UDS HRSA checks'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenEMR\Modules\UdsReport\Contrast::style(); ?>
    <style>.num { text-align: right; font-variant-numeric: tabular-nums; }</style>
</head>
<body class="body_top">
<div class="container-fluid py-3" style="max-width: 72rem">
    <a href="<?php echo attr($moduleUrl . '/public/index.php?' . $query); ?>">&larr; <?php echo xlt('UDS Report'); ?> <?php echo text((string)$year); ?>, <?php echo text(Labels::report($grant)); ?></a>
    <h1 class="h4 mt-2"><?php echo xlt('HRSA checks'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <form method="get" class="form-inline mb-3">
        <label class="mr-2" for="checks-year"><?php echo xlt('Calendar year'); ?></label>
        <select class="form-control form-control-sm mr-2" id="checks-year" name="year">
            <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
            <?php } ?>
        </select>
        <label class="mr-2" for="checks-grant"><?php echo xlt('Report'); ?></label>
        <select class="form-control form-control-sm mr-2" id="checks-grant" name="grant">
            <option value=""><?php echo text(Labels::report(null)); ?></option>
            <?php foreach (GrantReport::cases() as $g) { ?>
                <option value="<?php echo attr($g->value); ?>" <?php echo $g === $grant ? 'selected' : ''; ?>><?php echo text(Labels::report($g)); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary mr-2"><?php echo xlt('Show'); ?></button>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . '/public/checks.php?' . $query . '&format=csv'); ?>"><?php echo xlt('Download CSV'); ?></a>
    </form>

    <p><?php echo xlt('HRSA runs edit checks on every UDS report in EHBs and asks the health center to correct or explain each one it raises. These are the checks the module can run on its own figures, rebuilt from the edit messages HRSA published with the 2025 UDS data. A check raised here is one HRSA is likely to raise. One not raised here can still be raised: HRSA runs many more, on tables the module does not compute.'); ?></p>

    <?php if ($settings->hrsaGrantNumber === null) { ?>
        <div class="alert alert-info py-2" data-no-grant>
            <?php echo xlt('Without the health center grant or look-alike number, the checks that compare with last year are not run.'); ?>
            <?php if ($isAdmin) { ?>
                <a href="<?php echo attr($moduleUrl . '/public/settings.php#hrsa_grant_number'); ?>"><?php echo xlt('Enter it in Settings.'); ?></a>
            <?php } else {
                echo xlt('An administrator can enter it in Settings.');
            } ?>
        </div>
    <?php } elseif ($publishedYear !== $year - 1) { ?>
        <div class="alert alert-info py-2" data-other-year>
            <?php echo text(sprintf(xl('The module has the UDS figures HRSA published for %1$s, so it compares the %2$s report with them. For %3$s, the checks that compare with last year are not run.'), (string)$publishedYear, (string)((int)$publishedYear + 1), (string)$year)); ?>
        </div>
    <?php } elseif ($published === null) { ?>
        <div class="alert alert-warning py-2" data-not-published>
            <?php echo text(sprintf(xl('%1$s has no %2$s in the %3$s data HRSA published, so the checks that compare with last year are not run.'), $settings->hrsaGrantNumber, Labels::report($grant), (string)$publishedYear)); ?>
        </div>
    <?php } ?>
    <?php if ($grant === null && !$qualityDone) { ?>
        <p class="small text-muted"><?php echo xlt('Tables 6B and 7 are not calculated for this year yet, so their checks are not run.'); ?>
            <a href="<?php echo attr($moduleUrl . '/public/quality.php?year=' . $year); ?>"><?php echo xlt('Tables 6B and 7'); ?></a></p>
    <?php } ?>

    <h2 class="h5 mt-4"><?php echo text(sprintf(xl('%1$s raised, %2$s passed, %3$s not run'), (string)count($result['raised']), (string)count($result['passed']), (string)count($result['notRun']))); ?></h2>
    <?php if ($result['raised'] === []) { ?>
        <p><?php echo xlt('None of the checks the module can run is raised.'); ?></p>
    <?php } else { ?>
        <table class="table table-sm">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('What HRSA is likely to ask about'); ?></th>
                <th><?php echo xlt('HRSA edit'); ?></th>
                <th class="num"><?php echo xlt('Raised by HRSA in 2025 for'); ?></th>
                <th class="num"><?php echo xlt('Agreed with HRSA'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['raised'] as $r) { ?>
                <tr>
                    <td><?php echo text($r['message']); ?>
                        <?php if ($r['partial']) { ?><br><span class="small text-muted"><?php echo xlt('It reads a Table 6A line the module counts only in part: check that line against your records before you answer it.'); ?></span><?php } ?></td>
                    <td><?php echo text(implode(', ', $r['edits'])); ?></td>
                    <td class="num"><?php echo isset($r['fit']['hrsaRaised']) ? text(sprintf(xl('%1$s health centers'), number_format((int)$r['fit']['hrsaRaised']))) : ''; ?></td>
                    <td class="num"><?php echo isset($r['fit']['precision']) ? text(number_format(100 * (float)$r['fit']['precision'], 0) . '%') : ''; ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <p class="small text-muted"><?php echo xlt('Agreed with HRSA: of the 2025 reports this check raises on, the share HRSA also raised it on. Answer each in EHBs by correcting the figure or explaining it; the explanation goes in the comment HRSA asks for.'); ?></p>
    <?php } ?>
    <?php if ($notRunByReason !== []) { ?>
        <h3 class="h6 mt-3"><?php echo xlt('Not run'); ?></h3>
        <ul class="small">
            <?php foreach ($notRunByReason as $reason => $count) { ?>
                <li><?php echo text((string)$count); ?> <?php echo text($reasons[$reason] ?? $reason); ?></li>
            <?php } ?>
        </ul>
    <?php } ?>

    <?php if ($comparison !== null && $published !== null) { ?>
        <h2 class="h5 mt-4"><?php echo text(sprintf(xl('Compared with %1$s as HRSA published it'), (string)$published->year)); ?></h2>
        <p class="small text-muted"><?php echo text($published->displayName() . ', ' . $published->state . ' (' . $published->grant . ')'); ?>.
            <?php echo text(sprintf(xl('Cells that changed by more than %1$s and by at least %2$s, out of %3$s compared. HRSA reviewers ask about changes this size.'), number_format(100 * PriorYearComparison::SHARE, 0) . '%', (string)PriorYearComparison::DIFFERENCE, number_format($comparison['compared']))); ?>
            <?php if ($comparison['hidden'] > 0) {
                echo text(sprintf(xl('%1$s cells were 1 to 15 last year, which HRSA hides in its public file, so they are not compared.'), number_format($comparison['hidden'])));
            } ?></p>
        <?php if ($comparison['changed'] === []) { ?>
            <p><?php echo xlt('No cell changed that much.'); ?></p>
        <?php } else { ?>
            <table class="table table-sm">
                <thead class="thead-light"><tr>
                    <th><?php echo xlt('Cell'); ?></th>
                    <th class="num"><?php echo text((string)$published->year); ?></th>
                    <th class="num"><?php echo text((string)$year); ?></th>
                    <th class="num"><?php echo xlt('Change'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($comparison['changed'] as $c) { ?>
                    <tr>
                        <td><?php echo text($figures->describe($c['code'])); ?></td>
                        <td class="num"><?php echo text($number($c['prior'])); ?></td>
                        <td class="num"><?php echo text($number($c['current'])); ?></td>
                        <td class="num"><?php echo text($changeText($c['change'])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    <?php } ?>

    <h2 class="h6 mt-4"><?php echo xlt('Where the checks come from'); ?></h2>
    <p class="small text-muted"><?php echo xlt('HRSA does not publish its edit checks. It publishes, with each year of UDS data, every edit message it raised on every report. The module keeps the checks whose figures it computes, and takes each threshold from the message where the message states one, or else from the 2025 reports HRSA did and did not raise it on. A check is kept only where it agreed with HRSA at least three times in four. Last year is the UDS data HRSA publishes at data.hrsa.gov, by grant or look-alike number.'); ?></p>
</div>
</body>
</html>
