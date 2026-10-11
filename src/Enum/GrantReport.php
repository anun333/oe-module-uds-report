<?php

/**
 * The UDS Grant Reports (2026 UDS Manual, pages 11-12 and 15-16): health centers with
 * more than one Health Center Program funding type file one for each special
 * medically underserved population they are funded for. Each covers that
 * population's patients and all their in-scope services, whatever paid for
 * them, on Tables 3A, 3B, 4, 5 (without FTEs) and 6A.
 *
 * Membership is the Table 4 test for the same population, so a Grant Report's
 * total matches the Universal Report's Table 4 line for it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

enum GrantReport: string
{
    /** Migratory and Seasonal Agricultural Workers, section 330(g): Table 4 Lines 14-15. */
    case AgriculturalWorkers = '330g';
    /** Homeless Population, section 330(h): Table 4 Lines 17-22. */
    case Homeless = '330h';
    /** Residents of Public Housing, section 330(i): Table 4 Line 26, everyone seen at a site in or next to public housing. */
    case PublicHousing = '330i';

    /** The Table 4 line that counts this population, which the Grant Report's total patients must equal. */
    public function table4Line(): string
    {
        return match ($this) {
            self::AgriculturalWorkers => '16',
            self::Homeless => '23',
            self::PublicHousing => '26',
        };
    }

    public function includes(UdsPatient $patient, UdsSettings $settings): bool
    {
        return match ($this) {
            self::AgriculturalWorkers => $patient->agriculturalWorker?->table4Line() !== null,
            self::Homeless => $patient->housingStatus?->table4Line() !== null,
            self::PublicHousing => array_intersect($patient->visitSiteIds, $settings->publicHousingFacilityIds) !== [],
        };
    }
}
