<?php

/**
 * What the eCQM readiness page reads: the year's countable visits, the problems
 * and blood pressures of the patients seen at them, OpenEMR's own lists, the
 * measure files installed, and how OpenEMR's QDM builder treats a record with no
 * end date.
 *
 * What OpenEMR's eCQM builder reads (src/Services/Qdm/Services) was measured on
 * OpenEMR master c11dd1b15c with CMS165v13: visits by their Encounter Type code,
 * not billing codes; problems only when classified Diagnosis; blood pressures
 * only as Physical Exam Performed observations, not from the Vitals form.
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
        $dir = is_string($relative) ? $globals->getProjectDir() . $relative : null;
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
