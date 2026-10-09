<?php

/**
 * Clinic choices the UDS rules leave to the health center.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;

final readonly class UdsSettings
{
    /**
     * @param ?list<int> $countableCategoryIds     visit categories that are countable visits; null: every patient category except No Show
     * @param ?list<int> $inScopeFacilityIds       facilities in the health center's scope of project; null: all
     * @param list<int>  $schoolBasedFacilityIds   school-based service sites (Table 4, Line 24)
     * @param list<int>  $publicHousingFacilityIds sites in or immediately accessible to public housing (Line 26)
     * @param array<int, InsuranceCategory> $insuranceOverrides insurance company id => the clinic's choice
     */
    public function __construct(
        public ?array $countableCategoryIds = null,
        public ?array $inScopeFacilityIds = null,
        public array $schoolBasedFacilityIds = [],
        public array $publicHousingFacilityIds = [],
        public PovertyRegion $povertyRegion = PovertyRegion::Contiguous,
        public array $insuranceOverrides = [],
    ) {
    }
}
