<?php

/**
 * UDS Report: the Universal Report or a Grant Report (330(g), (h) or (i)) for a year.
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
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\DemographicTables;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\Table5;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS Report', xl('UDS Report'));
}
$isAdmin = AclMain::aclCheckCore('admin', 'super');
// Revenue is shown to those who can see the core Collections report.
$canSeeRevenue = AclMain::aclCheckCore('acct', 'rep_a');
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop($isAdmin);

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(Request::get()->query->getString('year'), $now);
$grant = GrantReport::tryFrom(Request::get()->query->getString('grant'));
$query = 'year=' . $year . ($grant === null ? '' : '&grant=' . $grant->value);
$report = new UdsReportService($year, (new SettingsRepository())->load(), $reference, new UdsPatientRepository($reference->insuranceCategories), $grant);
$tables = $report->tables();
$zip = $tables->zipTable();
$t3a = $tables->table3A();
$t3b = $tables->table3B();
$t4 = $tables->table4();
$check = $tables->crossCheck();
$t5 = $report->table5()->result();
$t6a = $report->table6A()->rows();
$gaps = $report->gaps();
$region = $report->settings->povertyRegion;
$onePerson = $reference->povertyGuidelines->annualThreshold($year, $region, 1);
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
$n = static fn(int $v): string => number_format($v);
$patients = static fn(int $count): string => $count === 1 ? xl('patient') : xl('patients');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS Report'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-table .subtotal td { font-weight: 600; background: var(--light, #f8f9fa); }
        .uds-table .grant-only td { color: var(--gray, #6c757d); }
        .uds-section { margin-top: 2rem; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS Report'); ?>: <?php echo text((string)$year); ?> <small class="text-muted"><?php echo text(Labels::report($grant)); ?></small></h1>
        <form method="get" class="form-inline mb-2">
            <label class="mr-2" for="uds-year"><?php echo xlt('Calendar year'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-year" name="year" onchange="this.form.submit()">
                <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                    <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                <?php } ?>
            </select>
            <label class="mr-2" for="uds-grant"><?php echo xlt('Report'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-grant" name="grant" onchange="this.form.submit()">
                <option value=""><?php echo text(Labels::report(null)); ?></option>
                <?php foreach (GrantReport::cases() as $g) { ?>
                    <option value="<?php echo attr($g->value); ?>" <?php echo $g === $grant ? 'selected' : ''; ?>><?php echo text(Labels::report($g)); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/export.php?' . $query); ?>"><?php echo xlt('Download CSV'); ?></a>
            <?php if ($grant === null) { ?>
                <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/ecqm.php?year=' . $year); ?>"><?php echo xlt('eCQM readiness'); ?></a>
                <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/quality.php?year=' . $year); ?>"><?php echo xlt('Tables 6B and 7'); ?></a>
            <?php } ?>
            <?php if ($grant === null && $canSeeRevenue) { ?>
                <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/table9d.php?year=' . $year); ?>"><?php echo xlt('Table 9D worksheet'); ?></a>
            <?php } ?>
            <?php if ($isAdmin) { ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . '/public/settings.php'); ?>"><?php echo xlt('Settings'); ?></a>
            <?php } ?>
        </form>
    </div>
    <p class="text-muted small mb-3">
        <?php echo xlt('Patients with at least one countable visit in the year, counted by the 2026 UDS Manual rules. Check every figure against your own records before entering it in the EHBs.'); ?>
    </p>
    <?php if ($grant !== null) { ?>
    <div class="alert alert-info small">
        <?php echo text(sprintf(
            xl('This Grant Report covers the patients counted on Table 4, Line %s of the Universal Report, and all their in-scope visits and services, whatever paid for them. Only health centers with more than one Health Center Program funding type file Grant Reports. It has Tables 3A, 3B, 4, 5 (without FTEs) and 6A; the Patients by ZIP Code table is not part of it.'),
            $grant->table4Line(),
        )); ?>
    </div>
    <?php } ?>

    <?php if (!$check['consistent']) { ?>
        <div class="alert alert-warning">
            <strong><?php echo xlt('The table totals do not agree yet.'); ?></strong>
            <?php echo xlt('The UDS requires the same patient total on every table. Fix the blocking record problems below, then reload.'); ?>
            <div class="small mt-1">
                <?php echo xlt('Patients'); ?> <?php echo text($n($check['patients'])); ?> ·
                <?php echo xlt('ZIP table'); ?> <?php echo text($n($check['zip'])); ?> ·
                <?php echo xlt('Table 3A'); ?> <?php echo text($n($check['table3A'])); ?> ·
                <?php echo xlt('Table 3B'); ?> <?php echo text($n($check['table3B'])); ?> ·
                <?php echo xlt('Table 4 income'); ?> <?php echo text($n($check['table4Income'])); ?> ·
                <?php echo xlt('Table 4 insurance'); ?> <?php echo text($n($check['table4Insurance'])); ?>
            </div>
        </div>
    <?php } else { ?>
        <div class="alert alert-success py-2"><?php echo xlt('Table totals agree'); ?>: <?php echo text($n($check['patients'])); ?> <?php echo text($patients($check['patients'])); ?>.</div>
    <?php } ?>
    <?php if (!$t4['povertyTableAvailable']) { ?>
        <div class="alert alert-warning py-2"><?php echo xlt('Poverty guidelines for this year and region are not loaded, so every patient is shown under Unknown income.'); ?></div>
    <?php } ?>

    <h2 class="h5 uds-section"><?php echo xlt('Records to fix before submitting'); ?></h2>
    <table class="table table-sm uds-table mb-1">
        <tbody>
        <?php foreach (DataGap::cases() as $gap) {
            $count = count($gaps[$gap->value] ?? []);
            if ($count === 0) {
                continue;
            } ?>
            <tr>
                <td><?php echo $gap->isBlocking() ? '<span class="badge badge-danger">' . xlt('Blocking') . '</span>' : '<span class="badge badge-light border">' . xlt('Quality') . '</span>'; ?></td>
                <td><?php echo text(Labels::gap($gap)); ?></td>
                <td class="num"><a href="<?php echo attr($moduleUrl . '/public/gaps.php?' . $query . '&gap=' . urlencode($gap->value)); ?>"><?php echo text($n($count)); ?> <?php echo text($patients($count)); ?></a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if (array_sum(array_map(count(...), $gaps)) === 0) { ?>
        <p class="text-muted"><?php echo xlt('No record problems found.'); ?></p>
    <?php } ?>

    <?php if ($grant === null) { ?>
    <h2 class="h5 uds-section"><?php echo xlt('Patients by ZIP Code'); ?></h2>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('ZIP Code'); ?> (A)</th>
                <th class="num"><?php echo xlt('None/Uninsured'); ?> (B)</th>
                <th class="num"><?php echo xlt('Medicaid/CHIP/Other Public'); ?> (C)</th>
                <th class="num"><?php echo xlt('Medicare'); ?> (D)</th>
                <th class="num"><?php echo xlt('Private'); ?> (E)</th>
                <th class="num"><?php echo xlt('Total Patients'); ?> (F)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($zip['rows'] as $label => $row) { ?>
                <tr>
                    <td><?php echo text(match ($label) {
                        DemographicTables::OTHER_ZIPS => xl('Other ZIP Codes'),
                        DemographicTables::UNKNOWN_RESIDENCE => xl('Unknown Residence'),
                        default => (string)$label,
                        }); ?></td>
                    <?php foreach ([...DemographicTables::ZIP_COLUMNS, 'total'] as $c) { ?>
                        <td class="num"><?php echo text($n($row[$c])); ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            <tr class="subtotal"><td><?php echo xlt('Total'); ?></td>
                <?php foreach ([...DemographicTables::ZIP_COLUMNS, 'total'] as $c) { ?>
                    <td class="num"><?php echo text($n(array_sum(array_column($zip['rows'], $c)))); ?></td>
                <?php } ?>
            </tr>
            </tbody>
        </table>
    </div>
    <?php } ?>

    <h2 class="h5 uds-section"><?php echo xlt('Table 3A: Patients by Age and by Sex'); ?></h2>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table" style="max-width: 36rem">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Age Groups'); ?></th>
                <th class="num"><?php echo xlt('Male Patients'); ?> (A)</th><th class="num"><?php echo xlt('Female Patients'); ?> (B)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($t3a['lines'] as $line => $r) { ?>
                <tr><td><?php echo text((string)$line); ?></td><td><?php echo text($r['label']); ?></td>
                    <td class="num"><?php echo text($n($r['male'])); ?></td><td class="num"><?php echo text($n($r['female'])); ?></td></tr>
            <?php } ?>
            <tr class="subtotal"><td>39</td><td><?php echo xlt('Total Patients'); ?></td>
                <td class="num"><?php echo text($n($t3a['totalMale'])); ?></td><td class="num"><?php echo text($n($t3a['totalFemale'])); ?></td></tr>
            </tbody>
        </table>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Table 3B: Demographic Characteristics'); ?></h2>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Race'); ?></th>
                <?php foreach (Labels::ethnicityColumns() as $col => $label) { ?>
                    <th class="num" title="<?php echo attr($label); ?>"><?php echo text($col); ?></th>
                <?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach (Labels::raceLines() as $key => $lineLabel) {
                $line = (string)$key; ?>
                <tr class="<?php echo in_array($line, ['1', '2', '8'], true) ? 'subtotal' : ''; ?>">
                    <td><?php echo text($line); ?></td><td><?php echo text($lineLabel); ?></td>
                    <?php foreach (array_keys(Labels::ethnicityColumns()) as $col) { ?>
                        <td class="num"><?php echo ($col === 'C' && !in_array($line, ['7', '8'], true)) ? '' : text($n($t3b['cells'][$line][$col] ?? 0)); ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <p class="small text-muted"><?php foreach (Labels::ethnicityColumns() as $col => $label) {
        echo text($col . ': ' . $label) . ' · ';
                                } ?></p>
    <table class="table table-sm table-bordered uds-table" style="max-width: 36rem">
        <tbody><tr><td>12</td><td><?php echo xlt('Patients Best Served in a Language Other Than English'); ?></td><td class="num"><?php echo text($n($t3b['line12'])); ?></td></tr></tbody>
    </table>

    <h2 class="h5 uds-section"><?php echo xlt('Table 4: Selected Patient Characteristics'); ?></h2>
    <div class="row">
        <div class="col-lg-5">
            <table class="table table-sm table-bordered uds-table">
                <thead class="thead-light"><tr><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Income as Percentage of Poverty Guideline'); ?></th><th class="num">(A)</th></tr></thead>
                <tbody>
                <?php foreach (Labels::table4Income() as $key => $label) {
                    $line = (string)$key; ?>
                    <tr class="<?php echo $line === '6' ? 'subtotal' : ''; ?>"><td><?php echo text($line); ?></td><td><?php echo text($label); ?></td><td class="num"><?php echo text($n($t4['income'][$line] ?? 0)); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <p class="small text-muted"><?php echo xlt('Poverty guidelines'); ?>: <?php echo text((string)$year); ?>, <?php echo text(Labels::povertyRegion($region)); ?>
                (<?php echo $onePerson === null ? xlt('not loaded') : text($n($onePerson)) . ' ' . xlt('for one person'); ?>)</p>
        </div>
        <div class="col-lg-7">
            <table class="table table-sm table-bordered uds-table">
                <thead class="thead-light"><tr><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Primary Third-Party Medical Insurance'); ?></th>
                    <th class="num"><?php echo xlt('0–17 Years Old'); ?> (A)</th><th class="num"><?php echo xlt('18 and Older'); ?> (B)</th></tr></thead>
                <tbody>
                <?php foreach (Labels::table4Insurance() as $key => $label) {
                    $line = (string)$key;
                    $cell = $t4['insurance'][$line] ?? ['child' => 0, 'adult' => 0]; ?>
                    <tr class="<?php echo in_array($line, ['8', '10', '12'], true) ? 'subtotal' : ''; ?>"><td><?php echo text($line); ?></td><td><?php echo text($label); ?></td>
                        <td class="num"><?php echo text($n($cell['child'])); ?></td><td class="num"><?php echo text($n($cell['adult'])); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    <table class="table table-sm table-bordered uds-table" style="max-width: 52rem">
        <thead class="thead-light"><tr><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Special Medically Underserved Populations'); ?></th><th class="num">(A)</th></tr></thead>
        <tbody>
        <?php foreach (Labels::table4Special() as $key => $label) {
            $line = (string)$key; ?>
            <tr class="<?php echo in_array($line, ['14', '15', '17', '18', '19', '20', '21a', '21', '22'], true) ? 'grant-only' : ''; ?>">
                <td><?php echo text($line); ?></td><td><?php echo text($label); ?></td><td class="num"><?php echo text($n($t4['special'][$line] ?? 0)); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
    <p class="small text-muted"><?php echo xlt('Lines 14-15 and 17-22 are entered only by health centers with 330(g) or 330(h) funding; everyone enters the totals on Lines 16 and 23.'); ?></p>

    <h2 class="h5 uds-section"><?php echo xlt('Table 5: Staffing and Utilization'); ?></h2>
    <p class="small text-muted"><?php echo xlt('Visits and patients from the countable visits, each on the line of the provider who saw the patient: the line set for the visit category, else the line chosen for the provider in Settings, else the one suggested by the taxonomy code of the provider. One visit per patient, service category, site and day. FTEs (Column A) come from your HR records; enter them in EHB.'); ?></p>
    <table class="table table-sm table-bordered uds-table" style="max-width: 70rem">
        <thead class="thead-light"><tr><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Personnel by Major Service Category'); ?></th><th class="num"><?php echo xlt('FTEs'); ?> (A)</th><th class="num"><?php echo xlt('Clinic Visits'); ?> (B)</th><th class="num"><?php echo xlt('Virtual Visits'); ?> (B2)</th><th class="num"><?php echo xlt('Patients'); ?> (C)</th></tr></thead>
        <tbody>
        <?php foreach ($t5['rows'] as $row) {
            $l = $row['line']; ?>
            <tr class="<?php echo $l->isPersonnel() ? '' : 'subtotal'; ?>">
                <td><?php echo text($l->line); ?></td>
                <td><?php echo text($l->label); ?></td>
                <td class="num text-muted" title="<?php echo xla('From HR records'); ?>">&mdash;</td>
                <td class="num"><?php echo $row['visits'] === null ? '&mdash;' : text($n($row['visits'])); ?></td>
                <td class="num"><?php echo $row['virtual'] === null ? '&mdash;' : text($n($row['virtual'])); ?></td>
                <td class="num"><?php echo $row['patients'] === null ? '&mdash;' : text($n($row['patients'])); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php
    $unconfirmed = array_filter($t5['providers'], static fn(array $p): bool => in_array($p['source'], [Table5::SOURCE_DEFAULT_TAXONOMY, Table5::SOURCE_NONE], true));
    $sourceLabel = [
        Table5::SOURCE_CLINIC => xl('chosen in Settings'),
        Table5::SOURCE_TAXONOMY => xl('from the taxonomy code'),
        Table5::SOURCE_DEFAULT_TAXONOMY => xl('from the default taxonomy code OpenEMR gives every user; confirm it'),
        Table5::SOURCE_NONE => xl('no line known; choose one'),
    ];
    ?>
    <ul class="small mb-2">
        <?php if ($unconfirmed !== []) { ?>
        <li class="text-danger"><?php echo text(sprintf(xl('%s providers need their Table 5 line confirmed or chosen in Settings.'), $n(count($unconfirmed)))); ?></li>
        <?php } ?>
        <?php if ($t5['notCounted'] > 0) { ?>
        <li><?php echo text(sprintf(xl('%s countable encounters are not Table 5 visits: their provider is not a provider for UDS purposes, or has no line.'), $n($t5['notCounted']))); ?></li>
        <?php } ?>
        <?php if ($t5['patientsWithoutVisit'] !== []) { ?>
        <li class="text-danger"><?php echo text(sprintf(xl('%s UDS patients have no Table 5 visit, so the tables disagree. Their only visits are with someone who is not a provider, or has no line.'), $n(count($t5['patientsWithoutVisit'])))); ?></li>
        <?php } ?>
        <?php if ($t5['merged'] > 0) { ?>
        <li><?php echo text(sprintf(xl('%s encounters were counted into another visit (one visit per patient, service category, site and day).'), $n($t5['merged']))); ?></li>
        <?php } ?>
        <?php if ($t5['behavioralWithoutDiagnosis'] > 0) { ?>
        <li><?php echo text(sprintf(xl('%s mental health or substance use visits have no diagnosis, so they stay on the line of the provider instead of going by the primary diagnosis.'), $n($t5['behavioralWithoutDiagnosis']))); ?></li>
        <?php } ?>
    </ul>
    <details class="small mb-4" <?php echo $unconfirmed !== [] ? 'open' : ''; ?>>
        <summary><?php echo xlt('Providers and their Table 5 lines'); ?></summary>
        <table class="table table-sm table-bordered mt-2" style="max-width: 60rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Provider'); ?></th><th><?php echo xlt('Taxonomy'); ?></th><th class="num"><?php echo xlt('Countable encounters'); ?></th><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('How'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($t5['providers'] as $p) { ?>
                <tr class="<?php echo in_array($p['source'], [Table5::SOURCE_DEFAULT_TAXONOMY, Table5::SOURCE_NONE], true) ? 'table-warning' : ''; ?>">
                    <td><?php echo text($p['provider']->name); ?></td>
                    <td><?php echo text($p['provider']->taxonomy); ?></td>
                    <td class="num"><?php echo text($n($p['encounters'])); ?></td>
                    <td><?php echo $p['line'] === null ? xlt('not counted') : text($p['line']); ?></td>
                    <td><?php echo text($sourceLabel[$p['source']] ?? $p['source']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($isAdmin) { ?>
        <a href="<?php echo attr($moduleUrl . '/public/settings.php#table5'); ?>"><?php echo xlt('Choose lines in Settings'); ?></a>
        <?php } ?>
    </details>

    <h2 class="h5 uds-section"><?php echo xlt('Table 6A: Selected Diagnoses and Services Rendered'); ?></h2>
    <p class="small text-muted"><?php echo xlt('From the billing codes on each encounter (the fee sheet). Diagnoses count at countable visits; tests, screenings, dental and support services at any encounter in the year at an in-scope site, for UDS patients. * counts only the codes the manual lists; a dash is a line defined only by value sets, not counted yet.'); ?></p>
    <table class="table table-sm table-bordered uds-table" style="max-width: 60rem">
        <thead class="thead-light"><tr><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Diagnosis or Service'); ?></th><th class="num"><?php echo xlt('Visits'); ?> (A)</th><th class="num"><?php echo xlt('Patients'); ?> (B)</th></tr></thead>
        <tbody>
        <?php $group = null;
        foreach ($t6a as $row) {
            $l = $row['line'];
            if ($l->group !== $group) {
                $group = $l->group; ?>
            <tr class="subtotal"><td colspan="4"><?php echo text($group); ?></td></tr>
            <?php } ?>
            <tr class="<?php echo $l->isCounted() ? '' : 'grant-only'; ?>">
                <td><?php echo text($l->line); ?></td>
                <td><?php echo text($l->label); ?><?php if ($l->limit !== null) { ?> <span title="<?php echo attr($l->limit); ?>">*</span><?php } ?></td>
                <td class="num"><?php echo $row['visits'] === null ? '&mdash;' : text($n($row['visits'])); ?></td>
                <td class="num"><?php echo $row['patients'] === null ? '&mdash;' : text($n($row['patients'])); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <details class="small text-muted mb-4">
        <summary><?php echo xlt('What * and the dashes leave out, line by line'); ?></summary>
        <ul class="mt-2">
        <?php foreach ($t6a as $row) {
            if ($row['line']->limit !== null) { ?>
            <li><?php echo xlt('Line'); ?> <?php echo text($row['line']->line); ?>: <?php echo text($row['line']->limit); ?></li>
            <?php }
        } ?>
        </ul>
    </details>
</div>
</body>
</html>
