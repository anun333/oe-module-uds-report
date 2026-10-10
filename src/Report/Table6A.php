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
    /**
     * @param list<EncounterCodes> $encounters every encounter in the year that has billing codes
     * @param array<int, true>     $patients   pids of UDS patients (at least one countable visit)
     */
    public function __construct(
        private Table6aDefinition $definition,
        private array $encounters,
        private array $patients,
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
                if (!isset($this->patients[$encounter->pid])) {
                    continue;
                }
                if ($line->needsCountableVisit() ? !$encounter->countable : !$encounter->inScope) {
                    continue;
                }
                if (!$line->allowsAge($encounter->birthDate, $encounter->date) || !$line->matches($encounter->codes)) {
                    continue;
                }
                $visits++;
                $patients[$encounter->pid] = true;
            }
            $rows[] = ['line' => $line, 'visits' => $visits, 'patients' => count($patients)];
        }
        return $rows;
    }
}
