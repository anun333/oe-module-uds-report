<?php

/**
 * One countable visit in the report year, with what Table 5 needs to place it:
 * who saw the patient, where, how (in person or virtual), and what for.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

final readonly class Table5Visit
{
    /**
     * @param string                      $day            the visit date, Y-m-d
     * @param ?string                     $placeOfService two digits, as on a claim
     * @param string                      $encounterClass OpenEMR encounter class code (AMB, VR, ...)
     * @param array<string, list<string>> $codes          procedure codes by system (cpt, hcpcs)
     * @param list<string>                $modifiers      procedure code modifiers, upper case
     * @param ?string                     $firstDiagnosis the first ICD-10 code on the encounter
     */
    public function __construct(
        public int $pid,
        public int $encounter,
        public string $day,
        public int $facilityId,
        public int $providerId,
        public int $categoryId,
        public ?string $placeOfService,
        public string $encounterClass,
        public array $codes,
        public array $modifiers,
        public ?string $firstDiagnosis,
    ) {
    }
}
