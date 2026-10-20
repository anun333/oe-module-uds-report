<?php

/**
 * The counts a grant application or report asks for, from the UDS patient universe:
 * who the health center serves, and how many were screened for social needs and
 * given services for food, housing or financial insecurity, by ZIP, age and insurance. Counts only, with small cells
 * hidden (SmallCells). The social needs are Table 6A's Lines 39 to 42, as Table 6A
 * counts them, so the figures agree with it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 * @phpstan-import-type Table4 from DemographicTables
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\AgriculturalWorker;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;

final class GrantEvidence
{
    /** Table 6A's upstream drivers of health lines: screening, then services for each need (not positive screens). */
    public const SOCIAL_NEEDS = ['39', '40', '41', '42'];

    public const OTHER_ZIPS = 'other';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<UdsPatient> $patients the report's patients
     * @param Table4 $table4 DemographicTables::table4(), for income as a share of the poverty guideline
     * @param array<string, array<int, true>> $socialNeeds by Table 6A line, the pids counted on it; a line
     *                                                     that isn't counted (no value sets) is left out
     */
    public function __construct(
        private readonly array $patients,
        private readonly int $year,
        private readonly array $table4,
        private readonly array $socialNeeds,
    ) {
    }

    public function total(): ?int
    {
        return SmallCells::one(count($this->patients));
    }

    /**
     * Who the health center serves, breakdown by breakdown. Each breakdown adds up to the
     * patient total except the special populations and language, which are counts of their own.
     *
     * @return array{age: array<string, ?int>, sex: array<string, ?int>, insurance: array<string, ?int>,
     *               income: array<string, ?int>, zip: array<int|string, ?int>, special: array<string, ?int>,
     *               language: ?int}
     */
    public function whoWeServe(): array
    {
        $age = array_fill_keys(['0-17', '18-64', '65+', self::UNKNOWN], 0);
        $sex = array_fill_keys(['female', 'male', self::UNKNOWN], 0);
        $insurance = array_fill_keys(['none', 'public', 'medicare', 'private', self::UNKNOWN], 0);
        $limitedEnglish = 0;
        $special = ['homeless' => 0, 'agricultural' => 0, 'veterans' => 0];
        foreach ($this->patients as $p) {
            $age[$this->ageBand($p)]++;
            $sex[$p->sexAtBirth?->value ?? self::UNKNOWN]++;
            $insurance[self::insuranceGroup($p)]++;
            $limitedEnglish += $p->limitedEnglish ? 1 : 0;
            $special['homeless'] += $p->isHomeless() ? 1 : 0;
            $special['agricultural'] += in_array($p->agriculturalWorker, [AgriculturalWorker::Migratory, AgriculturalWorker::Seasonal], true) ? 1 : 0;
            $special['veterans'] += $p->veteranStatus === VeteranStatus::Veteran ? 1 : 0;
        }
        $income = [];
        foreach (['1' => 'under100', '2' => '101to150', '3' => '151to200', '4' => 'over200', '5' => self::UNKNOWN] as $line => $key) {
            $value = $this->table4['income'][$line] ?? 0;
            $income[$key] = is_int($value) ? $value : 0;
        }
        return [
            'age' => SmallCells::breakdown($age),
            'sex' => SmallCells::breakdown($sex),
            'insurance' => SmallCells::breakdown($insurance),
            'income' => SmallCells::breakdown($income),
            'zip' => SmallCells::breakdown($this->zipCounts(array_map(fn(UdsPatient $p): int => $p->pid, $this->patients))),
            'special' => array_map(SmallCells::one(...), $special),
            'language' => SmallCells::one($limitedEnglish),
        ];
    }

    /** Whether the social-needs lines are counted on this site (their value sets are fetched). */
    public function socialNeedsCounted(): bool
    {
        return isset($this->socialNeeds['39']);
    }

    /**
     * Social-needs screening and services, overall and by ZIP, age band or insurance: for each group,
     * the patients in it and the count on each counted line. Each column is its own breakdown for
     * suppression, since a line's counts add up to that line's total.
     *
     * @param 'zip'|'age'|'insurance'|null $by null for the totals alone
     * @return array<int|string, array<string, ?int>> group => ('patients' | line) => count; a ZIP of digits is an int key
     */
    public function socialNeeds(?string $by = null): array
    {
        $lines = array_values(array_filter(self::SOCIAL_NEEDS, fn(string $l): bool => isset($this->socialNeeds[$l])));
        $groupOf = [];
        foreach ($this->patients as $p) {
            $groupOf[$p->pid] = match ($by) {
                'age' => $this->ageBand($p),
                'insurance' => self::insuranceGroup($p),
                default => 'all',
            };
        }
        if ($by === 'zip') {
            $groupOf = $this->zipGroups(array_keys($groupOf));
        }
        $columns = ['patients' => []];
        foreach ($groupOf as $group) {
            $columns['patients'][$group] = ($columns['patients'][$group] ?? 0) + 1;
        }
        foreach ($lines as $line) {
            $columns[$line] = array_fill_keys(array_keys($columns['patients']), 0);
            foreach ($this->socialNeeds[$line] as $pid => $_) {
                if (isset($groupOf[$pid])) {
                    $columns[$line][$groupOf[$pid]]++;
                }
            }
        }
        $shown = array_map(static fn(array $column): array => $by === null ? array_map(SmallCells::one(...), $column) : SmallCells::breakdown($column), $columns);
        $rows = [];
        foreach (array_keys($columns['patients']) as $group) {
            foreach ($shown as $column => $values) {
                $rows[$group][$column] = $values[$group];
            }
        }
        return $rows;
    }

    private function ageBand(UdsPatient $p): string
    {
        $age = $p->ageAtYearEnd($this->year);
        return match (true) {
            $age === null => self::UNKNOWN,
            $age < 18 => '0-17',
            $age < 65 => '18-64',
            default => '65+',
        };
    }

    /** The Patients by ZIP Code table's insurance columns: Medicaid, CHIP and other public together. */
    private static function insuranceGroup(UdsPatient $p): string
    {
        return $p->insurance->zipColumn() ?? self::UNKNOWN;
    }

    /** A patient's ZIP as the UDS ZIP table places them: residence, else a homeless patient's visit site. */
    private static function zipOf(UdsPatient $p): string
    {
        return $p->zip ?? ($p->isHomeless() ? $p->visitSiteZip : null) ?? self::UNKNOWN;
    }

    /**
     * Each patient's ZIP group: their ZIP when 11 or more patients share it, else "other".
     *
     * @param list<int> $pids
     * @return array<int, string>
     */
    private function zipGroups(array $pids): array
    {
        $wanted = array_fill_keys($pids, true);
        $zipOf = [];
        foreach ($this->patients as $p) {
            if (isset($wanted[$p->pid])) {
                $zipOf[$p->pid] = self::zipOf($p);
            }
        }
        $sizes = array_count_values($zipOf);
        return array_map(
            static fn(string $zip): string => $zip === self::UNKNOWN || $sizes[$zip] >= SmallCells::MIN ? $zip : self::OTHER_ZIPS,
            $zipOf
        );
    }

    /**
     * @param list<int> $pids
     * @return array<int|string, int> ZIP group => patients, ZIPs in order, then other, then unknown
     */
    private function zipCounts(array $pids): array
    {
        $counts = array_count_values($this->zipGroups($pids));
        $zips = array_filter($counts, static fn(int|string $k): bool => !in_array((string)$k, [self::OTHER_ZIPS, self::UNKNOWN], true), ARRAY_FILTER_USE_KEY);
        ksort($zips, SORT_STRING);
        $out = [];
        foreach ($zips as $zip => $n) {
            $out[$zip] = $n;
        }
        $out[self::OTHER_ZIPS] = $counts[self::OTHER_ZIPS] ?? 0;
        $out[self::UNKNOWN] = $counts[self::UNKNOWN] ?? 0;
        return $out;
    }
}
