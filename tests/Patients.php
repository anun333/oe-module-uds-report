<?php

/**
 * Builder for UDS patient fixtures: a fully recorded adult unless told otherwise.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\AgriculturalWorker;
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;

final class Patients
{
    /**
     * @param list<string> $race
     * @param list<string> $ethnicity
     * @param list<int>    $sites
     */
    public static function make(
        int $pid = 1,
        ?string $dob = '1980-06-15',
        ?SexAtBirth $sex = SexAtBirth::Female,
        array $race = ['2106-3'],
        array $ethnicity = ['2186-5'],
        ?string $zip = '12345',
        bool $incomeVerified = true,
        InsuranceCategory $insurance = InsuranceCategory::Private,
        ?HousingStatus $housing = HousingStatus::Housed,
        ?AgriculturalWorker $agriculture = AgriculturalWorker::NotAgricultural,
        ?VeteranStatus $veteran = VeteranStatus::NotVeteran,
        array $sites = [3],
        array $incomeGaps = [],
    ): UdsPatient {
        return new UdsPatient(
            pid: $pid,
            dob: $dob === null ? null : new \DateTimeImmutable($dob),
            sexAtBirth: $sex,
            raceCodes: $race,
            ethnicityCodes: $ethnicity,
            limitedEnglish: false,
            zip: $zip,
            visitSiteZip: '99999',
            annualIncome: 30000,
            familySize: 2,
            incomeVerified: $incomeVerified,
            insurance: $insurance,
            dualEligible: false,
            insuranceRecorded: true,
            housingStatus: $housing,
            agriculturalWorker: $agriculture,
            veteranStatus: $veteran,
            visitSiteIds: $sites,
            lastVisit: new \DateTimeImmutable('2026-09-01'),
            incomeGaps: $incomeGaps,
        );
    }
}
