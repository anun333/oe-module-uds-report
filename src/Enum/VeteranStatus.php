<?php

/**
 * Military service, the module's uds_veteran_status list (Table 4, Line 25).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum VeteranStatus: string
{
    /** Served, and not on active duty now: the only answer Line 25 counts. */
    case Veteran = 'veteran';
    case ActiveDuty = 'active_duty';
    case NotVeteran = 'not_veteran';
    case Declined = 'declined';
}
