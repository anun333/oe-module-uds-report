<?php

/**
 * Housing status, the module's uds_housing_status list (Table 4, Lines 17-23).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum HousingStatus: string
{
    case Housed = 'housed';
    case Shelter = 'shelter';
    case Transitional = 'transitional';
    case DoublingUp = 'doubling_up';
    case Street = 'street';
    case PermanentSupportive = 'permanent_supportive';
    case OtherHomeless = 'other_homeless';
    case HomelessUnknown = 'homeless_unknown';
    case Declined = 'declined';

    /** Table 4 line for someone experiencing homelessness, or null when the status isn't homelessness. */
    public function table4Line(): ?string
    {
        return match ($this) {
            self::Shelter => '17',
            self::Transitional => '18',
            self::DoublingUp => '19',
            self::Street => '20',
            self::PermanentSupportive => '21a',
            self::OtherHomeless => '21',
            self::HomelessUnknown => '22',
            self::Housed, self::Declined => null,
        };
    }

    public function isHomeless(): bool
    {
        return $this->table4Line() !== null;
    }
}
