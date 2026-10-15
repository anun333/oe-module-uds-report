<?php

/**
 * Codes for Table 6A recorded outside the fee sheet, for the lines the manual
 * defines by value sets of medications (RxNorm) and of screenings and tests
 * (LOINC):
 * - RxNorm: prescriptions, by their start date (their entry date when they
 *   have none), and medications given from OpenEMR's inventory (Dispense).
 * - LOINC: the screening instrument of an SDOH assessment (History, not marked
 *   declined), the code of a questionnaire answered (unless its encounter form
 *   was deleted), Observations, and lab results that weren't cancelled or
 *   entered in error.
 * - SNOMED CT, CPT and HCPCS: care plan entries marked Completed, for the
 *   support services and upstream drivers of health lines (35-42), whose
 *   interventions are often recorded there rather than on the fee sheet.
 * - CVX: vaccines recorded in the Immunizations screen as given here (Lines
 *   24-24b): completed or with no status, from a new immunization record or
 *   with no source, not refused, not marked as added in error. A historical
 *   record (given elsewhere) isn't a service of the health center.
 * Only the year's records are read. Each is tied to its encounter when it has
 * one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\Model\CodedEvent;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode;

final readonly class ClinicalCodeRepository
{
    public const RXNORM = 'rxnorm';
    public const LOINC = 'loinc';
    public const SNOMED = 'snomed';
    public const CPT = 'cpt';
    public const HCPCS = 'hcpcs';
    public const CVX = 'cvx';

    /** The prefixes a stored code may carry for each system, as OpenEMR's code types write them. */
    private const PREFIXES = [
        self::RXNORM => ['RXCUI', 'RXNORM'],
        self::LOINC => ['LOINC', 'LN'],
        self::SNOMED => ['SNOMED-CT', 'SNOMED', 'SNOMED-PR', 'SCT'],
        self::CPT => ['CPT4', 'CPT'],
        self::HCPCS => ['HCPCS'],
        self::CVX => ['CVX'],
    ];

    /**
     * Care plan entries marked Completed: services provided, not planned. Goals and health
     * concerns aren't services. Rows of a deleted form, or marked inactive, are left out.
     * The form stores each code as TYPE:code (SNOMED-CT by default).
     */
    private const CARE_PLANS = "SELECT fcp.pid, fcp.encounter, DATE(fcp.date) AS day, fcp.code
               FROM form_care_plan AS fcp
              WHERE fcp.date >= ? AND fcp.date < ? AND fcp.plan_status = 'completed'
                AND COALESCE(fcp.care_plan_type, '') NOT IN ('goal', 'health_concern')
                AND COALESCE(fcp.activity, 1) <> 0 AND COALESCE(fcp.code, '') <> ''
                AND NOT EXISTS (SELECT 1 FROM forms AS f WHERE f.form_id = fcp.id AND f.formdir = 'care_plan' AND f.deleted = 1)";

    /** Each source: its query (pid, encounter, day, code in that order) and the code system it gives. */
    private const SOURCES = [
        'prescriptions' => [self::RXNORM,
            "SELECT p.pid, p.encounter, p.day, p.code
               FROM (SELECT patient_id AS pid, COALESCE(encounter, 0) AS encounter, rxnorm_drugcode AS code,
                            CASE WHEN start_date IS NULL OR start_date < '1900-01-01' THEN DATE(date_added) ELSE start_date END AS day
                       FROM prescriptions
                      WHERE COALESCE(rxnorm_drugcode, '') <> '') AS p
              WHERE p.day >= ? AND p.day < ?"],
        'dispensed' => [self::RXNORM,
            "SELECT s.pid, s.encounter, s.sale_date AS day, d.drug_code AS code
               FROM drug_sales AS s
               JOIN drugs AS d ON d.drug_id = s.drug_id
              WHERE s.trans_type = 1 AND s.pid > 0 AND s.sale_date >= ? AND s.sale_date < ? AND COALESCE(d.drug_code, '') <> ''"],
        'sdoh' => [self::LOINC,
            "SELECT h.pid, COALESCE(h.encounter, 0) AS encounter, COALESCE(h.assessment_date, DATE(h.created_at)) AS day, lo.codes AS code
               FROM form_history_sdoh AS h
               JOIN list_options AS lo ON lo.list_id = 'sdoh_instruments' AND lo.option_id = h.screening_tool
              WHERE COALESCE(h.declined_flag, 0) = 0
                AND COALESCE(h.assessment_date, DATE(h.created_at)) >= ? AND COALESCE(h.assessment_date, DATE(h.created_at)) < ?"],
        'questionnaires' => [self::LOINC,
            "SELECT r.patient_id AS pid, COALESCE(r.encounter, 0) AS encounter, DATE(r.create_time) AS day, q.code
               FROM questionnaire_response AS r
               JOIN questionnaire_repository AS q ON q.id = r.questionnaire_foreign_id
              WHERE r.create_time >= ? AND r.create_time < ? AND COALESCE(q.code, '') <> ''
                AND COALESCE(r.status, '') NOT IN ('entered-in-error', 'stopped')
                AND NOT EXISTS (
                    SELECT 1 FROM form_questionnaire_assessments AS a
                      JOIN forms AS f ON f.form_id = a.id AND f.formdir = 'questionnaire_assessments' AND f.deleted = 1
                     WHERE a.response_id = r.response_id)"],
        'observations' => [self::LOINC,
            "SELECT o.pid, o.encounter, DATE(o.date) AS day, o.code
               FROM form_observation AS o
              WHERE o.date >= ? AND o.date < ? AND COALESCE(o.activity, 1) <> 0 AND o.code LIKE 'LOINC:%'
                AND NOT EXISTS (SELECT 1 FROM forms AS f WHERE f.form_id = o.form_id AND f.formdir = 'observation' AND f.deleted = 1)"],
        'results' => [self::LOINC,
            "SELECT ord.patient_id AS pid, COALESCE(ord.encounter_id, 0) AS encounter,
                    DATE(COALESCE(res.date, rep.date_collected, ord.date_ordered)) AS day, res.result_code AS code
               FROM procedure_result AS res
               JOIN procedure_report AS rep ON rep.procedure_report_id = res.procedure_report_id
               JOIN procedure_order AS ord ON ord.procedure_order_id = rep.procedure_order_id
              WHERE ord.activity <> 0 AND COALESCE(res.result_code, '') <> ''
                AND COALESCE(res.result_status, '') NOT IN ('cancel', 'error')
                AND COALESCE(res.date, rep.date_collected, ord.date_ordered) >= ?
                AND COALESCE(res.date, rep.date_collected, ord.date_ordered) < ?"],
        'immunizations' => [self::CVX,
            "SELECT i.patient_id AS pid, COALESCE(i.encounter_id, 0) AS encounter, DATE(i.administered_date) AS day, i.cvx_code AS code
               FROM immunizations AS i
              WHERE i.administered_date >= ? AND i.administered_date < ? AND COALESCE(i.cvx_code, '') <> ''
                AND COALESCE(i.added_erroneously, 0) = 0 AND COALESCE(i.refusal_reason, '') = ''
                AND COALESCE(i.completion_status, '') IN ('', 'Completed')
                AND COALESCE(i.information_source, '') IN ('', 'new_immunization_record')"],
    ];

    /**
     * @param list<string> $systems the code systems wanted (rxnorm, loinc, cvx, ...); others are ignored
     * @return list<CodedEvent>
     */
    public function forYear(int $year, array $systems): array
    {
        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-01-01', $year + 1);
        $events = [];
        foreach (self::SOURCES as [$system, $sql]) {
            if (!in_array($system, $systems, true)) {
                continue;
            }
            foreach (QueryUtils::fetchRecords($sql, [$start, $end]) as $row) {
                $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr(Row::text($row, 'day'), 0, 10));
                $pid = Row::nullableInt($row, 'pid') ?? 0;
                if ($day === false || $pid <= 0) {
                    continue;
                }
                // form_observation.encounter is text.
                $encounter = max(0, Row::nullableInt($row, 'encounter') ?? 0);
                foreach (self::codes(Row::text($row, 'code'), $system) as $code) {
                    $events[] = new CodedEvent($pid, $encounter, $day, $system, $code);
                }
            }
        }
        $carePlanSystems = array_values(array_intersect([self::SNOMED, self::CPT, self::HCPCS], $systems));
        if ($carePlanSystems !== []) {
            foreach (QueryUtils::fetchRecords(self::CARE_PLANS, [$start, $end]) as $row) {
                $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr(Row::text($row, 'day'), 0, 10));
                $pid = Row::nullableInt($row, 'pid') ?? 0;
                if ($day === false || $pid <= 0) {
                    continue;
                }
                // form_care_plan.encounter is text.
                $encounter = max(0, Row::nullableInt($row, 'encounter') ?? 0);
                foreach ($carePlanSystems as $system) {
                    foreach (self::codes(Row::text($row, 'code'), $system, true) as $code) {
                        $events[] = new CodedEvent($pid, $encounter, $day, $system, $code);
                    }
                }
            }
        }
        return $events;
    }

    /**
     * The year's prescriptions and medications given from inventory whose drug
     * name contains one of the words, with their RxNorm codes, for the check
     * that finds medications Table 6A can't count (MedicationCheck).
     *
     * @param list<string> $words lower case
     * @return list<array{pid: int, name: string, codes: list<string>}>
     */
    public function namedMedications(int $year, array $words): array
    {
        if ($words === []) {
            return [];
        }
        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-01-01', $year + 1);
        $like = static fn(string $column): string => '(' . implode(' OR ', array_fill(0, count($words), "LOWER($column) LIKE ?")) . ')';
        $patterns = array_map(static fn(string $w): string => '%' . $w . '%', $words);
        $rows = [
            ...QueryUtils::fetchRecords(
                "SELECT p.pid, p.name, p.code
                   FROM (SELECT rx.patient_id AS pid, COALESCE(NULLIF(rx.drug, ''), d.name, '') AS name, rx.rxnorm_drugcode AS code,
                                CASE WHEN rx.start_date IS NULL OR rx.start_date < '1900-01-01' THEN DATE(rx.date_added) ELSE rx.start_date END AS day
                           FROM prescriptions AS rx
                           LEFT JOIN drugs AS d ON d.drug_id = rx.drug_id) AS p
                  WHERE p.day >= ? AND p.day < ? AND " . $like('p.name'),
                [$start, $end, ...$patterns],
            ),
            ...QueryUtils::fetchRecords(
                "SELECT s.pid, d.name, d.drug_code AS code
                   FROM drug_sales AS s
                   JOIN drugs AS d ON d.drug_id = s.drug_id
                  WHERE s.trans_type = 1 AND s.pid > 0 AND s.sale_date >= ? AND s.sale_date < ? AND " . $like('d.name'),
                [$start, $end, ...$patterns],
            ),
        ];
        $out = [];
        foreach ($rows as $row) {
            $pid = Row::nullableInt($row, 'pid') ?? 0;
            if ($pid > 0) {
                $out[] = ['pid' => $pid, 'name' => Row::text($row, 'name'), 'codes' => self::codes(Row::text($row, 'code'), self::RXNORM)];
            }
        }
        return $out;
    }

    /**
     * Patients prescribed a drug whose name contains one of the words during the
     * year, for the lines that need a prescription (PrEP, Line 21e): a
     * prescription started in the year, or earlier with an end date in the year
     * or later, and medications given from inventory in the year. A prescription
     * started earlier with no end date isn't taken to have lasted into the year.
     *
     * @param list<string> $words lower case
     * @return list<array{pid: int, name: string}>
     */
    public function prescribedInYear(int $year, array $words): array
    {
        if ($words === []) {
            return [];
        }
        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-01-01', $year + 1);
        $like = static fn(string $column): string => '(' . implode(' OR ', array_fill(0, count($words), "LOWER($column) LIKE ?")) . ')';
        $patterns = array_map(static fn(string $w): string => '%' . $w . '%', $words);
        $rows = [
            ...QueryUtils::fetchRecords(
                "SELECT p.pid, p.name
                   FROM (SELECT rx.patient_id AS pid, COALESCE(NULLIF(rx.drug, ''), d.name, '') AS name, rx.end_date,
                                CASE WHEN rx.start_date IS NULL OR rx.start_date < '1900-01-01' THEN DATE(rx.date_added) ELSE rx.start_date END AS day
                           FROM prescriptions AS rx
                           LEFT JOIN drugs AS d ON d.drug_id = rx.drug_id) AS p
                  WHERE p.day < ? AND (p.day >= ? OR p.end_date >= ?) AND " . $like('p.name'),
                [$end, $start, $start, ...$patterns],
            ),
            ...QueryUtils::fetchRecords(
                "SELECT s.pid, d.name
                   FROM drug_sales AS s
                   JOIN drugs AS d ON d.drug_id = s.drug_id
                  WHERE s.trans_type = 1 AND s.pid > 0 AND s.sale_date >= ? AND s.sale_date < ? AND " . $like('d.name'),
                [$start, $end, ...$patterns],
            ),
        ];
        $out = [];
        foreach ($rows as $row) {
            $pid = Row::nullableInt($row, 'pid') ?? 0;
            if ($pid > 0) {
                $out[] = ['pid' => $pid, 'name' => Row::text($row, 'name')];
            }
        }
        return $out;
    }

    /**
     * The codes in a stored value: one code, or several separated by ; or
     * spaces, each perhaps prefixed with its system (RXCUI:, RXNORM:, LOINC:).
     * A code prefixed with another system is not one of this system's.
     * With $prefixRequired, a code without a prefix is left out (care plans mix code types).
     *
     * @return list<string> normalized
     */
    public static function codes(string $value, string $system, bool $prefixRequired = false): array
    {
        $prefixes = self::PREFIXES[$system] ?? [];
        $codes = [];
        foreach (preg_split('/[;,\s]+/', trim($value)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $colon = strpos($part, ':');
            if ($colon !== false) {
                if (!in_array(strtoupper(substr($part, 0, $colon)), $prefixes, true)) {
                    continue;
                }
                $part = substr($part, $colon + 1);
            } elseif ($prefixRequired) {
                continue;
            }
            if ($system === self::CVX && ctype_digit($part)) {
                // OpenEMR stores CVX codes without CDC's leading zero (8, not 08).
                $part = (string) (int) $part;
            }
            if ($part !== '') {
                $codes[] = Table6aCode::normalize($part);
            }
        }
        return array_values(array_unique($codes));
    }
}
