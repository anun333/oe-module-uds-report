<?php

/**
 * UDS Report: eCQM readiness for Tables 6B and 7. Whether this OpenEMR can
 * calculate the year's measures, and which records the calculation can't see.
 * Aggregate counts only.
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
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\EcqmChecks;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Repository\EcqmReadinessRepository;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS eCQM readiness', xl('UDS Report'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop(AclMain::aclCheckCore('admin', 'super'));

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(CurrentRequest::get()->query->getString('year'), $now);
$settings = (new SettingsRepository())->load();
$readiness = new EcqmReadinessRepository(new UdsPatientRepository($reference->insuranceCategories));
$measures = $reference->ecqmMeasures->forYear($year);
$installed = $readiness->installedMeasures($year);
$visits = $readiness->visits($year, $settings);
$types = $readiness->encounterTypes();
$problems = $readiness->problems($year, $settings);
$pressures = $readiness->bloodPressures($year, $settings);
$examTypeActive = $readiness->physicalExamTypeActive();
$openEnds = EcqmReadinessRepository::openEndsCount();
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
$n = static fn(int $v): string => number_format($v);
$ready = 0;
foreach ($measures as $m) {
    $ready += EcqmChecks::measureStatus($m['measure'], $installed ?? [])['installed'] ? 1 : 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS eCQM readiness'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-section { margin-top: 2rem; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS eCQM readiness'); ?>: <?php echo text((string)$year); ?> <small class="text-muted"><?php echo xlt('Tables 6B and 7'); ?></small></h1>
        <form method="get" class="form-inline mb-2">
            <label class="mr-2" for="uds-year"><?php echo xlt('Calendar year'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-year" name="year" onchange="this.form.submit()">
                <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                    <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/index.php?year=' . $year); ?>"><?php echo xlt('UDS Report'); ?></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . '/public/quality.php?year=' . $year); ?>"><?php echo xlt('Tables 6B and 7'); ?></a>
        </form>
    </div>
    <div class="alert alert-info small">
        <?php echo xlt('Most of Tables 6B and 7 are electronic clinical quality measures (eCQMs), which OpenEMR calculates from its own records. This page shows whether this OpenEMR can calculate the measures for the year, and which records the calculation cannot see, so they can be fixed before the measures are run. This module does not calculate Tables 6B and 7 yet.'); ?>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Records the measures cannot see'); ?></h2>
    <p class="text-muted small"><?php echo text(sprintf(xl('Countable visits in the year: %s. Problems and blood pressures are those of the patients seen at them.'), $n($visits['visits']))); ?></p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Records'); ?></th><th class="num"><?php echo xlt('Count'); ?></th><th><?php echo xlt('Why, and how to fix'); ?></th>
            </tr></thead>
            <tbody>
            <tr>
                <td><?php echo xlt('Visits with no coded Encounter Type'); ?></td>
                <td class="num"><?php echo text($n($visits['untyped'])); ?> / <?php echo text($n($visits['visits'])); ?></td>
                <td class="small">
                    <?php echo xlt('The measures find visits only by the code of their Encounter Type; billing codes on the fee sheet are not read. Choose an Encounter Type on each visit, and give each type a CPT or SNOMED CT code in Admin > Forms > Lists > Encounter Types.'); ?>
                    <?php if ($types['uncoded'] !== []) { ?>
                        <div class="mt-1"><?php echo text(sprintf(xl('Encounter Types with no code: %d of %d'), count($types['uncoded']), $types['active'])); ?>:
                            <?php echo text(implode(', ', $types['uncoded'])); ?></div>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <td><?php echo xlt('Problems not classified as Diagnosis'); ?></td>
                <td class="num"><?php echo text($n($problems['unclassified'])); ?> / <?php echo text($n($problems['problems'])); ?></td>
                <td class="small"><?php echo xlt('The measures read a problem only when its Classification Type is Diagnosis, and a new problem has none. Set it when adding or editing the problem.'); ?></td>
            </tr>
            <tr>
                <td><?php echo xlt('Problems with no code'); ?></td>
                <td class="num"><?php echo text($n($problems['uncoded'])); ?> / <?php echo text($n($problems['problems'])); ?></td>
                <td class="small"><?php echo xlt('Give each problem an ICD-10 or SNOMED CT code; the measures match codes, not titles.'); ?></td>
            </tr>
            <tr>
                <td><?php echo xlt('Patients whose blood pressure is only in the Vitals form'); ?></td>
                <td class="num"><?php echo text($n($pressures['vitalsOnly'])); ?> / <?php echo text($n($pressures['patients'])); ?></td>
                <td class="small">
                    <?php echo xlt('Controlling High Blood Pressure (Table 7) reads blood pressure only from the Observation form: type Physical Exam Performed, codes LOINC 8480-6 (systolic) and 8462-4 (diastolic), unit mm[Hg]. The Vitals form is not read.'); ?>
                    <?php if ($examTypeActive === false) { ?>
                        <div class="mt-1"><?php echo xlt('The Physical Exam Performed type is turned off in Admin > Forms > Lists > Observation Types.'); ?></div>
                    <?php } ?>
                </td>
            </tr>
            <?php
            $endRows = [
                [$openEnds['problems'], xl('Ongoing problems (no end date)'), $problems['noEndDate'], $problems['problems'],
                    xl('This OpenEMR treats a missing end date as unknown, so an ongoing problem never overlaps the year (openemr/openemr issue 14526). Do not add end dates to ongoing problems to work around it: an end date marks a problem resolved.')],
                [$openEnds['visits'], xl('Visits with no end time'), $visits['noEndTime'], $visits['visits'],
                    xl('This OpenEMR treats a missing end time as unknown, so a visit with none is never during the year (openemr/openemr issue 14526). The visit screen sets no end time, so there is nothing to fix in the records.')],
            ];
            foreach ($endRows as [$counts, $label, $count, $of, $why]) {
                if ($counts === true) {
                    continue;
                } ?>
            <tr<?php echo $counts === false ? ' class="table-warning"' : ''; ?>>
                <td><?php echo text($label); ?></td>
                <td class="num"><?php echo $counts === false ? text($n($count) . ' / ' . $n($of)) : '?'; ?></td>
                <td class="small"><?php echo $counts === false ? text($why) : xlt('Could not ask this OpenEMR how it treats a missing end date.'); ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Measures'); ?></h2>
    <p class="text-muted small">
        <?php echo EcqmReadinessRepository::phpEngine()
            ? xlt('This OpenEMR calculates eCQMs with its PHP engine.')
            : xlt('This OpenEMR calculates eCQMs with its Node service.'); ?>
        <?php if ($measures === []) { ?>
            <?php echo xlt('This module does not list the UDS measures for this year.'); ?>
        <?php } elseif ($installed === null) { ?>
            <?php echo xlt('No eCQM files are installed for this year.'); ?>
        <?php } else { ?>
            <?php echo text(sprintf(xl('%d of the %d measures UDS uses are installed at the version it names.'), $ready, count($measures))); ?>
        <?php } ?>
    </p>
    <?php if ($measures !== []) { ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Table'); ?></th><th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Measure'); ?></th>
                <th><?php echo xlt('Version UDS uses'); ?></th><th><?php echo xlt('Installed'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($measures as $m) {
                $status = EcqmChecks::measureStatus($m['measure'], $installed ?? []); ?>
                <tr>
                    <td><?php echo text($m['table']); ?></td>
                    <td><?php echo text($m['line']); ?></td>
                    <td><?php echo text($m['title']); ?></td>
                    <td><?php echo text($m['measure']); ?></td>
                    <td><?php echo $status['installed'] ? xlt('Yes') : (text(xl('No')) . ($status['otherVersions'] === [] ? '' : text(' (' . xl('only') . ' ' . implode(', ', $status['otherVersions']) . ')'))); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>
</div>
</body>
</html>
