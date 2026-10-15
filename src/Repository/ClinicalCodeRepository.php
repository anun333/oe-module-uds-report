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
    ];

    /**
     * @param list<string> $systems the code systems wanted (rxnorm, loinc); others are ignored
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
        return $events;
    }

    /**
     * The codes in a stored value: one code, or several separated by ; or
     * spaces, each perhaps prefixed with its system (RXCUI:, RXNORM:, LOINC:).
     * A code prefixed with another system is not one of this system's.
     *
     * @return list<string> normalized
     */
    public static function codes(string $value, string $system): array
    {
        $prefixes = $system === self::RXNORM ? ['RXCUI', 'RXNORM'] : ['LOINC', 'LN'];
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
            }
            if ($part !== '') {
                $codes[] = Table6aCode::normalize($part);
            }
        }
        return array_values(array_unique($codes));
    }
}
