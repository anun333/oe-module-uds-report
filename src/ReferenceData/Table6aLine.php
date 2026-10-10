<?php

/**
 * One line of UDS Table 6A: its codes by code system, any age limit, and how
 * much of it the module can count.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use DateTimeImmutable;

final readonly class Table6aLine
{
    public const SECTION_DIAGNOSES = 'diagnoses';
    public const SECTION_SERVICES = 'services';
    public const SECTION_DENTAL = 'dental';

    public const COMPUTED_FULL = 'full';
    public const COMPUTED_PARTIAL = 'partial';
    public const COMPUTED_NONE = 'none';

    /**
     * @param array<string, list<Table6aCode>> $codes      by code system (icd10, cpt, hcpcs, cdt, snomed)
     * @param list<string>                     $valueSets  OIDs the manual lists
     * @param ?array{0: int, 1: int}           $ageMonths  inclusive age range in months at the visit
     */
    public function __construct(
        public string $line,
        public string $section,
        public string $group,
        public string $label,
        public array $codes,
        public array $valueSets,
        public ?array $ageMonths,
        public string $computed,
        public ?string $limit,
    ) {
    }

    /** Diagnoses count only at countable visits; services and dental at any in-scope encounter. */
    public function needsCountableVisit(): bool
    {
        return $this->section === self::SECTION_DIAGNOSES;
    }

    public function isCounted(): bool
    {
        return $this->computed !== self::COMPUTED_NONE;
    }

    /**
     * Whether any of a visit's codes puts the visit on this line.
     *
     * @param array<string, list<string>> $visitCodes normalized codes by code system
     */
    public function matches(array $visitCodes): bool
    {
        foreach ($this->codes as $system => $entries) {
            foreach ($visitCodes[$system] ?? [] as $code) {
                foreach ($entries as $entry) {
                    if (!$entry->matches($code)) {
                        continue;
                    }
                    if ($entry->unless !== null && in_array($entry->unless, array_map(Table6aCode::normalize(...), $visitCodes[$system] ?? []), true)) {
                        continue;
                    }
                    return true;
                }
            }
        }
        return false;
    }

    /** A line with an age limit can't count a patient whose age at the visit is unknown. */
    public function allowsAge(?DateTimeImmutable $birthDate, DateTimeImmutable $visit): bool
    {
        if ($this->ageMonths === null) {
            return true;
        }
        if ($birthDate === null || $birthDate > $visit) {
            return false;
        }
        $diff = $birthDate->diff($visit);
        $months = $diff->y * 12 + $diff->m;
        return $months >= $this->ageMonths[0] && $months <= $this->ageMonths[1];
    }
}
