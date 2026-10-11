<?php

/**
 * The rules behind the eCQM readiness page, kept apart from the database so they
 * can be tested: whether a UDS measure is installed at the version the manual
 * names, and whether OpenEMR's QDM builder lets a record with no end date count.
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
}
