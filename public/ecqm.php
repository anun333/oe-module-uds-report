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
use OpenEMR\Core\Header;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\EcqmChecks;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Repository\EcqmReadinessRepository;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS eCQM readiness', xl('UDS Report'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop(AclMain::aclCheckCore('admin', 'super'));

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(Request::get()->query->getString('year'), $now);
$settings = (new SettingsRepository())->load();
$readiness = new EcqmReadinessRepository(new UdsPatientRepository($reference->insuranceCategories));
$measures = $reference->ecqmMeasures->forYear($year);
$installed = $readiness->installedMeasures($year);
$visits = $readiness->visits($year, $settings);
$types = $readiness->encounterTypes();
$problems = $readiness->problems($year, $settings);
$pressures = $readiness->bloodPressures($year, $settings);
$examTypeActive = $readiness->physicalExamTypeActive();
$bmis = $readiness->bodyMassIndexes($year, $settings);
$tobacco = $readiness->tobaccoInHistory($year, $settings);
$phq9 = $readiness->depressionForms($year, $settings);
$misplaced = $readiness->misplacedCodes($year, $settings);
$kinds = [
    'tobacco' => xl('Tobacco screening'), 'depression' => xl('Depression screening'), 'falls' => xl('Falls screening'),
    'bmi' => xl('BMI'), 'bmiPercentile' => xl('BMI percentile'), 'bloodPressure' => xl('Blood pressure'),
];
$typeTitles = ['assessment' => xl('Assessment'), 'physical_exam_performed' => xl('Physical Exam Performed')];
$openEnds = EcqmReadinessRepository::openEndsCount();
$labs = $readiness->labResults($year, $settings);
$decimalsKept = EcqmReadinessRepository::labDecimalsKept();
$vitalsRead = EcqmReadinessRepository::vitalsFormRead();
$leftOut = EcqmReadinessRepository::engineLeavesOut();
$wrong = $readiness->wrongRecords($year, $settings);
$wrongWhy = [
    'problemsInError' => [xl('Problems marked Entered in Error or Refuted'), xl('The measures still read a problem classified as Diagnosis whatever its Verification Status, so these patients can be counted with a condition they do not have, as in Controlling High Blood Pressure and the diabetes measure (Table 7). Until OpenEMR leaves them out, clear the Classification Type of these problems: the measures read only problems classified as Diagnosis.')],
    'immunizationsInError' => [xl('Immunizations marked Entered in error'), xl('The measures still read an immunization marked Entered in error, so Childhood Immunization Status (CMS117, Table 6B Line 10) can count a dose that was not given. Until OpenEMR leaves them out, only deleting the record keeps it out of the measures.')],
    'inactivePrescriptions' => [xl('Prescriptions marked not active, with no end date'), xl('The measures read a prescription with Currently Active unticked as still taken, because it has no end date, so statin therapy (CMS347, Table 6B Line 17a) and other measures can count a medication that was stopped. Give a stopped prescription its end date.')],
    'deletedObservations' => [xl('Observations in forms deleted from the visit'), xl('The measures still read the observations of an Observation form deleted from its visit, screenings and exams among them (openemr/openemr pull request 14587). Nothing to fix in the records: the count is what the measures can read wrongly.')],
    'deletedCarePlans' => [xl('Care plan entries in forms deleted from the visit'), xl('The measures still read the entries of a Care Plan form deleted from its visit as ordered or recommended interventions, medications and tests, which the follow-up plans of the BMI, tobacco and depression measures (Table 6B Lines 13, 14a and 21) can count. Nothing to fix in the records.')],
    'deletedOrders' => [xl('Orders in forms deleted from the visit'), xl('The measures still read a procedure, intervention or lab order whose form was deleted from its visit, with its results. Nothing to fix in the records.')],
    'labResultsInError' => [xl('Lab results reported as error or cancelled'), xl('The measures read every lab result whatever its status, so a result the lab reported in error or cancelled can count, as an HbA1c can in the diabetes measure (CMS122, Table 7). Nothing to fix in the records.')],
];
$wrongRows = array_filter(
    $wrongWhy,
    static fn(string $kind): bool => ($leftOut[$kind] ?? null) === false && $wrong[$kind]['records'] > 0,
    ARRAY_FILTER_USE_KEY
);
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
$n = static fn(int $v): string => number_format($v);
$ready = 0;
foreach ($measures as $m) {
    $ready += EcqmChecks::measureStatus($m['measure'], $installed ?? [])['installed'] ? 1 : 0;
}
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <title><?php echo xlt('UDS eCQM readiness'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenEMR\Modules\UdsReport\Contrast::style(); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-section { margin-top: 2rem; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS eCQM readiness'); ?>: <?php echo text((string)$year); ?> <small class="text-muted"><?php echo xlt('Tables 6B and 7'); ?></small></h1>
        <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
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
        <?php echo xlt('Most of Tables 6B and 7 are electronic clinical quality measures (eCQMs), which OpenEMR calculates from its own records. This page shows whether this OpenEMR can calculate the measures for the year, and which records the calculation cannot see or refuses, so they can be fixed before the measures are run on the Tables 6B and 7 page.'); ?>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Records the measures cannot see'); ?></h2>
    <p class="text-muted small"><?php echo text(sprintf(xl('Countable visits in the year: %s. The problems, vitals, History, forms, observations and lab results counted are those of the patients seen at them.'), $n($visits['visits']))); ?></p>
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
            <?php if (!$vitalsRead) { ?>
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
            <tr>
                <td><?php echo xlt('Patients whose BMI is only in the Vitals form'); ?></td>
                <td class="num"><?php echo text($n($bmis['vitalsOnly'])); ?> / <?php echo text($n($bmis['patients'])); ?></td>
                <td class="small">
                    <?php echo xlt('The BMI measures read BMI only from the Observation form, type Physical Exam Performed: code LOINC 39156-5 for adults (CMS69, Table 6B Line 13), and for children BMI percentile LOINC 59576-9 with height and weight (CMS155, Line 12). The Vitals form is not read.'); ?>
                    <?php if ($examTypeActive === false) { ?>
                        <div class="mt-1"><?php echo xlt('The Physical Exam Performed type is turned off in Admin > Forms > Lists > Observation Types.'); ?></div>
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
            <tr>
                <td><?php echo xlt('Patients whose tobacco use is only in History'); ?></td>
                <td class="num"><?php echo text($n($tobacco['historyOnly'])); ?> / <?php echo text($n($tobacco['patients'])); ?></td>
                <td class="small"><?php echo xlt('Tobacco screening (CMS138, Table 6B Line 14a) reads only the Observation form: type Assessment, code LOINC 72166-2 (or 39240-7, 68535-4, 68536-2), with the result coded as tobacco user or non-user. The Tobacco field in History is not read. Counted here: History saved during the year with a tobacco status.'); ?></td>
            </tr>
            <?php if ($phq9 !== null) { ?>
            <tr>
                <td><?php echo xlt('Patients with a PHQ-9 form and no depression screening the measure reads'); ?></td>
                <td class="num"><?php echo text($n($phq9['formOnly'])); ?> / <?php echo text($n($phq9['patients'])); ?></td>
                <td class="small"><?php echo xlt('Depression screening (CMS2, Table 6B Line 21) reads only the Observation form: type Assessment, code LOINC 73832-8 (adults) or 73831-0 (adolescents), with the result coded positive or negative. The PHQ-9 form is not read.'); ?></td>
            </tr>
            <?php }
            foreach ($misplaced as $kind => $patients) {
                $read = EcqmChecks::OBSERVATION_CODES[$kind]; ?>
            <tr class="table-warning">
                <td><?php echo text(sprintf(xl('Patients with a %1$s code (%2$s) only where the measure does not read it'), $kinds[$kind], $read['measure'])); ?></td>
                <td class="num"><?php echo text($n($patients)); ?></td>
                <td class="small"><?php echo text(sprintf(
                    xl('The measure reads these codes (LOINC %1$s) only as observations of type %2$s. These patients had one in the year only as a lab result or as an observation of another type. Record it in the Observation form with that type.'),
                    implode(', ', $read['codes']),
                    $typeTitles[$read['type']],
                )); ?></td>
            </tr>
            <?php }
            $endRows = [
                [$openEnds['problems'], xl('Ongoing problems (no end date)'), $problems['noEndDate'], $problems['problems'],
                    xl('This OpenEMR treats a missing end date as unknown, so an ongoing problem never overlaps the year (openemr/openemr issue 14526). Do not add end dates to ongoing problems to work around it: an end date marks a problem resolved.')],
                [$openEnds['visits'], xl('Visits with no end time'), $visits['noEndTime'], $visits['visits'],
                    xl('This OpenEMR treats a missing end time as unknown, so a visit with none is never during the year (openemr/openemr issue 14526). The visit screen sets no end time, so there is nothing to fix in the records.')],
            ];
            if (is_array($labs['rejected']) && $labs['rejected'] !== []) {
                $listed = [];
                foreach (array_slice($labs['rejected'], 0, 12, true) as $unit => $c) {
                    $listed[] = sprintf(xl('%1$s (%2$s results%3$s)'), $unit, $n($c['results']), isset(EcqmChecks::UCUM[$unit]) ? '; ' . sprintf(xl('UCUM: %s'), EcqmChecks::UCUM[$unit]) : '');
                } ?>
            <tr class="table-warning">
                <td><?php echo xlt('Patients with a lab result in a unit the engine refuses'); ?></td>
                <td class="num"><?php echo text($n((int)$labs['rejectedPatients']) . ' / ' . $n($labs['patients'])); ?></td>
                <td class="small">
                    <?php echo xlt('The eCQM engine in OpenEMR accepts only UCUM units. One lab result in any other unit, from any year, makes it refuse the patient for every measure. Tables 6B and 7 then leave the patient out and list them. Correct the unit on the existing results, and have the lab interface send UCUM.'); ?>
                    <div class="mt-1"><?php echo text(implode('; ', $listed) . (count($labs['rejected']) > 12 ? '; …' : '')); ?></div>
                </td>
            </tr>
            <?php }
            if ($decimalsKept === false) { ?>
            <tr class="table-warning">
                <td><?php echo xlt('Patients with an HbA1c between 9.0 and 10.0 in the year'); ?></td>
                <td class="num"><?php echo text($n($labs['a1cNineToTen'])); ?></td>
                <td class="small"><?php echo xlt('This OpenEMR reads lab results as whole numbers, so an HbA1c of 9.4 reaches the diabetes measure (CMS122, Table 7) as 9, which is not over 9.0: these patients are not counted as poorly controlled. Nothing to fix in the records; the count is how many patients the measure can miss.'); ?></td>
            </tr>
            <?php }
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

    <?php if ($wrongRows !== []) { ?>
    <h2 class="h5 uds-section"><?php echo xlt('Records the measures count that OpenEMR treats as deleted or wrong'); ?></h2>
    <p class="text-muted small"><?php echo xlt('Asked of the eCQM builder in this OpenEMR: a row goes away once it leaves these records out. Counted: the records of the patients seen at countable visits, from any year, since the measures read the whole record; problems are those active in the year.'); ?></p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Records'); ?></th><th class="num"><?php echo xlt('Count'); ?></th><th class="num"><?php echo xlt('Patients'); ?></th><th><?php echo xlt('Why, and how to fix'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($wrongRows as $kind => [$label, $why]) { ?>
            <tr class="table-warning">
                <td><?php echo text($label); ?></td>
                <td class="num"><?php echo text($n($wrong[$kind]['records'])); ?></td>
                <td class="num"><?php echo text($n($wrong[$kind]['patients'])); ?></td>
                <td class="small"><?php echo text($why); ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>

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
