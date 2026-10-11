<?php

/**
 * A year's UDS report: the patients, the tables and the record problems.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceData;
use OpenEMR\Modules\UdsReport\Repository\EncounterCodeRepository;
use OpenEMR\Modules\UdsReport\Repository\Table5VisitRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

final class UdsReportService
{
    /** @var ?list<UdsPatient> */
    private ?array $patients = null;

    public function __construct(
        public readonly int $year,
        public readonly UdsSettings $settings,
        private readonly ReferenceData $reference,
        private readonly UdsPatientRepository $patientRepository,
    ) {
    }

    /** @return list<UdsPatient> */
    public function patients(): array
    {
        return $this->patients ??= $this->patientRepository->forYear($this->year, $this->settings);
    }

    public function tables(): DemographicTables
    {
        return new DemographicTables($this->patients(), $this->year, $this->settings, $this->reference);
    }

    /** Table 6A, from the billing codes on the year's encounters. */
    public function table6A(): Table6A
    {
        $pids = [];
        foreach ($this->patients() as $patient) {
            $pids[$patient->pid] = true;
        }
        $encounters = (new EncounterCodeRepository($this->patientRepository))->forYear($this->year, $this->settings);
        return new Table6A($this->reference->table6a, $encounters, $pids);
    }

    /** Table 5 visits and patients, from the year's countable visits and who provided them. */
    public function table5(): Table5
    {
        $pids = [];
        foreach ($this->patients() as $patient) {
            $pids[$patient->pid] = true;
        }
        $repository = new Table5VisitRepository($this->patientRepository);
        $visits = $repository->forYear($this->year, $this->settings);
        $providerIds = array_values(array_unique(array_map(static fn(Table5Visit $v): int => $v->providerId, $visits)));
        return new Table5(
            $this->reference->table5,
            $this->reference->providerTaxonomy,
            $visits,
            $repository->providers($providerIds),
            $this->settings,
            $pids,
        );
    }

    /** @return array<string, list<int>> DataGap value => pids */
    public function gaps(): array
    {
        return (new DataGapFinder($this->reference->raceEthnicity))->byGap($this->patients());
    }
}
