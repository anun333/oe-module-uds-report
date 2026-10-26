<?php

/**
 * What the eCQM readiness page reads: the year's countable visits, the problems,
 * vitals, History, PHQ-9 forms, observations and lab results of the patients
 * seen at them, OpenEMR's own lists, the measure files installed, and how
 * OpenEMR's QDM builder treats a record with no end date.
 *
 * What OpenEMR's eCQM builder reads (src/Services/Qdm/Services) was measured on
 * OpenEMR master c11dd1b15c with CMS165v13: visits by their Encounter Type code,
 * not billing codes; problems only when classified Diagnosis; blood pressures
 * only as Physical Exam Performed observations, not from the Vitals form. No QDM
 * service reads form_vitals, history_data or form_phq9 (OpenEMR master
 * be104434f7, src/Services/Qdm/Services).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Report\EcqmChecks;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\Qdm\MeasureService;
use OpenEMR\Services\Qdm\QdmRecord;
use OpenEMR\Services\Qdm\QdmRequestSome;
use OpenEMR\Services\Qdm\Services\DiagnosisService;
use OpenEMR\Services\Qdm\Services\EncounterService;
use OpenEMR\Services\Qdm\Services\LaboratoryTestService;
use OpenHealthcare\Common\OpenEmr;

final readonly class EcqmReadinessRepository
{
    public function __construct(private UdsPatientRepository $patients)
    {
    }

    /**
     * The year's countable visits: how many have no Encounter Type code, and how many no end time.
     *
     * @return array{visits: int, untyped: int, noEndTime: int}
     */
    public function visits(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return ['visits' => 0, 'untyped' => 0, 'noEndTime' => 0];
        }
        [$where, $binds] = $filter->where($year);
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS visits,
                    SUM(TRIM(COALESCE(fe.encounter_type_code, '')) = '') AS untyped,
                    SUM(fe.date_end IS NULL OR fe.date_end < '1900-01-01') AS no_end
               FROM form_encounter AS fe
              WHERE $where",
            $binds,
        )[0] ?? [];
        return [
            'visits' => Row::nullableInt($row, 'visits') ?? 0,
            'untyped' => Row::nullableInt($row, 'untyped') ?? 0,
            'noEndTime' => Row::nullableInt($row, 'no_end') ?? 0,
        ];
    }

    /**
     * Active entries of OpenEMR's Encounter Types list, and the titles of those with no code.
     *
     * @return array{active: int, uncoded: list<string>}
     */
    public function encounterTypes(): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT title, codes FROM list_options WHERE list_id = 'encounter-types' AND activity = 1 ORDER BY seq, title",
        );
        $uncoded = [];
        foreach ($rows as $row) {
            if (trim(Row::text($row, 'codes')) === '') {
                $uncoded[] = Row::text($row, 'title');
            }
        }
        return ['active' => count($rows), 'uncoded' => $uncoded];
    }

    /**
     * Problems active in the year of the patients with countable visits.
     *
     * @return array{problems: int, unclassified: int, uncoded: int, noEndDate: int}
     */
    public function problems(int $year, UdsSettings $settings): array
    {
        $zero = ['problems' => 0, 'unclassified' => 0, 'uncoded' => 0, 'noEndDate' => 0];
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return $zero;
        }
        [$where, $binds] = $filter->where($year);
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS problems,
                    SUM(COALESCE(l.subtype, '') <> 'diagnosis') AS unclassified,
                    SUM(TRIM(COALESCE(l.diagnosis, '')) = '') AS uncoded,
                    SUM(l.enddate IS NULL OR l.enddate < '1900-01-01') AS no_end
               FROM lists AS l
              WHERE l.type = 'medical_problem' AND l.activity = 1
                AND (l.begdate IS NULL OR l.begdate < ?)
                AND (l.enddate IS NULL OR l.enddate < '1900-01-01' OR l.enddate >= ?)
                AND l.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
            [sprintf('%04d-01-01', $year + 1), sprintf('%04d-01-01', $year), ...$binds],
        )[0] ?? [];
        return [
            'problems' => Row::nullableInt($row, 'problems') ?? 0,
            'unclassified' => Row::nullableInt($row, 'unclassified') ?? 0,
            'uncoded' => Row::nullableInt($row, 'uncoded') ?? 0,
            'noEndDate' => Row::nullableInt($row, 'no_end') ?? 0,
        ];
    }

    /**
     * Which records OpenEMR's eCQM builder still reads that the rest of OpenEMR treats as deleted
     * or wrong (EcqmChecks::LEFT_OUT_BY), asked of the installed QDM service's own query: true when
     * it leaves them out, false when it reads them, null when it can't be asked.
     *
     * @return array<string, ?bool>
     */
    public static function engineLeavesOut(): array
    {
        $answers = [];
        foreach (EcqmChecks::LEFT_OUT_BY as $kind => ['service' => $class, 'column' => $column]) {
            try {
                if (!class_exists($class) || !class_exists(QdmRequestSome::class)) {
                    $answers[$kind] = null;
                    continue;
                }
                $sql = (new $class(new QdmRequestSome([0]), new CodeTypesService()))->getSqlStatement();
                $answers[$kind] = is_string($sql) ? EcqmChecks::queryNames($sql, $column) : null;
            } catch (\Throwable) {
                $answers[$kind] = null;
            }
        }
        return $answers;
    }

    /**
     * The records of the patients with countable visits that the rest of OpenEMR treats as deleted
     * or wrong and the eCQM builder may still read, by kind (EcqmChecks::LEFT_OUT_BY). Problems
     * are those classified Diagnosis and active in the year, as the measures read them; the rest
     * are from any year, since the engine loads a patient's whole record. A deleted form's records
     * are matched to it by its patient as well as its id.
     *
     * @return array<string, array{records: int, patients: int}>
     */
    public function wrongRecords(int $year, UdsSettings $settings): array
    {
        $counts = array_fill_keys(array_keys(self::WRONG_RECORDS), ['records' => 0, 'patients' => 0]);
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return $counts;
        }
        [$where, $binds] = $filter->where($year);
        $seen = "SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where";
        foreach (self::WRONG_RECORDS as $kind => $sql) {
            $period = $kind === 'problemsInError' ? [sprintf('%04d-01-01', $year + 1), sprintf('%04d-01-01', $year)] : [];
            $row = QueryUtils::fetchRecords(sprintf($sql, $seen), [...$period, ...$binds])[0] ?? [];
            $counts[$kind] = ['records' => Row::nullableInt($row, 'n') ?? 0, 'patients' => Row::nullableInt($row, 'p') ?? 0];
        }
        return $counts;
    }

    /**
     * The count behind each wrongRecords() kind; %s is the patients seen at countable visits.
     * Whole queries, so tools/footprint.php sees every table they read.
     */
    private const WRONG_RECORDS = [
        'problemsInError' => "SELECT COUNT(*) AS n, COUNT(DISTINCT l.pid) AS p FROM lists AS l
            WHERE l.type = 'medical_problem' AND l.subtype = 'diagnosis' AND l.activity = 1
              AND l.verification IN ('entered-in-error', 'refuted')
              AND (l.begdate IS NULL OR l.begdate < ?) AND (l.enddate IS NULL OR l.enddate < '1900-01-01' OR l.enddate >= ?)
              AND l.pid IN (%s)",
        'immunizationsInError' => "SELECT COUNT(*) AS n, COUNT(DISTINCT i.patient_id) AS p FROM immunizations AS i
            WHERE i.added_erroneously = 1 AND i.patient_id IN (%s)",
        'inactivePrescriptions' => "SELECT COUNT(*) AS n, COUNT(DISTINCT p.patient_id) AS p FROM prescriptions AS p
            WHERE p.active = 0 AND p.end_date IS NULL AND p.patient_id IN (%s)",
        'deletedObservations' => "SELECT COUNT(*) AS n, COUNT(DISTINCT o.pid) AS p FROM form_observation AS o
            JOIN forms AS f ON f.form_id = o.form_id AND f.pid = o.pid AND f.formdir = 'observation'
            WHERE f.deleted = 1 AND COALESCE(o.activity, 1) <> 0 AND o.pid IN (%s)",
        'deletedCarePlans' => "SELECT COUNT(*) AS n, COUNT(DISTINCT c.pid) AS p FROM form_care_plan AS c
            JOIN forms AS f ON f.form_id = c.id AND f.pid = c.pid AND f.formdir = 'care_plan'
            WHERE f.deleted = 1 AND c.pid IN (%s)",
        'deletedOrders' => "SELECT COUNT(*) AS n, COUNT(DISTINCT o.patient_id) AS p FROM procedure_order AS o
            JOIN forms AS f ON f.form_id = o.procedure_order_id AND f.pid = o.patient_id AND f.formdir = 'procedure_order'
            WHERE f.deleted = 1 AND o.activity <> 0 AND o.patient_id IN (%s)",
        'labResultsInError' => "SELECT COUNT(*) AS n, COUNT(DISTINCT o.patient_id) AS p FROM procedure_result AS res
            JOIN procedure_report AS rep ON rep.procedure_report_id = res.procedure_report_id
            JOIN procedure_order AS o ON o.procedure_order_id = rep.procedure_order_id
            WHERE o.procedure_order_type = 'laboratory_test' AND o.activity <> 0 AND res.result_status IN ('cancel', 'error')
              AND o.patient_id IN (%s)",
    ];

    /**
     * Whether this OpenEMR's eCQM builder reads vitals from the Vitals form (openemr/openemr
     * #14656, OpenEMR master from 2026-10-18).
     */
    public static function vitalsFormRead(): bool
    {
        return class_exists('OpenEMR\\Services\\Qdm\\Services\\VitalSignsService');
    }

    /** The HbA1c tests CMS122 reads (its "HbA1c Laboratory Test" value set). */
    private const HBA1C = ['LOINC:4548-4', 'LOINC:4549-2', 'LOINC:17856-6', 'LOINC:96595-4'];

    /**
     * The lab results of the patients with countable visits, as OpenEMR's eCQM
     * builder reads them (every year, not only this one: the engine loads a
     * patient's whole record), with the units the engine refuses. The engine
     * accepts only UCUM units and refuses a patient for every measure over one
     * result with any other. Rejected is null when this OpenEMR can't be asked.
     *
     * @return array{results: int, patients: int, rejected: ?array<string, array{results: int, patients: int}>, rejectedPatients: ?int, a1cNineToTen: int}
     */
    public function labResults(int $year, UdsSettings $settings): array
    {
        $zero = ['results' => 0, 'patients' => 0, 'rejected' => [], 'rejectedPatients' => 0, 'a1cNineToTen' => 0];
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return $zero;
        }
        [$where, $binds] = $filter->where($year);
        $from = "FROM procedure_result AS res
                   JOIN procedure_report AS rep ON rep.procedure_report_id = res.procedure_report_id
                   JOIN procedure_order AS o ON o.procedure_order_id = rep.procedure_order_id
                  WHERE o.procedure_order_type = 'laboratory_test' AND o.activity <> 0
                    AND o.patient_id IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)";
        $byUnit = [];
        $results = 0;
        foreach (QueryUtils::fetchRecords("SELECT COALESCE(res.units, '') AS unit, COUNT(*) AS n, COUNT(DISTINCT o.patient_id) AS p $from GROUP BY COALESCE(res.units, '')", $binds) as $row) {
            $byUnit[Row::text($row, 'unit')] = ['results' => Row::nullableInt($row, 'n') ?? 0, 'patients' => Row::nullableInt($row, 'p') ?? 0];
            $results += Row::nullableInt($row, 'n') ?? 0;
        }
        $patients = Row::nullableInt(QueryUtils::fetchRecords("SELECT COUNT(DISTINCT o.patient_id) AS p $from", $binds)[0] ?? [], 'p') ?? 0;
        $units = 'OpenEMR\\Cqm\\Cql\\Util\\Units';
        $rejected = class_exists($units) && method_exists($units, 'checkUnit')
            ? EcqmChecks::rejectedUnits($byUnit, static fn(string $u): bool => (bool)$units::checkUnit($u))
            : null;
        $rejectedPatients = null;
        if ($rejected !== null) {
            $rejectedPatients = 0;
            if ($rejected !== []) {
                $in = implode(', ', array_fill(0, count($rejected), '?'));
                $rejectedPatients = Row::nullableInt(QueryUtils::fetchRecords(
                    "SELECT COUNT(DISTINCT o.patient_id) AS p $from AND COALESCE(res.units, '') IN ($in)",
                    [...$binds, ...array_map('strval', array_keys($rejected))],
                )[0] ?? [], 'p') ?? 0;
            }
        }
        $codes = implode(', ', array_fill(0, count(self::HBA1C), '?'));
        $a1c = Row::nullableInt(QueryUtils::fetchRecords(
            "SELECT COUNT(DISTINCT o.patient_id) AS p $from
                AND res.date >= ? AND res.date < ?
                AND res.result REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(res.result AS DECIMAL(10, 4)) > 9 AND CAST(res.result AS DECIMAL(10, 4)) < 10
                AND EXISTS (SELECT 1 FROM procedure_order_code AS oc WHERE oc.procedure_order_id = o.procedure_order_id AND oc.procedure_code IN ($codes))",
            [...$binds, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1), ...self::HBA1C],
        )[0] ?? [], 'p') ?? 0;
        return ['results' => $results, 'patients' => $patients, 'rejected' => $rejected, 'rejectedPatients' => $rejectedPatients, 'a1cNineToTen' => $a1c];
    }

    /**
     * Whether this OpenEMR's eCQM builder keeps a lab result's decimals: asked of
     * the builder itself with a 9.4, so the answer changes when OpenEMR does. It
     * read results as whole numbers through 2026-10 at least, so an HbA1c of 9.4
     * reached CMS122 as 9. Null when it can't be asked.
     */
    public static function labDecimalsKept(): ?bool
    {
        try {
            if (!class_exists(LaboratoryTestService::class) || !class_exists(QdmRecord::class) || !class_exists(QdmRequestSome::class)) {
                return null;
            }
            $service = new LaboratoryTestService(new QdmRequestSome([0]), new CodeTypesService());
            $model = json_decode(json_encode($service->makeQdmModel(new QdmRecord([
                'pid' => 0, 'encounter' => 0, 'procedure_order_type' => 'laboratory_test', 'date_ordered' => '2026-06-01 09:00:00',
                'procedure_code' => '', 'result_code' => '', 'result' => '9.4', 'units' => '%', 'date' => '2026-06-01 12:00:00', 'date_end' => null,
            ], 0, 0)), JSON_THROW_ON_ERROR), true);
            $value = is_array($model) && is_array($model['result'] ?? null) ? ($model['result']['value'] ?? null) : null;
            return is_numeric($value) ? abs((float)$value - 9.4) < 0.001 : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Patients with countable visits who had a blood pressure taken in the year
     * in the Vitals form, and how many of them have none as an observation the
     * eCQM builder reads (Physical Exam Performed, systolic LOINC 8480-6).
     *
     * @return array{patients: int, vitalsOnly: int}
     */
    public function bloodPressures(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return ['patients' => 0, 'vitalsOnly' => 0];
        }
        [$where, $binds] = $filter->where($year);
        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end = sprintf('%04d-01-01 00:00:00', $year + 1);
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(DISTINCT v.pid) AS patients,
                    COUNT(DISTINCT CASE WHEN NOT EXISTS (
                        SELECT 1 FROM form_observation AS o
                         WHERE o.pid = v.pid AND o.ob_type = 'physical_exam_performed' AND o.code LIKE '%8480-6%'
                           AND o.date >= ? AND o.date < ?
                    ) THEN v.pid END) AS vitals_only
               FROM form_vitals AS v
               JOIN forms AS f ON f.form_id = v.id AND f.formdir = 'vitals' AND f.deleted = 0
              WHERE v.date >= ? AND v.date < ? AND TRIM(COALESCE(v.bps, '')) NOT IN ('', '0')
                AND v.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
            [$start, $end, $start, $end, ...$binds],
        )[0] ?? [];
        return [
            'patients' => Row::nullableInt($row, 'patients') ?? 0,
            'vitalsOnly' => Row::nullableInt($row, 'vitals_only') ?? 0,
        ];
    }

    /**
     * Patients with countable visits who had a BMI in the Vitals form in the
     * year, and how many of them have none as an observation the eCQM builder
     * reads (Physical Exam Performed, LOINC 39156-5).
     *
     * @return array{patients: int, vitalsOnly: int}
     */
    public function bodyMassIndexes(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return ['patients' => 0, 'vitalsOnly' => 0];
        }
        [$where, $binds] = $filter->where($year);
        [$start, $end] = self::yearBounds($year);
        [$observed, $observedBinds] = self::observed('v.pid', 'bmi', $start, $end);
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(DISTINCT v.pid) AS patients,
                    COUNT(DISTINCT CASE WHEN NOT $observed THEN v.pid END) AS vitals_only
               FROM form_vitals AS v
               JOIN forms AS f ON f.form_id = v.id AND f.formdir = 'vitals' AND f.deleted = 0
              WHERE v.date >= ? AND v.date < ? AND v.BMI > 0
                AND v.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
            [...$observedBinds, $start, $end, ...$binds],
        )[0] ?? [];
        return [
            'patients' => Row::nullableInt($row, 'patients') ?? 0,
            'vitalsOnly' => Row::nullableInt($row, 'vitals_only') ?? 0,
        ];
    }

    /**
     * Patients with countable visits whose History, saved in the year, records
     * their tobacco use, and how many of them have no tobacco screening as an
     * observation the eCQM builder reads (Assessment, CMS138's codes).
     *
     * @return array{patients: int, historyOnly: int}
     */
    public function tobaccoInHistory(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return ['patients' => 0, 'historyOnly' => 0];
        }
        [$where, $binds] = $filter->where($year);
        [$start, $end] = self::yearBounds($year);
        $recorded = [];
        foreach (QueryUtils::fetchRecords(
            "SELECT DISTINCT h.pid, h.tobacco FROM history_data AS h
              WHERE h.date >= ? AND h.date < ? AND COALESCE(h.tobacco, '') <> ''
                AND h.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
            [$start, $end, ...$binds],
        ) as $row) {
            if (EcqmChecks::tobaccoRecorded(Row::text($row, 'tobacco'))) {
                $recorded[Row::int($row, 'pid')] = true;
            }
        }
        if ($recorded === []) {
            return ['patients' => 0, 'historyOnly' => 0];
        }
        [$observed, $observedBinds] = self::observed('fe.pid', 'tobacco', $start, $end);
        $screened = [];
        foreach (QueryUtils::fetchRecords(
            "SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE ($where) AND $observed",
            [...$binds, ...$observedBinds],
        ) as $row) {
            $screened[Row::int($row, 'pid')] = true;
        }
        return ['patients' => count($recorded), 'historyOnly' => count(array_diff_key($recorded, $screened))];
    }

    /**
     * Patients with countable visits who had a PHQ-9 form in the year, and how
     * many of them have no depression screening as an observation the eCQM
     * builder reads (Assessment, LOINC 73831-0 or 73832-8). Null when the PHQ-9
     * form isn't installed: OpenEMR creates its table when it is.
     *
     * @return ?array{patients: int, formOnly: int}
     */
    public function depressionForms(int $year, UdsSettings $settings): ?array
    {
        $exists = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'form_phq9'",
        )[0] ?? [];
        if ((Row::nullableInt($exists, 'n') ?? 0) === 0) {
            return null;
        }
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return ['patients' => 0, 'formOnly' => 0];
        }
        [$where, $binds] = $filter->where($year);
        [$start, $end] = self::yearBounds($year);
        [$observed, $observedBinds] = self::observed('p.pid', 'depression', $start, $end);
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(DISTINCT p.pid) AS patients,
                    COUNT(DISTINCT CASE WHEN NOT $observed THEN p.pid END) AS form_only
               FROM form_phq9 AS p
               JOIN forms AS f ON f.form_id = p.id AND f.formdir = 'phq9' AND f.deleted = 0
              WHERE p.date >= ? AND p.date < ?
                AND p.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
            [...$observedBinds, $start, $end, ...$binds],
        )[0] ?? [];
        return [
            'patients' => Row::nullableInt($row, 'patients') ?? 0,
            'formOnly' => Row::nullableInt($row, 'form_only') ?? 0,
        ];
    }

    /**
     * For each kind of record in EcqmChecks::OBSERVATION_CODES, the patients
     * with countable visits who had one of its codes in the year only where the
     * eCQM builder doesn't read it for the measure: an active observation of
     * another type, or a lab result (by result code or order code).
     *
     * @return array<string, int> by kind, only kinds with any
     */
    public function misplacedCodes(int $year, UdsSettings $settings): array
    {
        $filter = CountableVisitFilter::fromSettings($settings, $this->patients);
        if ($filter->matchesNothing()) {
            return [];
        }
        [$where, $binds] = $filter->where($year);
        [$start, $end] = self::yearBounds($year);
        $out = [];
        foreach (EcqmChecks::OBSERVATION_CODES as $kind => $read) {
            $codes = $read['codes'];
            $in = implode(', ', array_fill(0, count($codes), '?'));
            [$like, $likeBinds] = self::codeLike('o.code', $codes);
            [$observed, $observedBinds] = self::observed('x.pid', $kind, $start, $end);
            $row = QueryUtils::fetchRecords(
                "SELECT COUNT(DISTINCT x.pid) AS p FROM (
                    SELECT o.pid FROM form_observation AS o
                     WHERE COALESCE(o.ob_type, '') <> ? AND ($like) AND o.date >= ? AND o.date < ? AND COALESCE(o.activity, 1) <> 0
                    UNION ALL
                    SELECT ord.patient_id AS pid FROM procedure_result AS res
                      JOIN procedure_report AS rep ON rep.procedure_report_id = res.procedure_report_id
                      JOIN procedure_order AS ord ON ord.procedure_order_id = rep.procedure_order_id
                     WHERE ord.activity <> 0 AND res.date >= ? AND res.date < ?
                       AND (res.result_code IN ($in) OR EXISTS (
                           SELECT 1 FROM procedure_order_code AS oc
                            WHERE oc.procedure_order_id = ord.procedure_order_id AND oc.procedure_code IN ($in)))
                 ) AS x
                 WHERE NOT $observed AND x.pid IN (SELECT DISTINCT fe.pid FROM form_encounter AS fe WHERE $where)",
                [
                    $read['type'], ...$likeBinds, $start, $end,
                    $start, $end, ...$codes, ...array_map(static fn(string $c): string => 'LOINC:' . $c, $codes),
                    ...$observedBinds, ...$binds,
                ],
            )[0] ?? [];
            $patients = Row::nullableInt($row, 'p') ?? 0;
            if ($patients > 0) {
                $out[$kind] = $patients;
            }
        }
        return $out;
    }

    /**
     * SQL true when the patient in $pidColumn has, in [$start, $end), an
     * observation of the type and with one of the codes the measures read for
     * $kind, as the eCQM builder reads them (it doesn't skip inactive ones).
     *
     * @return array{string, list<string>}
     */
    private static function observed(string $pidColumn, string $kind, string $start, string $end): array
    {
        $read = EcqmChecks::OBSERVATION_CODES[$kind];
        [$like, $likeBinds] = self::codeLike('o2.code', $read['codes']);
        return [
            "EXISTS (SELECT 1 FROM form_observation AS o2 WHERE o2.pid = $pidColumn AND o2.ob_type = ? AND ($like) AND o2.date >= ? AND o2.date < ?)",
            [$read['type'], ...$likeBinds, $start, $end],
        ];
    }

    /**
     * An observation's code column holds one code as SYSTEM:code (LOINC:39156-5).
     *
     * @param list<string> $codes
     * @return array{string, list<string>}
     */
    private static function codeLike(string $column, array $codes): array
    {
        return [
            implode(' OR ', array_fill(0, count($codes), "$column LIKE ?")),
            array_map(static fn(string $c): string => '%' . $c . '%', $codes),
        ];
    }

    /** @return array{string, string} */
    private static function yearBounds(int $year): array
    {
        return [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)];
    }

    /** Whether the Physical Exam Performed observation type is active; null if the list has no such entry. */
    public function physicalExamTypeActive(): ?bool
    {
        $row = QueryUtils::fetchRecords(
            "SELECT activity FROM list_options WHERE list_id = 'Observation_Types' AND option_id = 'physical_exam_performed'",
        )[0] ?? null;
        return $row === null ? null : Row::nullableInt($row, 'activity') === 1;
    }

    /**
     * The measure folders installed for a year where OpenEMR looks for them, or
     * null when there is no folder for that year (or OpenEMR has no eCQM support).
     *
     * @return ?list<string>
     */
    public function installedMeasures(int $year): ?array
    {
        if (!class_exists(MeasureService::class) || !method_exists(MeasureService::class, 'fetchMeasureSourceOptions')) {
            return null;
        }
        // OpenEMR builds the path from the performance-period global; set it for this year, then put it back.
        $globals = OEGlobalsBag::getInstance();
        $previous = $globals->get('cqm_performance_period');
        $globals->set('cqm_performance_period', (string)$year);
        try {
            $options = MeasureService::fetchMeasureSourceOptions();
        } finally {
            $globals->set('cqm_performance_period', $previous);
        }
        $relative = is_array($options) ? ($options['openemr/oe-cqm-parsers'] ?? null) : null;
        $dir = is_string($relative) ? OpenEmr::projectDir() . $relative : null;
        if ($dir === null || !is_dir($dir)) {
            return null;
        }
        $names = array_values(array_filter(scandir($dir) ?: [], static fn(string $n): bool => preg_match('/^CMS\d+v/', $n) === 1 && is_dir("$dir/$n")));
        sort($names, SORT_NATURAL);
        return $names;
    }

    /** Whether this OpenEMR calculates eCQMs in PHP (#14469) rather than through its Node service. */
    public static function phpEngine(): bool
    {
        return class_exists('OpenEMR\\Cqm\\PhpCqmCalculation');
    }

    /**
     * Whether this OpenEMR's QDM builder lets an ongoing problem, and a visit
     * with no end time, count: asked of the builder itself, so the answer
     * changes when OpenEMR does (openemr/openemr#14526). Null when it can't be
     * asked.
     *
     * @return array{problems: ?bool, visits: ?bool}
     */
    public static function openEndsCount(): array
    {
        $ask = static function (string $class, array $record, string $period, bool $isVisit): ?bool {
            try {
                if (!class_exists($class) || !class_exists(QdmRecord::class) || !class_exists(QdmRequestSome::class)) {
                    return null;
                }
                $service = new $class(new QdmRequestSome([0]), new CodeTypesService());
                $model = json_decode(json_encode($service->makeQdmModel(new QdmRecord($record, 0, 0)), JSON_THROW_ON_ERROR), true);
                $interval = is_array($model) ? ($model[$period] ?? null) : null;
                return is_array($interval) ? EcqmChecks::openEndCounts($interval, $isVisit) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        return [
            'problems' => $ask(DiagnosisService::class, ['pid' => 0, 'begdate' => '2026-01-01', 'enddate' => null, 'date' => '2026-01-01', 'diagnosis' => ''], 'prevalencePeriod', false),
            'visits' => $ask(EncounterService::class, [
                'pid' => 0, 'encounter' => 1, 'date' => '2026-03-10 10:00:00', 'date_end' => null, 'encounter_type_code' => '',
                'discharge_disposition' => null, 'discharge_dispo_code' => null, 'diagnosis' => null, 'pc_duration' => null,
            ], 'relevantPeriod', true),
        ];
    }
}
