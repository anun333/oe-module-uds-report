<?php

/**
 * UDS Table 6A: Selected Diagnoses and Services Rendered.
 *
 * Column A counts visits, Column B patients, per line (2026 UDS Manual,
 * Table 6A instructions):
 * - Diagnoses (Lines 1-20g): countable visits at which a listed diagnosis was
 *   recorded. A visit counts once on a line however many of its codes match,
 *   and on every line any of its codes match.
 * - Services and dental (Lines 21-42): visits at which a listed service was
 *   provided, whether or not the visit itself is countable, for patients with
 *   at least one countable visit in the year ("provided at any point during the
 *   year to a health center patient").
 * - Column B counts a patient once per line.
 * - Age limits use the age at each visit.
 * - Dental services (Lines 27-32) count only when the visit was with dental
 *   staff; fluoride (Line 31) at a medical visit too (page 73).
 * - PrEP (Line 21e) counts only for patients prescribed one of the drugs the
 *   manual names (page 72).
 * Lines the module can't count (value sets only) are left blank.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Model\EncounterCodes;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6a as Table6aDefinition;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aLine;

final readonly class Table6A
{
    public const NOT_COUNTED_PERSONNEL = 'personnel';
    public const NOT_COUNTED_PRESCRIPTION = 'prescription';

    /**
     * @param list<EncounterCodes>              $encounters every encounter in the year that has billing codes
     * @param array<int, true>                  $patients   pids of UDS patients (at least one countable visit)
     * @param array<string, array<int, true>>   $prescribed by line, the pids with one of the line's prescriptions
     */
    public function __construct(
        private Table6aDefinition $definition,
        private array $encounters,
        private array $patients,
        private array $prescribed = [],
    ) {
    }

    /**
     * @return list<array{line: Table6aLine, visits: ?int, patients: ?int}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->definition->lines as $line) {
            if (!$line->isCounted()) {
                $rows[] = ['line' => $line, 'visits' => null, 'patients' => null];
                continue;
            }
            $visits = 0;
            $patients = [];
            foreach ($this->encounters as $encounter) {
                if ($this->matches($line, $encounter) && $this->missing($line, $encounter) === null) {
                    $visits++;
                    $patients[$encounter->pid] = true;
                }
            }
            $rows[] = ['line' => $line, 'visits' => $visits, 'patients' => count($patients)];
        }
        return $rows;
    }

    /**
     * The patients counted on a line, as rows() counts them, or null when the line isn't counted
     * (it needs value sets the site hasn't fetched, or the module doesn't count it).
     *
     * @return ?array<int, true>
     */
    public function patientsOnLine(string $lineId): ?array
    {
        foreach ($this->definition->lines as $line) {
            if ($line->line !== $lineId) {
                continue;
            }
            if (!$line->isCounted()) {
                return null;
            }
            $patients = [];
            foreach ($this->encounters as $encounter) {
                if ($this->matches($line, $encounter) && $this->missing($line, $encounter) === null) {
                    $patients[$encounter->pid] = true;
                }
            }
            return $patients;
        }
        return null;
    }

    /**
     * Patients with a visit that has a line's codes but isn't counted on it,
     * because of who provided the service or a missing prescription.
     *
     * @return array<string, list<int>> NOT_COUNTED_* => sorted pids
     */
    public function notCounted(): array
    {
        $found = [self::NOT_COUNTED_PERSONNEL => [], self::NOT_COUNTED_PRESCRIPTION => []];
        foreach ($this->definition->lines as $line) {
            if (!$line->isCounted()) {
                continue;
            }
            foreach ($this->encounters as $encounter) {
                $missing = $this->matches($line, $encounter) ? $this->missing($line, $encounter) : null;
                if ($missing !== null) {
                    $found[$missing][$encounter->pid] = true;
                }
            }
        }
        $out = [];
        foreach ($found as $reason => $pids) {
            $sorted = array_keys($pids);
            sort($sorted);
            $out[$reason] = $sorted;
        }
        return $out;
    }

    /** A UDS patient's visit, of the right kind and age, with one of the line's codes. */
    private function matches(Table6aLine $line, EncounterCodes $encounter): bool
    {
        if (!isset($this->patients[$encounter->pid])) {
            return false;
        }
        if ($line->needsCountableVisit() ? !$encounter->countable : !$encounter->inScope) {
            return false;
        }
        return $line->allowsAge($encounter->birthDate, $encounter->date) && $line->matches($encounter->codes);
    }

    /** What else a matching visit needs to count on the line: NOT_COUNTED_*, or null when nothing. */
    private function missing(Table6aLine $line, EncounterCodes $encounter): ?string
    {
        if (!$line->allowsPersonnel($encounter->personnel)) {
            return self::NOT_COUNTED_PERSONNEL;
        }
        if ($line->needsPrescription() && !isset($this->prescribed[$line->line][$encounter->pid])) {
            return self::NOT_COUNTED_PRESCRIPTION;
        }
        return null;
    }
}
