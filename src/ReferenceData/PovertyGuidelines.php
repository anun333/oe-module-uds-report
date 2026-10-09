<?php

/**
 * HHS poverty guidelines, for UDS Table 4 income Lines 1-5.
 *
 * The figures come from data/poverty-guidelines.json, one row per year and
 * region, each citing its HHS/ASPE source. A year or region without figures
 * has no guideline, and the report says so rather than computing with a guess.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;

final readonly class PovertyGuidelines
{
    public const FILE = 'poverty-guidelines';

    /**
     * @param array<int, array<string, array{int, int}>> $tables year => region => [first person, each additional]
     */
    private function __construct(private array $tables)
    {
    }

    /** Checks the decoded file and keeps the rows that have figures. */
    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $root = JsonShape::object($data, $file, ['about', 'guidelines']);
        JsonShape::text($root['about'], $file . ' about');
        $regions = array_map(static fn(PovertyRegion $r): string => $r->value, PovertyRegion::cases());
        $tables = [];
        $seen = [];
        foreach (JsonShape::list($root['guidelines'], $file . ' guidelines') as $i => $row) {
            $at = sprintf('%s guidelines[%d]', $file, $i);
            $g = JsonShape::object($row, $at, ['year', 'region', 'firstPerson', 'eachAdditional', 'source']);
            $year = JsonShape::int($g['year'], $at . '.year', 2000, 2100);
            $region = JsonShape::oneOf($g['region'], $at . '.region', $regions);
            if (isset($seen[$year][$region])) {
                throw new ReferenceDataException($at, sprintf('%d %s is listed twice', $year, $region));
            }
            $seen[$year][$region] = true;
            if ($g['firstPerson'] === null && $g['eachAdditional'] === null) {
                continue; // still to be filled in; listed so the gap is visible
            }
            $tables[$year][$region] = [
                JsonShape::int($g['firstPerson'], $at . '.firstPerson', 1, 1_000_000),
                JsonShape::int($g['eachAdditional'], $at . '.eachAdditional', 1, 1_000_000),
            ];
            JsonShape::text($g['source'], $at . '.source');
        }
        return new self($tables);
    }

    public function annualThreshold(int $year, PovertyRegion $region, int $familySize): ?int
    {
        $table = $this->tables[$year][$region->value] ?? null;
        if ($table === null || $familySize < 1) {
            return null;
        }
        return $table[0] + ($familySize - 1) * $table[1];
    }

    public function isAvailable(int $year, PovertyRegion $region): bool
    {
        return isset($this->tables[$year][$region->value]);
    }

    /**
     * Table 4 income line: '1' (100% and below), '2' (101-150%), '3' (151-200%),
     * '4' (over 200%), or '5' (unknown).
     *
     * @return '1'|'2'|'3'|'4'|'5'
     */
    public function incomeLine(?int $annualIncome, ?int $familySize, int $year, PovertyRegion $region): string
    {
        if ($annualIncome === null || $annualIncome < 0 || $familySize === null || $familySize < 1) {
            return '5';
        }
        $threshold = $this->annualThreshold($year, $region, $familySize);
        if ($threshold === null) {
            return '5';
        }
        // Exact comparison, as sliding-fee policies state it ("at or below 100%"):
        // anything above 100% is in the 101-150% band. Integer maths, no float edges.
        $income200 = $annualIncome * 2;
        return match (true) {
            $income200 <= $threshold * 2 => '1',
            $income200 <= $threshold * 3 => '2',
            $income200 <= $threshold * 4 => '3',
            default => '4',
        };
    }
}
