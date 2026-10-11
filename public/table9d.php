<?php

/**
 * UDS Report: a worksheet for Table 9D, Accrued Patient Service Revenue, from
 * OpenEMR's accounts receivable, as a page or (format=csv) a download.
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
use OpenEMR\Modules\UdsReport\Enum\Table9DLine;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\Money;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

// The permission OpenEMR's own Collections and Receipts by Payment Method reports require.
if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for acct/rep_a: UDS Table 9D worksheet', xl('UDS Report'));
}
$isAdmin = AclMain::aclCheckCore('admin', 'super');
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop($isAdmin);

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(CurrentRequest::get()->query->getString('year'), $now);
$report = new UdsReportService($year, (new SettingsRepository())->load(), $reference, new UdsPatientRepository($reference->insuranceCategories));
$sheet = $report->table9D()->result();
$extra = $report->table9DNotes();
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

// Rows that hold money no line can take are shown only when they hold some.
$rows = array_filter(
    Table9DLine::cases(),
    static fn(Table9DLine $l): bool => $l->isLine() || array_filter($sheet['lines'][$l->value]) !== [],
);
$columns = [
    'billedFirst' => xl('Charges by insurance at the visit, before reclassification'),
    'charges' => xl('Charges') . ' (A)',
    'collections' => xl('Collections') . ' (B)',
    'adjustments' => xl('Adjustments') . ' (D)',
    'unclassified' => xl('Adjustments not classified'),
    'net' => xl('Net Patient Service Revenue') . ' (G)',
];

$notes = [];
$unmapped = $sheet['lines'][Table9DLine::Unmapped->value];
if (array_filter($unmapped) !== []) {
    $notes[] = sprintf(
        xl('Insurers not mapped to a UDS category account for %s of charges and %s of collections. Map them in Settings and reload.'),
        Money::format($unmapped['charges']),
        Money::format($unmapped['collections']),
    );
}
if (array_filter($sheet['lines'][Table9DLine::Uninsured->value]) !== []) {
    $notes[] = xl('Some money is from insurers mapped to None/Uninsured for Table 4. A program that pays for services without being insurance, such as Title X, is Other Public (Line 9) on Table 9D (page 161).');
}
if ($sheet['total']['unclassified'] !== 0) {
    $notes[] = sprintf(
        xl('Adjustments of %s have a reason with no suggested treatment. Decide for each reason below whether it is Column D, bad debt (Line 15), or a correction to collections.'),
        Money::format($sheet['total']['unclassified']),
    );
}
if ($sheet['creditBalances']['encounters'] > 0) {
    $notes[] = sprintf(
        xl('Visits paid more than was charged: %d, with credits of %s in all. Each credit is shown as a negative charge on the line that owes the balance now. Refunds reduce collections (page 168).'),
        $sheet['creditBalances']['encounters'],
        Money::format($sheet['creditBalances']['cents']),
    );
}
if ($extra['copayLines']['count'] > 0) {
    $notes[] = sprintf(
        xl('Old COPAY lines on the fee sheets of visits in the year: %d, totalling %s. Early OpenEMR versions recorded payments this way, as negative charges. They are not counted here.'),
        $extra['copayLines']['count'],
        Money::format($extra['copayLines']['cents']),
    );
}
if ($extra['unapplied']['count'] > 0) {
    $notes[] = sprintf(
        xl('Payments received in the year and not yet applied to any visit (prepayments, or checks not fully posted): %d, totalling %s. They are not in Column B.'),
        $extra['unapplied']['count'],
        Money::format($extra['unapplied']['cents']),
    );
}
if ($extra['productSales']['count'] > 0 || $sheet['products']['collections'] !== 0) {
    $notes[] = sprintf(
        xl('Product sales from inventory in the year: %d, totalling %s, with %s collected. Pharmaceuticals belong on Line 17 as net revenue, and supplies and equipment such as eyeglasses in Column A (pages 164-165). OpenEMR does not tell them apart, so none of this is in the lines above.'),
        $extra['productSales']['count'],
        Money::format($extra['productSales']['cents']),
        Money::format($sheet['products']['collections']),
    );
}
$method = [
    xl('Charges are the fee sheet amounts of visits dated in the year at in-scope sites. Each payer is charged what it settled (the payments and adjustments posted for it, up to today), and the balance still open goes to whoever owes it now: the next insurer, or the patient once the last insurer is closed. That is the reclassification FAQ 4 asks for (page 169), so Self-Pay includes the copays, deductibles and remaining balances of insured patients.'),
    xl('Collections and adjustments are those posted in the year, for visits of any year (FAQ 6, page 169). A posting is dated by the date entered with it, else its batch Post To Date, else the day it was saved. The Receipts by Payment Method report uses deposit dates, so its totals can differ near the start and end of the year.'),
    xl('Adjustments are sorted by the reason posted with them (data/table-9d-adjustments.json). Bad debt is shown by the direct write-off method; if your audit uses an allowance for doubtful accounts, report its change from the general ledger instead (page 163).'),
    xl('Not in OpenEMR: capitation, cost report settlements, wrap payments, incentive revenue (Line 18) and contract pharmacy revenue (Line 17) are usually in the general ledger (FAQ 8, page 169).'),
];

if (CurrentRequest::get()->query->getString('format') === 'csv') {
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Cannot open the output stream');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uds-' . $year . '-table-9d-worksheet.csv"');
    header('Cache-Control: no-store');
    CsvRow::write($out, ['UDS Table 9D worksheet', (string)$year, 'generated ' . $now->format('Y-m-d H:i'), 'An input for Table 9D, not the table']);
    CsvRow::write($out, []);
    CsvRow::write($out, ['Line', 'Payer', ...array_values($columns)]);
    foreach ($rows as $line) {
        CsvRow::write($out, [$line->isLine() ? $line->value : '', $line->label(), ...array_map(Money::plain(...), array_values($sheet['lines'][$line->value]))]);
    }
    CsvRow::write($out, ['14', 'Total', ...array_map(Money::plain(...), array_values($sheet['total']))]);
    CsvRow::write($out, ['15', 'Bad Debt Write-Offs (direct write-off method)', '', '', '', '', '', Money::plain($sheet['badDebt'])]);
    CsvRow::write($out, ['16', 'Net Patient Service Revenue Before Other Patient Service Revenue', '', '', '', '', '', Money::plain($sheet['netBeforeOther'])]);
    CsvRow::write($out, []);
    CsvRow::write($out, ['Adjustments by reason', 'Line', 'Reason', 'Suggested', 'Postings', 'Amount', 'Note']);
    foreach ($sheet['reasons'] as $r) {
        CsvRow::write($out, ['', $r['line']->isLine() ? $r['line']->value : $r['line']->label(), $r['reason'], $r['class']->label(), $r['postings'], Money::plain($r['cents']), $r['note'] ?? '']);
    }
    CsvRow::write($out, []);
    foreach ([...$notes, ...$method] as $note) {
        CsvRow::write($out, ['Note', $note]);
    }
    fclose($out);
    return;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS Table 9D worksheet'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .uds-table td.num, .uds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .uds-table .subtotal td { font-weight: 600; background: var(--light, #f8f9fa); }
        .uds-table .ledger td { color: var(--gray, #6c757d); }
        .uds-section { margin-top: 2rem; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between">
        <h1 class="h4 mb-2"><?php echo xlt('UDS Table 9D worksheet'); ?>: <?php echo text((string)$year); ?></h1>
        <form method="get" class="form-inline mb-2">
            <label class="mr-2" for="uds-year"><?php echo xlt('Calendar year'); ?></label>
            <select class="form-control form-control-sm mr-2" id="uds-year" name="year" onchange="this.form.submit()">
                <?php for ($y = (int)$now->format('Y'); $y >= ReportYear::EARLIEST; $y--) { ?>
                    <option value="<?php echo attr((string)$y); ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                <?php } ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo attr($moduleUrl . '/public/table9d.php?year=' . $year . '&format=csv'); ?>"><?php echo xlt('Download CSV'); ?></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . '/public/index.php?year=' . $year); ?>"><?php echo xlt('UDS Report'); ?></a>
        </form>
    </div>
    <div class="alert alert-info small">
        <?php echo xlt('An input for whoever prepares Table 9D, not the table itself. Table 9D follows the audited financial statements of the health center, and part of it comes from the general ledger. Check every figure against your accounting before entering it in the EHBs.'); ?>
    </div>
    <?php if ($notes !== []) { ?>
        <div class="alert alert-warning small">
            <ul class="mb-0 pl-3">
                <?php foreach ($notes as $note) { ?>
                    <li><?php echo text($note); ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <h2 class="h5 uds-section"><?php echo xlt('Table 9D: Accrued Patient Service Revenue'); ?></h2>
    <p class="text-muted small"><?php echo text(sprintf(xl('Visits with charges in the year: %d.'), $sheet['encounters'])); ?></p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Payer Category'); ?></th>
                <?php foreach ($columns as $label) { ?>
                    <th class="num"><?php echo text($label); ?></th>
                <?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $line) { ?>
                <tr<?php echo $line->isLine() ? '' : ' class="table-warning"'; ?>>
                    <td><?php echo text($line->isLine() ? $line->value : ''); ?></td>
                    <td><?php echo text($line->label()); ?></td>
                    <?php foreach (array_keys($columns) as $c) { ?>
                        <td class="num"><?php echo text(Money::format($sheet['lines'][$line->value][$c])); ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            <tr class="subtotal"><td>14</td><td><?php echo xlt('Total'); ?></td>
                <?php foreach (array_keys($columns) as $c) { ?>
                    <td class="num"><?php echo text(Money::format($sheet['total'][$c])); ?></td>
                <?php } ?>
            </tr>
            <tr><td>15</td><td><?php echo xlt('Bad Debt Write-Offs and Allowances (direct write-offs posted in the year)'); ?></td>
                <td colspan="5"></td><td class="num"><?php echo text(Money::format($sheet['badDebt'])); ?></td></tr>
            <tr class="subtotal"><td>16</td><td><?php echo xlt('Net Patient Service Revenue Before Other Patient Service Revenue'); ?></td>
                <td colspan="5"></td><td class="num"><?php echo text(Money::format($sheet['netBeforeOther'])); ?></td></tr>
            <tr class="ledger"><td>17</td><td><?php echo xlt('Pharmacy Net Patient Service Revenue'); ?></td>
                <td colspan="6"><?php echo xlt('From the general ledger'); ?></td></tr>
            <tr class="ledger"><td>18</td><td><?php echo xlt('Third-Party Incentive Revenue'); ?></td>
                <td colspan="6"><?php echo xlt('From the general ledger'); ?></td></tr>
            <tr class="ledger"><td>19</td><td><?php echo xlt('Total Net Patient Service Revenue'); ?></td>
                <td colspan="6"><?php echo xlt('Line 16 plus Lines 17 and 18'); ?></td></tr>
            </tbody>
        </table>
    </div>

    <h2 class="h5 uds-section"><?php echo xlt('Adjustments by reason'); ?></h2>
    <?php if ($sheet['reasons'] === []) { ?>
        <p class="text-muted"><?php echo xlt('No adjustments posted in the year.'); ?></p>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered uds-table">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Line'); ?></th><th><?php echo xlt('Reason'); ?></th><th><?php echo xlt('Suggested'); ?></th>
                <th class="num"><?php echo xlt('Postings'); ?></th><th class="num"><?php echo xlt('Amount'); ?></th><th><?php echo xlt('Why'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sheet['reasons'] as $r) { ?>
                <tr<?php echo $r['class']->inColumnD() ? '' : ' class="table-light"'; ?>>
                    <td><?php echo text($r['line']->isLine() ? $r['line']->value : $r['line']->label()); ?></td>
                    <td><?php echo text($r['reason']); ?></td>
                    <td><?php echo text($r['class']->label()); ?><?php echo $r['matched'] ? '' : ' <span class="badge badge-warning">' . xlt('no rule') . '</span>'; ?></td>
                    <td class="num"><?php echo text(number_format($r['postings'])); ?></td>
                    <td class="num"><?php echo text(Money::format($r['cents'])); ?></td>
                    <td class="small"><?php echo text($r['note'] ?? ''); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>

    <h2 class="h5 uds-section"><?php echo xlt('How the figures are worked out'); ?></h2>
    <ul class="small text-muted">
        <?php foreach ($method as $line) { ?>
            <li><?php echo text($line); ?></li>
        <?php } ?>
    </ul>
</div>
</body>
</html>
