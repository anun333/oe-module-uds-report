<?php

/**
 * Finds the record problems that keep a patient off a UDS table or push them
 * into an "unknown" line, so staff can fix the chart before submission.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;

final readonly class DataGapFinder
{
    public function __construct(private RaceEthnicity $raceEthnicity)
    {
    }

    /** @return list<DataGap> in DataGap order */
    public function forPatient(UdsPatient $p): array
    {
        $gaps = [];
        if ($p->sexAtBirth === null) {
            $gaps[] = DataGap::NoBirthSex;
        }
        if ($p->dob === null) {
            $gaps[] = DataGap::NoDob;
        }
        if ($p->insurance === InsuranceCategory::Unmapped) {
            $gaps[] = DataGap::InsurerUnmapped;
        }
        if ($this->raceEthnicity->raceLine($p->raceCodes) === '7') {
            $gaps[] = DataGap::RaceUnreported;
        }
        if ($p->incomeGaps !== []) {
            array_push($gaps, ...$p->incomeGaps);
        } elseif (!$p->incomeVerified || $p->annualIncome === null || $p->familySize === null) {
            // Built without the raw values (tests, conformance cases): the reason is unknown.
            $gaps[] = DataGap::IncomeUnverified;
        }
        if (!$p->insuranceRecorded) {
            $gaps[] = DataGap::NoInsuranceOnFile;
        }
        if ($p->zip === null && !$p->isHomeless()) {
            $gaps[] = DataGap::NoZip;
        }
        // OpenEMR's own Demographics fields for the same facts are free text, so they aren't read as a
        // status; when one holds something, the gap says so, for staff to record the UDS field from it.
        if ($p->housingStatus === null) {
            $gaps[] = $p->coreHomeless !== '' ? DataGap::HousingInCoreField : DataGap::HousingNotAsked;
        }
        if ($p->agriculturalWorker === null) {
            $gaps[] = $p->coreMigrantSeasonal !== '' ? DataGap::AgricultureInCoreField : DataGap::AgricultureNotAsked;
        }
        if ($p->veteranStatus === null) {
            $gaps[] = DataGap::VeteranNotAsked;
        }
        return $gaps;
    }

    /**
     * @param list<UdsPatient> $patients
     * @return array<string, list<int>> DataGap value => pids, with every gap present (possibly empty)
     */
    public function byGap(array $patients): array
    {
        $result = [];
        foreach (DataGap::cases() as $gap) {
            $result[$gap->value] = [];
        }
        foreach ($patients as $p) {
            foreach ($this->forPatient($p) as $gap) {
                $result[$gap->value][] = $p->pid;
            }
        }
        return $result;
    }
}
