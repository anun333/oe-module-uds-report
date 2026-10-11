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

use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceData;
use OpenEMR\Modules\UdsReport\Repository\EncounterCodeRepository;
use OpenEMR\Modules\UdsReport\Repository\Table5VisitRepository;
use OpenEMR\Modules\UdsReport\Repository\Table9DRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

final class UdsReportService
{
    /** @var ?list<UdsPatient> */
    private ?array $patients = null;

    /** @param ?GrantReport $grant null: the Universal Report; else that population's Grant Report */
    public function __construct(
        public readonly int $year,
        public readonly UdsSettings $settings,
        private readonly ReferenceData $reference,
        private readonly UdsPatientRepository $patientRepository,
        public readonly ?GrantReport $grant = null,
    ) {
    }

    /**
     * Every UDS patient in the year (the Universal Report's population).
     *
     * @return list<UdsPatient>
     */
    public function patients(): array
    {
        return $this->patients ??= $this->patientRepository->forYear($this->year, $this->settings);
    }

    /**
     * The patients this report covers: everyone for the Universal Report, or the
     * grant's population for a Grant Report.
     *
     * @return list<UdsPatient>
     */
    public function reportPatients(): array
    {
        $grant = $this->grant;
        if ($grant === null) {
            return $this->patients();
        }
        return array_values(array_filter($this->patients(), fn(UdsPatient $p): bool => $grant->includes($p, $this->settings)));
    }

    public function tables(): DemographicTables
    {
        return new DemographicTables($this->reportPatients(), $this->year, $this->settings, $this->reference);
    }

    /** Table 6A, from the billing codes on the year's encounters. */
    public function table6A(): Table6A
    {
        $pids = $this->reportPids();
        $encounters = (new EncounterCodeRepository($this->patientRepository))->forYear($this->year, $this->settings);
        return new Table6A($this->reference->table6a, $encounters, $pids);
    }

    /** Table 5 visits and patients, from the year's countable visits and who provided them. */
    public function table5(): Table5
    {
        $pids = $this->reportPids();
        $repository = new Table5VisitRepository($this->patientRepository);
        $visits = array_values(array_filter(
            $repository->forYear($this->year, $this->settings),
            static fn(Table5Visit $v): bool => isset($pids[$v->pid]),
        ));
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

    /**
     * The Table 9D worksheet, for the whole health center: Grant Reports have no
     * Table 9D, so it ignores the grant.
     */
    public function table9D(): Table9DWorksheet
    {
        $repository = new Table9DRepository($this->patientRepository);
        return new Table9DWorksheet(
            $this->reference->adjustmentReasons,
            $repository->charged($this->year, $this->settings),
            $repository->posted($this->year, $this->settings),
        );
    }

    /**
     * What the Table 9D worksheet reports beside the table.
     *
     * @return array{copayLines: array{count: int, cents: int}, unapplied: array{count: int, cents: int}, productSales: array{count: int, cents: int}}
     */
    public function table9DNotes(): array
    {
        return (new Table9DRepository($this->patientRepository))->notes($this->year, $this->settings);
    }

    /** @return array<string, list<int>> DataGap value => pids */
    public function gaps(): array
    {
        return (new DataGapFinder($this->reference->raceEthnicity))->byGap($this->reportPatients());
    }

    /** @return array<int, true> */
    private function reportPids(): array
    {
        $pids = [];
        foreach ($this->reportPatients() as $patient) {
            $pids[$patient->pid] = true;
        }
        return $pids;
    }
}
