<?php

/**
 * An inclusive range of CDC Race & Ethnicity codes, compared on their first
 * four digits (2034 for 2034-7).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class CodeRange
{
    public function __construct(public int $from, public int $to)
    {
    }

    /** The first four digits of a CDC code, or null when it isn't one. */
    public static function stem(string $code): ?int
    {
        return preg_match('/^(\d{4})-\d$/', $code, $m) === 1 ? (int)$m[1] : null;
    }

    public function contains(?int $stem): bool
    {
        return $stem !== null && $stem >= $this->from && $stem <= $this->to;
    }

    public function overlaps(self $other): bool
    {
        return $this->from <= $other->to && $other->from <= $this->to;
    }
}
