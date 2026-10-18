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

use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Model\EncounterCodes;
use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceData;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6a as Table6aDefinition;
use OpenEMR\Modules\UdsReport\Repository\ClinicalCodeRepository;
use OpenEMR\Modules\UdsReport\Repository\EncounterCodeRepository;
use OpenEMR\Modules\UdsReport\Repository\Table5VisitRepository;
use OpenEMR\Modules\UdsReport\Repository\Table9DRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenEMR\Modules\UdsReport\Repository\ValueSetRepository;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetCodes;

final class UdsReportService
{
    /** @var ?list<UdsPatient> */
    private ?array $patients = null;

    private ?ValueSetCodes $valueSets = null;

    private ?Table6A $table6A = null;

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

    /**
     * Table 6A, from the billing codes on the year's encounters. A line whose
     * value sets the site has fetched is counted from their codes too, read
     * from prescriptions, screenings and results where they are medications
     * (RxNorm) or screenings and tests (LOINC). Each visit carries the kind of
     * staff it was with, for the dental lines, and the lines that need a
     * prescription get the patients who had one.
     */
    /** The counts a grant application asks for (GrantEvidence), from this report's patients and Table 6A. */
    public function grantEvidence(): GrantEvidence
    {
        $socialNeeds = [];
        foreach (GrantEvidence::SOCIAL_NEEDS as $line) {
            $patients = $this->table6A()->patientsOnLine($line);
            if ($patients !== null) {
                $socialNeeds[$line] = $patients;
            }
        }
        return new GrantEvidence($this->reportPatients(), $this->year, $this->tables()->table4(), $socialNeeds);
    }

    public function table6A(): Table6A
    {
        return $this->table6A ??= $this->buildTable6A();
    }

    private function buildTable6A(): Table6A
    {
        $pids = $this->reportPids();
        $fetched = $this->valueSets();
        // Care plan entries carry the codes of support services (Lines 35-42), and the Immunizations screen
        // vaccines (Lines 24-24b), whether or not the value sets are fetched; medications (RxNorm) and
        // screenings (LOINC) matter only for value set lines.
        $systems = [
            ClinicalCodeRepository::SNOMED, ClinicalCodeRepository::CPT, ClinicalCodeRepository::HCPCS, ClinicalCodeRepository::CVX,
            ...array_intersect([ClinicalCodeRepository::RXNORM, ClinicalCodeRepository::LOINC], $fetched->systems()),
        ];
        $events = (new ClinicalCodeRepository())->forYear($this->year, $systems);
        $encounters = (new EncounterCodeRepository($this->patientRepository))->forYear($this->year, $this->settings, $events);
        $providerIds = array_values(array_unique(array_filter(
            array_map(static fn(EncounterCodes $e): int => $e->providerId, $encounters),
            static fn(int $id): bool => $id > 0,
        )));
        $personnel = new EncounterPersonnel(
            $this->reference->table5,
            $this->reference->providerTaxonomy,
            (new Table5VisitRepository($this->patientRepository))->providers($providerIds),
            $this->settings,
        );
        $encounters = array_map(
            static fn(EncounterCodes $e): EncounterCodes => $e->withPersonnel($personnel->category($e->providerId, $e->categoryId)),
            $encounters,
        );
        $definition = $this->reference->table6a->withValueSets($fetched);
        return new Table6A($definition, $encounters, $pids, $this->prescribed($definition));
    }

    /**
     * For each line that needs a prescription, the patients who had one in the year.
     *
     * @return array<string, array<int, true>>
     */
    private function prescribed(Table6aDefinition $definition): array
    {
        $prescribed = [];
        foreach ($definition->lines as $line) {
            if (!$line->needsPrescription()) {
                continue;
            }
            $words = array_values(array_unique(array_merge(...$line->prescriptions)));
            $prescribed[$line->line] = [];
            foreach ((new ClinicalCodeRepository())->prescribedInYear($this->year, $words) as $medication) {
                if ($line->namesPrescription($medication['name'])) {
                    $prescribed[$line->line][$medication['pid']] = true;
                }
            }
        }
        return $prescribed;
    }

    /** The value sets fetched from VSAC, none until an administrator fetches them. */
    public function valueSets(): ValueSetCodes
    {
        return $this->valueSets ??= (new ValueSetRepository())->load();
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
        $gaps = (new DataGapFinder($this->reference->raceEthnicity))->byGap($this->reportPatients());
        $gaps[DataGap::MedicationNotCounted->value] = $this->medicationsNotCounted();
        $notCounted = $this->table6A()->notCounted();
        $gaps[DataGap::DentalServiceNotCounted->value] = $notCounted[Table6A::NOT_COUNTED_PERSONNEL];
        $gaps[DataGap::PrepNotCounted->value] = $notCounted[Table6A::NOT_COUNTED_PRESCRIPTION];
        return $gaps;
    }

    /**
     * Report patients with a tobacco cessation or opioid use disorder medication
     * that Table 6A can't count (MedicationCheck), once those lines count from
     * their value sets.
     *
     * @return list<int>
     */
    private function medicationsNotCounted(): array
    {
        $lines = [];
        foreach ($this->reference->table6a->withValueSets($this->valueSets())->lines as $line) {
            if (isset(MedicationCheck::WORDS[$line->line]) && $line->usesValueSets()) {
                $lines[$line->line] = $line;
            }
        }
        if ($lines === []) {
            return [];
        }
        $report = $this->reportPids();
        $pids = [];
        foreach ((new ClinicalCodeRepository())->namedMedications($this->year, MedicationCheck::allWords()) as $medication) {
            if (isset($report[$medication['pid']]) && MedicationCheck::uncountedLine($medication['name'], $medication['codes'], $lines) !== null) {
                $pids[$medication['pid']] = true;
            }
        }
        $sorted = array_keys($pids);
        sort($sorted);
        return $sorted;
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
