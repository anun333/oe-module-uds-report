<?php

/**
 * One encounter in the report year, with the billing codes recorded on it,
 * as Table 6A needs it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

use DateTimeImmutable;

final readonly class EncounterCodes
{
    /**
     * @param bool                        $countable a countable visit: has a provider, a countable category, an in-scope site
     * @param bool                        $inScope   at an in-scope site (or no scope set)
     * @param array<string, list<string>> $codes     normalized codes by Table 6A code system
     */
    public function __construct(
        public int $pid,
        public int $encounter,
        public DateTimeImmutable $date,
        public bool $countable,
        public bool $inScope,
        public ?DateTimeImmutable $birthDate,
        public array $codes,
    ) {
    }
}
