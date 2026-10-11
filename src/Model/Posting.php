<?php

/**
 * One payment or adjustment on an encounter (an active ar_activity row), with
 * the Table 9D line it belongs to already resolved.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

use OpenEMR\Modules\UdsReport\Enum\Table9DLine;

final readonly class Posting
{
    /**
     * @param int    $payerType 0 the patient, 1-3 the insurance level
     * @param string $reason    the adjustment reason (ar_activity.memo)
     * @param string $date      the posting date, Y-m-d
     * @param bool   $product   posted against a product sold from inventory, not a service
     */
    public function __construct(
        public int $payerType,
        public Table9DLine $line,
        public int $paidCents,
        public int $adjustedCents,
        public string $reason,
        public string $date,
        public bool $product = false,
    ) {
    }
}
