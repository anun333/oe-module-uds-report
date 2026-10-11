<?php

/**
 * CSV download of the UDS demographic tables (aggregate counts only, no patient data).
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
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Report\UdsReportService;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: UDS export', xl('UDS Report'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))
    ->loadOrStop(AclMain::aclCheckCore('admin', 'super'));

$now = ServiceContainer::getClock()->now();
$year = ReportYear::fromQuery(CurrentRequest::get()->query->getString('year'), $now);
$report = new UdsReportService($year, (new SettingsRepository())->load(), $reference, new UdsPatientRepository($reference->insuranceCategories));
$tables = $report->tables();

$out = fopen('php://output', 'w');
if ($out === false) {
    throw new RuntimeException('Cannot open the output stream');
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="uds-' . $year . '-tables.csv"');
header('Cache-Control: no-store');

CsvRow::write($out, ['UDS demographic tables', (string)$year, 'generated ' . $now->format('Y-m-d H:i')]);
CsvRow::write($out, []);
CsvRow::write($out, ['Patients by ZIP Code', 'None/Uninsured (B)', 'Medicaid/CHIP/Other Public (C)', 'Medicare (D)', 'Private (E)', 'Total Patients (F)']);
foreach ($tables->zipTable()['rows'] as $label => $r) {
    CsvRow::write($out, [(string)$label, $r['none'], $r['public'], $r['medicare'], $r['private'], $r['total']]);
}
CsvRow::write($out, []);
$t3a = $tables->table3A();
CsvRow::write($out, ['Table 3A', 'Line', 'Age Group', 'Male (A)', 'Female (B)']);
foreach ($t3a['lines'] as $line => $r) {
    CsvRow::write($out, ['', $line, $r['label'], $r['male'], $r['female']]);
}
CsvRow::write($out, ['', 39, 'Total Patients', $t3a['totalMale'], $t3a['totalFemale']]);
CsvRow::write($out, []);
$t3b = $tables->table3B();
$cols = array_keys(Labels::ethnicityColumns());
CsvRow::write($out, ['Table 3B', 'Line', 'Race', ...$cols]);
foreach (Labels::raceLines() as $line => $label) {
    CsvRow::write($out, ['', $line, $label, ...array_map(static fn(string $c): int => $t3b['cells'][$line][$c] ?? 0, $cols)]);
}
CsvRow::write($out, ['', 12, 'Patients Best Served in a Language Other Than English', $t3b['line12']]);
CsvRow::write($out, []);
$t4 = $tables->table4();
CsvRow::write($out, ['Table 4', 'Line', 'Income as Percentage of Poverty Guideline', 'Patients (A)']);
foreach (Labels::table4Income() as $line => $label) {
    CsvRow::write($out, ['', $line, $label, $t4['income'][$line] ?? 0]);
}
CsvRow::write($out, ['', 'Line', 'Primary Third-Party Medical Insurance', '0-17 (A)', '18+ (B)']);
foreach (Labels::table4Insurance() as $line => $label) {
    CsvRow::write($out, ['', $line, $label, $t4['insurance'][$line]['child'] ?? 0, $t4['insurance'][$line]['adult'] ?? 0]);
}
CsvRow::write($out, ['', 'Line', 'Special Medically Underserved Populations', 'Patients (A)']);
foreach (Labels::table4Special() as $line => $label) {
    CsvRow::write($out, ['', $line, $label, $t4['special'][$line] ?? 0]);
}
CsvRow::write($out, []);
$t5 = $report->table5()->result();
CsvRow::write($out, ['Table 5', 'Line', 'Personnel by Major Service Category', 'FTEs (A)', 'Clinic Visits (B)', 'Virtual Visits (B2)', 'Patients (C)']);
foreach ($t5['rows'] as $row) {
    CsvRow::write($out, ['', $row['line']->line, $row['line']->label, '', $row['visits'] ?? '', $row['virtual'] ?? '', $row['patients'] ?? '']);
}
CsvRow::write($out, ['', '', 'FTEs come from HR records and are not computed. Encounters not counted: ' . $t5['notCounted']
    . '; UDS patients with no Table 5 visit: ' . count($t5['patientsWithoutVisit'])]);
CsvRow::write($out, []);
CsvRow::write($out, ['Table 6A', 'Line', 'Diagnosis or Service', 'Visits (A)', 'Patients (B)', 'Counted']);
foreach ($report->table6A()->rows() as $row) {
    CsvRow::write($out, [
        '',
        $row['line']->line,
        $row['line']->label,
        $row['visits'] ?? '',
        $row['patients'] ?? '',
        $row['line']->computed . ($row['line']->limit === null ? '' : ': ' . $row['line']->limit),
    ]);
}
$check = $tables->crossCheck();
CsvRow::write($out, []);
CsvRow::write($out, ['Cross-table check', $check['consistent'] ? 'totals agree' : 'TOTALS DO NOT AGREE', 'patients ' . $check['patients']]);
fclose($out);
