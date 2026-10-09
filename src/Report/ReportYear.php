<?php

/**
 * Which calendar year a UDS page reports on.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use DateTimeImmutable;

final class ReportYear
{
    /** Earliest year the pages offer. */
    public const EARLIEST = 2024;

    /**
     * The UDS is filed by February 15 for the previous calendar year, so through
     * March the default is last year, and after that the year so far.
     */
    public static function default(DateTimeImmutable $now): int
    {
        $year = (int)$now->format('Y');
        return (int)$now->format('n') <= 3 ? $year - 1 : $year;
    }

    /** The year asked for in a query string, or the default when it is missing or out of range. */
    public static function fromQuery(string $raw, DateTimeImmutable $now): int
    {
        if (preg_match('/^\d{4}$/D', $raw) !== 1) {
            return self::default($now);
        }
        $year = (int)$raw;
        return ($year >= self::EARLIEST && $year <= (int)$now->format('Y')) ? $year : self::default($now);
    }
}
