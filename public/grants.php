<?php

/**
 * UDS Report: grant evidence. The counts grant applications and reports ask for,
 * from the UDS Report's patients: who the health center serves, and social-needs
 * screening by ZIP, age and insurance. De-identified: counts only, with counts of
 * 1 to 10 hidden (SmallCells). ?format=csv downloads the same figures.
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
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\GrantEvidence;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\SmallCells;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS grant evidence', xl('UDS Report'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop(AclMain::aclCheckCore('admin', 'super'));

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(Request::get()->query->getString('year'), $now);
$grant = GrantReport::tryFrom(Request::get()->query->getString('grant'));
$query = 'year=' . $year . ($grant === null ? '' : '&grant=' . $grant->value);
$report = new UdsReportService($year, (new SettingsRepository())->load(), $reference, new UdsPatientRepository($reference->insuranceCategories), $grant);
$evidence = $report->grantEvidence();
$who = $evidence->whoWeServe();
$total = $evidence->total();
$counted = $evidence->socialNeedsCounted();
$povertyAvailable = $report->tables()->table4()['povertyTableAvailable'];

$income = Labels::table4Income();
$labels = [
    'age' => ['0-17' => xl('Children (0 to 17)'), '18-64' => xl('Adults (18 to 64)'), '65+' => xl('Older adults (65 and over)'), 'unknown' => xl('Age unknown')],
    'sex' => ['female' => xl('Female'), 'male' => xl('Male'), 'unknown' => xl('Sex at birth unknown')],
    'insurance' => ['none' => xl('Uninsured'), 'public' => xl('Medicaid, CHIP or other public'), 'medicare' => xl('Medicare'), 'private' => xl('Private'), 'unknown' => xl('Insurance unknown')],
    'income' => ['under100' => $income['1'], '101to150' => $income['2'], '151to200' => $income['3'], 'over200' => $income['4'], 'unknown' => $income['5']],
    'special' => ['homeless' => xl('Homeless'), 'agricultural' => xl('Migratory or seasonal agricultural workers'), 'veterans' => xl('Veterans')],
];
$titles = [
    'age' => xl('Age at the end of the year'), 'sex' => xl('Sex at birth'), 'insurance' => xl('Insurance at the last visit'),
    'income' => xl('Income as a share of the federal poverty guideline'), 'zip' => xl('ZIP code of residence'),
];
$needs = ['39' => xl('Screened for social needs'), '40' => xl('Food insecurity services'), '41' => xl('Housing instability services'), '42' => xl('Financial insecurity services')];
$groupLabel = static function (string $by, int|string $group) use ($labels): string {
    if ($by === 'zip') {
        return match ((string)$group) {
            GrantEvidence::OTHER_ZIPS => xl('Other ZIP codes (fewer than 11 patients each)'),
            GrantEvidence::UNKNOWN => xl('Unknown residence'),
            default => (string)$group,
        };
    }
    return $labels[$by][(string)$group] ?? (string)$group;
};
$show = static fn(?int $v): string => $v === null ? '<11' : number_format($v);
$pct = static fn(?int $p): string => $p === null ? '' : $p . '%';

// The same figures as rows: section, breakdown, group, measure, count, percent.
$rows = [];
foreach (['age', 'sex', 'insurance', 'income', 'zip'] as $by) {
    foreach ($who[$by] as $group => $count) {
        $rows[] = [xl('Who we serve'), $titles[$by], $groupLabel($by, $group), xl('Patients'), $show($count), $pct(SmallCells::percent($count, $total))];
    }
}
foreach ($who['special'] as $key => $count) {
    $rows[] = [xl('Who we serve'), xl('Special populations'), $labels['special'][$key], xl('Patients'), $show($count), $pct(SmallCells::percent($count, $total))];
}
$rows[] = [xl('Who we serve'), xl('Language'), xl('Best served in a language other than English'), xl('Patients'), $show($who['language']), $pct(SmallCells::percent($who['language'], $total))];
$needTables = [];
if ($counted) {
    foreach (['all' => null, 'zip' => 'zip', 'age' => 'age', 'insurance' => 'insurance'] as $key => $by) {
        $needTables[$key] = $evidence->socialNeeds($by);
        foreach ($needTables[$key] as $group => $cells) {
            foreach ($needs as $line => $label) {
                if (!array_key_exists($line, $cells)) {
                    continue;
                }
                $rows[] = [
                    xl('Social needs'),
                    $by === null ? xl('All patients') : $titles[$by],
                    $by === null ? xl('All patients') : $groupLabel($by, $group),
                    $label,
                    $show($cells[$line]),
                    $pct(SmallCells::percent($cells[$line], $cells['patients'])),
                ];
            }
        }
    }
}

if (Request::get()->query->getString('format') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uds-' . $year . ($grant === null ? '' : '-' . $grant->value) . '-grant-evidence.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    CsvRow::write($out, ['UDS grant evidence', (string)$year, 'generated ' . $now->format('Y-m-d H:i'), Labels::report($grant), 'counts of 1 to 10 shown as <11']);
    CsvRow::write($out, ['Section', 'Breakdown', 'Group', 'Measure', 'Count', 'Percent']);
    CsvRow::write($out, [xl('Who we serve'), xl('All patients'), xl('All patients'), xl('Patients'), $show($total), '']);
    foreach ($rows as $row) {
        CsvRow::write($out, $row);
    }
    fclose($out);
    exit;
}

$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <title><?php echo xlt('UDS grant evidence'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-section { margin-top: 2rem; }
    </style>
    <style>/* Links in running text are underlined, not told apart by colour alone (WCAG 1.4.1). */ p a, li a { text-decoration: underline; }</style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS grant evidence'); ?>: <?php echo text((string)$year); ?> <small class="text-muted"><?php echo text(Labels::report($grant)); ?></small></h1>
        <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
        <form method="get" class="form-inline mb-2">
            <label class="mr-2" for="uds-year"><?php echo xlt('Calendar year'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-year" name="year" onchange="this.form.submit()">
                <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                    <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                <?php } ?>
            </select>
            <select class="form-control form-control-sm mr-2" name="grant" onchange="this.form.submit()" aria-label="<?php echo xla('Population'); ?>">
                <option value=""><?php echo text(Labels::report(null)); ?></option>
                <?php foreach (GrantReport::cases() as $g) { ?>
                    <option value="<?php echo attr($g->value); ?>" <?php echo $g === $grant ? 'selected' : ''; ?>><?php echo text(Labels::report($g)); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/grants.php?' . $query . '&format=csv'); ?>"><?php echo xlt('Download CSV'); ?></a>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/index.php?' . $query); ?>"><?php echo xlt('UDS Report'); ?></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . '/public/quality.php?year=' . $year); ?>"><?php echo xlt('Tables 6B and 7'); ?></a>
        </form>
    </div>
    <div class="alert alert-info small">
        <?php echo xlt('Counts for grant applications and reports, from the same patients as the UDS Report. They are de-identified: counts only, with any count from 1 to 10 shown as <11, and one more count hidden where a total would give a hidden one away. Check them against the notice you are applying to.'); ?>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Who we serve'); ?></h2>
    <p><?php echo text(sprintf(xl('Patients seen in %1$s: %2$s.'), (string)$year, $show($total))); ?></p>
    <div class="row">
        <?php foreach (['age', 'sex', 'insurance', 'income', 'zip'] as $by) { ?>
        <div class="col-lg-6">
            <table class="table table-sm table-bordered uds-table">
                <thead class="thead-light"><tr><th><?php echo text($titles[$by]); ?></th><th class="num"><?php echo xlt('Patients'); ?></th><th class="num"><?php echo xlt('Share'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($who[$by] as $group => $count) {
                    if ($count === 0 && in_array((string)$group, [GrantEvidence::UNKNOWN, GrantEvidence::OTHER_ZIPS], true)) {
                        continue;
                    } ?>
                    <tr><td><?php echo text($groupLabel($by, $group)); ?></td><td class="num"><?php echo text($show($count)); ?></td><td class="num"><?php echo text($pct(SmallCells::percent($count, $total))); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php if ($by === 'income' && !$povertyAvailable) { ?>
                <p class="small text-muted"><?php echo xlt('This year has no poverty guideline in the module, so income is counted as unknown.'); ?></p>
            <?php } ?>
        </div>
        <?php } ?>
        <div class="col-lg-6">
            <table class="table table-sm table-bordered uds-table">
                <thead class="thead-light"><tr><th><?php echo xlt('Special populations and language'); ?></th><th class="num"><?php echo xlt('Patients'); ?></th><th class="num"><?php echo xlt('Share'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($who['special'] as $key => $count) { ?>
                    <tr><td><?php echo text($labels['special'][$key]); ?></td><td class="num"><?php echo text($show($count)); ?></td><td class="num"><?php echo text($pct(SmallCells::percent($count, $total))); ?></td></tr>
                <?php } ?>
                <tr><td><?php echo xlt('Best served in a language other than English'); ?></td><td class="num"><?php echo text($show($who['language'])); ?></td><td class="num"><?php echo text($pct(SmallCells::percent($who['language'], $total))); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Social needs: screening and services'); ?> <small class="text-muted"><?php echo xlt('Table 6A, Lines 39 to 42'); ?></small></h2>
    <?php if (!$counted) { ?>
        <div class="alert alert-warning small">
            <?php echo xlt('Not counted yet: Table 6A defines these lines by value sets, and this site has not fetched them.'); ?>
            <a href="<?php echo attr($moduleUrl . '/public/valuesets.php'); ?>"><?php echo xlt('Fetch the value sets'); ?></a>
        </div>
    <?php } else { ?>
        <p class="small text-muted"><?php echo xlt('Patients screened for social needs, and patients given services for food insecurity, housing instability or financial insecurity, as Table 6A counts them, each as a share of all patients. A screening counts when it is coded: a screening code on the fee sheet, or a questionnaire answered with its LOINC code. A service counts when it is coded on the fee sheet or in a completed care plan entry. Table 6A does not count positive screens or diagnoses such as the Z59 codes on these lines.'); ?></p>
        <?php foreach (['all' => xl('All patients'), 'zip' => $titles['zip'], 'age' => $titles['age'], 'insurance' => $titles['insurance']] as $key => $heading) { ?>
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo text($heading); ?></th><th class="num"><?php echo xlt('Patients'); ?></th>
                <?php foreach ($needs as $line => $label) { ?><th class="num"><?php echo text($label); ?></th><?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($needTables[$key] as $group => $cells) { ?>
                <tr>
                    <td><?php echo text($key === 'all' ? xl('All patients') : $groupLabel($key, $group)); ?></td>
                    <td class="num"><?php echo text($show($cells['patients'])); ?></td>
                    <?php foreach ($needs as $line => $label) {
                        $value = $cells[$line] ?? null;
                        $share = SmallCells::percent($value, $cells['patients']); ?>
                        <td class="num"><?php echo array_key_exists($line, $cells) ? text($show($value) . ($share === null ? '' : ' (' . $share . '%)')) : '—'; ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    <?php } ?>

    <h2 class="h5 uds-section"><?php echo xlt('Quality measures'); ?></h2>
    <p class="small">
        <?php echo xlt('Blood pressure and diabetes control, screenings and the other eCQM rates are on the Tables 6B and 7 page. Check eCQM readiness first: it lists the records the measures cannot see.'); ?>
        <a href="<?php echo attr($moduleUrl . '/public/quality.php?year=' . $year); ?>"><?php echo xlt('Tables 6B and 7'); ?></a> ·
        <a href="<?php echo attr($moduleUrl . '/public/ecqm.php?year=' . $year); ?>"><?php echo xlt('eCQM readiness'); ?></a>
    </p>
</div>
</body>
</html>
