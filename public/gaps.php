<?php

/**
 * The patients behind one UDS record problem, each opening the chart.
 *
 * Lists many patients' records at once, so viewing it is written to the audit log.
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
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Page;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\Row;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS record gaps', xl('UDS Report'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))
    ->loadOrStop(AclMain::aclCheckCore('admin', 'super'));

$request = Request::get();
$year = ReportYear::fromQuery($request->query->getString('year'), ServiceContainer::getClock()->now());
$gap = DataGap::tryFrom($request->query->getString('gap')) ?? DataGap::NoBirthSex;
$grant = GrantReport::tryFrom($request->query->getString('grant'));

$report = new UdsReportService($year, (new SettingsRepository())->load(), $reference, new UdsPatientRepository($reference->insuranceCategories), $grant);
$pids = $report->gaps()[$gap->value] ?? [];

$rows = [];
foreach (array_chunk($pids, 500) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    array_push($rows, ...QueryUtils::fetchRecords(
        "SELECT pid, pubpid, fname, lname, DOB FROM patient_data WHERE pid IN ($placeholders) ORDER BY lname, fname",
        $chunk,
    ));
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$user = $session->get('authUser');
$group = $session->get('authProvider');
EventAuditLogger::getInstance()->newEvent(
    'patient-record-select',
    is_string($user) ? $user : '',
    is_string($group) ? $group : '',
    1,
    'UDS record gaps viewed (' . $year . ', ' . $gap->value . '), ' . count($pids) . ' patients, pids: ' . implode(',', $pids),
);

$webRoot = OpenEmr::webRoot();
$moduleUrl = $webRoot . Bootstrap::MODULE_INSTALLATION_PATH;
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('UDS record gaps'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenEMR\Modules\UdsReport\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <a href="<?php echo attr($moduleUrl . '/public/index.php?year=' . $year . ($grant === null ? '' : '&grant=' . $grant->value)); ?>">&larr; <?php echo xlt('UDS Report'); ?> <?php echo text((string)$year); ?>, <?php echo text(Labels::report($grant)); ?></a>
    <h1 class="h5 mt-2"><?php echo text(Labels::gap($gap)); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p class="text-muted"><?php echo text((string)count($pids)); ?> <?php echo xlt('patients with countable visits in'); ?> <?php echo text((string)$year); ?>.
        <?php echo $gap->isDemographic()
            ? xlt('Open a patient to fix the record in Demographics; the report reflects changes on reload.')
            : xlt('Open a patient to fix the record as described above; the report reflects changes on reload.'); ?></p>
    <table class="table table-sm table-hover" style="max-width: 48rem">
        <thead class="thead-light"><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('DOB'); ?></th><th><?php echo xlt('ID'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row) { ?>
            <tr>
                <td><a href="#" class="js-open-chart" data-pid="<?php echo attr(Row::text($row, 'pid')); ?>"><?php echo text(trim(Row::text($row, 'lname') . ', ' . Row::text($row, 'fname'), ', ')); ?></a></td>
                <td><?php $dob = oeFormatShortDate(Row::text($row, 'DOB'));
                echo text(is_string($dob) ? $dob : ''); ?></td>
                <td><?php echo text(Row::text($row, 'pubpid')); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<script>
    document.addEventListener('click', function (event) {
        const link = event.target.closest('.js-open-chart');
        if (!link) {
            return;
        }
        event.preventDefault();
        top.restoreSession();
        top.RTop.location = <?php echo js_escape($webRoot); ?> +
            '/interface/patient_file/summary/demographics.php?set_pid=' + encodeURIComponent(link.dataset.pid);
    });
</script>
</body>
</html>
