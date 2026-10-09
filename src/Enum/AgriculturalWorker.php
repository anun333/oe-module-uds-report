<?php

/**
 * Agricultural worker status, the module's uds_agricultural_worker list
 * (Table 4, Lines 14-16).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum AgriculturalWorker: string
{
    case NotAgricultural = 'not_agricultural';
    case Migratory = 'migratory';
    case Seasonal = 'seasonal';
    /** Aged or disabled former migratory worker: counted as migratory. */
    case FormerMigratory = 'former_migratory';
    case Declined = 'declined';

    /** Line 14 (migratory) or 15 (seasonal), or null when not an agricultural worker. */
    public function table4Line(): ?string
    {
        return match ($this) {
            self::Migratory, self::FormerMigratory => '14',
            self::Seasonal => '15',
            self::NotAgricultural, self::Declined => null,
        };
    }
}
