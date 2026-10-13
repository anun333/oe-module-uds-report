<?php

/**
 * The rules behind the eCQM readiness page, kept apart from the database so they
 * can be tested: whether a UDS measure is installed at the version the manual
 * names, whether OpenEMR's QDM builder lets a record with no end date count, and
 * where the measures read screenings and exams from.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class EcqmChecks
{
    /**
     * Whether a measure is installed at the version UDS uses. OpenEMR keeps each
     * measure in a folder named for it, such as CMS165v14.
     *
     * @param list<string> $installed the measure folders installed for the year
     * @return array{installed: bool, otherVersions: list<string>}
     */
    public static function measureStatus(string $measure, array $installed): array
    {
        $base = preg_replace('/v\d+$/D', '', $measure) ?? $measure;
        $others = array_values(array_filter(
            $installed,
            static fn(string $dir): bool => $dir !== $measure && preg_match('/^' . preg_quote($base, '/') . 'v\d+(?:-v\d+)?$/D', $dir) === 1,
        ));
        sort($others, SORT_NATURAL);
        return ['installed' => in_array($measure, $installed, true), 'otherVersions' => $others];
    }

    /**
     * Whether a period with no end date can satisfy a measure, from the period
     * OpenEMR's QDM builder made for such a record (decoded JSON: low, high,
     * lowClosed, highClosed). In CQL an open null end is unknown, so it fails
     * "overlaps" and "during"; a closed null end is ongoing.
     *
     * An ongoing problem counts when its end is ongoing or given. A visit counts
     * only when it has an end: a visit ongoing to the end of time isn't "during"
     * the year either.
     *
     * @param array<mixed> $period
     */
    public static function openEndCounts(array $period, bool $isVisit): bool
    {
        $high = $period['high'] ?? null;
        if ($high !== null && $high !== '') {
            return true;
        }
        return !$isVisit && ($period['highClosed'] ?? false) === true;
    }

    /**
     * The units the engine refuses, from a count of results by unit. An empty
     * unit isn't one: a result with none reaches the engine without a unit.
     *
     * @param array<string, array{results: int, patients: int}> $byUnit
     * @param callable(string): bool $accepted
     * @return array<string, array{results: int, patients: int}> the most results first
     */
    public static function rejectedUnits(array $byUnit, callable $accepted): array
    {
        $out = [];
        foreach ($byUnit as $unit => $counts) {
            if (trim((string)$unit) !== '' && !$accepted((string)$unit)) {
                $out[(string)$unit] = $counts;
            }
        }
        uasort($out, static fn(array $a, array $b): int => $b['results'] <=> $a['results']);
        return $out;
    }

    /**
     * Codes the UDS measures read only from the Observation form, and only as
     * the Observation type named: QDM "Assessment, Performed" is type
     * assessment, "Physical Exam, Performed" is physical_exam_performed
     * (OpenEMR's AbstractObservationService). The same code as a lab result, or
     * as an Observation of another type, is not read. All LOINC, from the
     * measures' value sets for the 2026 reporting period (CMS138v14 Tobacco Use
     * Screening, CMS2v15, CMS139v14 Falls Screening, CMS69v14, CMS155v14 BMI
     * percentile, CMS165v14).
     *
     * @var array<string, array{type: string, measure: string, codes: list<string>}>
     */
    public const OBSERVATION_CODES = [
        'tobacco' => ['type' => 'assessment', 'measure' => 'CMS138', 'codes' => ['39240-7', '68535-4', '68536-2', '72166-2']],
        'depression' => ['type' => 'assessment', 'measure' => 'CMS2', 'codes' => ['73831-0', '73832-8']],
        'falls' => ['type' => 'assessment', 'measure' => 'CMS139', 'codes' => ['52552-7', '57254-5', '59454-9', '73830-2']],
        'bmi' => ['type' => 'physical_exam_performed', 'measure' => 'CMS69', 'codes' => ['39156-5']],
        'bmiPercentile' => ['type' => 'physical_exam_performed', 'measure' => 'CMS155', 'codes' => ['59574-4', '59575-1', '59576-9']],
        'bloodPressure' => ['type' => 'physical_exam_performed', 'measure' => 'CMS165', 'codes' => ['8480-6', '8462-4']],
    ];

    /**
     * Whether a History tobacco value records the patient's tobacco use.
     * OpenEMR saves the field as note|type|date|status|pack-years: status is a
     * Smoking Status list option, type the current, quit or never button
     * (named for the field, as in currenttobacco). Either one counts.
     */
    public static function tobaccoRecorded(?string $value): bool
    {
        $parts = explode('|', (string)$value);
        return trim($parts[3] ?? '') !== '' || preg_match('/^(current|quit|never)/', trim($parts[1] ?? '')) === 1;
    }

    /**
     * Common lab units the engine refuses, with the UCUM unit meaning the same.
     * Each pair checked against OpenEMR's own Units::checkUnit (master, 2026-10-13):
     * the left is refused, the right accepted.
     */
    public const UCUM = [
        'mEq/L' => 'meq/L', 'x10E3/uL' => '10*3/uL', 'thou/uL' => '10*3/uL', 'x10E6/uL' => '10*6/uL', 'mill/uL' => '10*6/uL',
        'IU/L' => '[IU]/L', 'mIU/mL' => 'm[IU]/mL', 'uIU/mL' => 'u[IU]/mL', 'mm/hr' => 'mm/h', 'sec' => 's', 'mmHg' => 'mm[Hg]',
        'cells/uL' => '{cells}/uL', 'mL/min/1.73m2' => 'mL/min/{1.73_m2}', 'lb' => '[lb_av]', 'lbs' => '[lb_av]', 'in' => '[in_i]',
        'degF' => '[degF]', 'bpm' => '/min', 'ratio' => '{ratio}', '/HPF' => '/[HPF]', '/LPF' => '/[LPF]', 'mg/24hr' => 'mg/(24.h)',
        'Index' => '{index}', 'titer' => '{titer}',
    ];
}
