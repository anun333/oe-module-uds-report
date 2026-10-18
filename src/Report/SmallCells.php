<?php

/**
 * Small-cell suppression for counts published outside the health center: a count
 * from 1 to 10 isn't shown (CMS's cell-size rule), and within one breakdown, when
 * exactly one cell is hidden, the next smallest is hidden too, so the breakdown's
 * total can't reveal it. Zero is shown.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class SmallCells
{
    /** The smallest count shown. */
    public const MIN = 11;

    /** A single count, shown or hidden (null). */
    public static function one(int $count): ?int
    {
        return $count === 0 || $count >= self::MIN ? $count : null;
    }

    /**
     * The cells of one breakdown that add up to its total, with the hidden ones null.
     *
     * @template K of array-key
     * @param array<K, int> $cells
     * @return array<K, ?int>
     */
    public static function breakdown(array $cells): array
    {
        $out = array_map(self::one(...), $cells);
        $hidden = array_keys(array_filter($out, static fn(?int $v): bool => $v === null));
        if (count($hidden) === 1) {
            // The total less the shown cells would give the hidden one: hide the smallest other count too.
            $smallest = null;
            foreach ($out as $key => $value) {
                if ($value !== null && $value > 0 && ($smallest === null || $value < $out[$smallest])) {
                    $smallest = $key;
                }
            }
            if ($smallest !== null) {
                $out[$smallest] = null;
            }
        }
        return $out;
    }

    /** A share as a whole percent, or null when either count is hidden or there is no denominator. */
    public static function percent(?int $part, ?int $whole): ?int
    {
        if ($part === null || $whole === null || $whole === 0) {
            return null;
        }
        return (int)round(100 * $part / $whole);
    }
}
