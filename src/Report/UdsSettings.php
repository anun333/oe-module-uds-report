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
    /** A provider-line choice meaning the person isn't a provider, so their encounters aren't Table 5 visits. */
    public const NOT_A_PROVIDER = 'none';

    /**
     * @param ?list<int> $countableCategoryIds     visit categories that are countable visits; null: every patient category except No Show
     * @param ?list<int> $inScopeFacilityIds       facilities in the health center's scope of project; null: all
     * @param list<int>  $schoolBasedFacilityIds   school-based service sites (Table 4, Line 24)
     * @param list<int>  $publicHousingFacilityIds sites in or immediately accessible to public housing (Line 26)
     * @param array<int, InsuranceCategory> $insuranceOverrides insurance company id => the clinic's choice
     * @param array<int, string> $providerLines user id => the Table 5 line the clinic chose, or NOT_A_PROVIDER
     * @param array<int, string> $categoryLines visit category id => the Table 5 line every visit in it goes on
     * @param ?list<int> $virtualCategoryIds       visit categories whose visits are virtual (Table 5 Column B2); null: the
     *                                             reference data's by constant id (Comlink's telehealth categories)
     * @param list<string> $serviceAreaZips        the service area's ZIP codes on the health center's Form 5B, as entered
     * @param ?string      $hrsaGrantNumber        the health center's HRSA grant or look-alike number (H80CS00000, LALCS00000),
     *                                             to find its published report from last year
     */
    public function __construct(
        public ?array $countableCategoryIds = null,
        public ?array $inScopeFacilityIds = null,
        public array $schoolBasedFacilityIds = [],
        public array $publicHousingFacilityIds = [],
        public PovertyRegion $povertyRegion = PovertyRegion::Contiguous,
        public array $insuranceOverrides = [],
        public array $providerLines = [],
        public array $categoryLines = [],
        public ?array $virtualCategoryIds = null,
        public array $serviceAreaZips = [],
        public ?string $hrsaGrantNumber = null,
    ) {
    }
}
