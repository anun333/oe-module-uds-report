<?php

/**
 * An encounter with charges, with what is needed to split its charge among the
 * payers responsible for it: the insurers on file on its date of service, how
 * far its insurance billing has been closed, and every posting made to it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;

final readonly class ChargedEncounter
{
    /**
     * @param int                           $lastLevelClosed form_encounter.last_level_closed: 0 none, else the last insurance level finished
     * @param array<int, InsuranceCategory> $insurers        insurance level (1-3) => category on the date of service
     * @param list<Posting>                 $postings        every active posting on the encounter, whatever its date
     */
    public function __construct(
        public int $chargeCents,
        public int $lastLevelClosed,
        public array $insurers,
        public array $postings,
    ) {
    }
}
