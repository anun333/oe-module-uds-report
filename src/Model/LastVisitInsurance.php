<?php

/**
 * A patient's insurance as of their last visit in the year.
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

final readonly class LastVisitInsurance
{
    /**
     * @param InsuranceCategory $category     category of the primary policy, None when there was none
     * @param bool              $dualEligible Medicare primary with Medicaid also in effect
     * @param bool              $recorded     false when no policy at all was in effect
     */
    public function __construct(
        public InsuranceCategory $category,
        public bool $dualEligible,
        public bool $recorded,
    ) {
    }
}
