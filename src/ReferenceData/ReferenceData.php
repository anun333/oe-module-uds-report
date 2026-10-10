<?php

/**
 * The module's reference data, checked and ready to use: everything that
 * changes with each UDS Manual or HHS release, loaded from data/*.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class ReferenceData
{
    public function __construct(
        public PovertyGuidelines $povertyGuidelines,
        public RaceEthnicity $raceEthnicity,
        public InsuranceCategoryMap $insuranceCategories,
        public Table6a $table6a,
    ) {
    }
}
