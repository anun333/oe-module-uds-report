<?php

/**
 * One UDS patient for the reporting year: someone with at least one countable
 * visit, with the facts the demographic tables need, already resolved from
 * OpenEMR's tables by UdsPatientRepository.
 *
 * This is also the shape of the patients in data/cases/demographic-tables.json:
 * what an EHR has to supply for the counting rules to work.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

use DateTimeImmutable;
use OpenEMR\Modules\UdsReport\Enum\AgriculturalWorker;
use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;

final readonly class UdsPatient
{
    /**
     * @param list<string> $raceCodes          CDC race codes (empty: not reported)
     * @param list<string> $ethnicityCodes     CDC ethnicity codes (empty: not reported)
     * @param ?string      $zip                five-digit residence ZIP
     * @param ?string      $visitSiteZip       ZIP of the last visit's site, which stands in for people experiencing homelessness
     * @param bool         $limitedEnglish     best served in a language other than English
     * @param ?int         $annualIncome       household income per year
     * @param bool         $incomeVerified     income recorded at or within 12 months before the last visit
     * @param list<DataGap> $incomeGaps        why the income can't be used, when the raw values are known;
     *                                         empty for patients built without them (tests, conformance cases)
     * @param InsuranceCategory $insurance     primary medical insurance at the last visit
     * @param bool         $dualEligible       Medicare primary with Medicaid also active at the last visit
     * @param bool         $insuranceRecorded  false when no insurance at all was on file for the last visit
     * @param ?HousingStatus      $housingStatus      null: not recorded
     * @param ?AgriculturalWorker $agriculturalWorker null: not recorded
     * @param ?VeteranStatus      $veteranStatus      null: not recorded
     * @param list<int>    $visitSiteIds       facility ids of the year's countable visits
     * @param string $coreHomeless        OpenEMR's own Demographics field "Homeless, etc." (patient_data.homeless), free text
     * @param string $coreMigrantSeasonal OpenEMR's own "Migrant/Seasonal" field (patient_data.migrantseasonal), free text
     */
    public function __construct(
        public int $pid,
        public ?DateTimeImmutable $dob,
        public ?SexAtBirth $sexAtBirth,
        public array $raceCodes,
        public array $ethnicityCodes,
        public bool $limitedEnglish,
        public ?string $zip,
        public ?string $visitSiteZip,
        public ?int $annualIncome,
        public ?int $familySize,
        public bool $incomeVerified,
        public InsuranceCategory $insurance,
        public bool $dualEligible,
        public bool $insuranceRecorded,
        public ?HousingStatus $housingStatus,
        public ?AgriculturalWorker $agriculturalWorker,
        public ?VeteranStatus $veteranStatus,
        public array $visitSiteIds,
        public DateTimeImmutable $lastVisit,
        public array $incomeGaps = [],
        public string $coreHomeless = '',
        public string $coreMigrantSeasonal = '',
    ) {
    }

    /**
     * Age on December 31 of the reporting year (Tables 3A and 4), or null without a date of birth. HRSA's
     * Table 3A fact sheet: "Date to use to identify age: as of December 31", which replaced June 30 from the
     * 2023 report (https://bphc.hrsa.gov/sites/default/files/bphc/data-reporting/table-3a-fact-sheet-2024.pdf).
     */
    public function ageAtYearEnd(int $year): ?int
    {
        if ($this->dob === null) {
            return null;
        }
        $yearEnd = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        return $this->dob > $yearEnd ? null : $this->dob->diff($yearEnd)->y;
    }

    public function isHomeless(): bool
    {
        return $this->housingStatus?->isHomeless() ?? false;
    }
}
