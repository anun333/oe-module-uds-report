<?php

/**
 * Places a patient on the UDS Table 3B race-by-ethnicity matrix.
 *
 * Input is CDC Race & Ethnicity codes (OpenEMR keeps them in list_options.notes
 * for the race and ethnicity lists). Which codes go to which line and column is
 * in data/race-ethnicity.json. The rules here follow the 2026 UDS Manual,
 * Table 3B instructions:
 * - Lines 1a-1g Asian, 2a-2d Native Hawaiian/Other Pacific Islander, 3 Black,
 *   4 American Indian/Alaska Native, 5 White, 6 more than one race, 7 unreported.
 * - Within a group, one listed subgroup goes to its line; an unlisted subgroup or
 *   several subgroups go to the group's own line (1g, 2b). Races from two or more
 *   groups go to Line 6.
 * - Columns A1 Mexican, A2 Puerto Rican, A3 Cuban, A4 another Hispanic origin,
 *   A5 Hispanic with unknown or multiple origins, B not Hispanic,
 *   C unreported ethnicity (only on Line 7, only when race is also unreported).
 * - A reported race with no ethnicity answer is presumed not Hispanic (B).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class RaceEthnicity
{
    public const FILE = 'race-ethnicity';

    public const LINES = ['1a', '1b', '1c', '1d', '1e', '1f', '1g', '2a', '2b', '2c', '2d', '3', '4', '5', '6', '7'];
    public const COLUMNS = ['A1', 'A2', 'A3', 'A4', 'A5', 'B', 'C'];

    /** Lines a race group or subgroup can map to: Lines 6 and 7 come from the rules, not the data. */
    private const GROUP_LINES = ['1a', '1b', '1c', '1d', '1e', '1f', '1g', '2a', '2b', '2c', '2d', '3', '4', '5'];
    /** Columns a Hispanic origin can map to. */
    private const ORIGIN_COLUMNS = ['A1', 'A2', 'A3', 'A4'];

    /**
     * @param list<RaceGroup>       $groups
     * @param array<string, string> $subgroupLines CDC code => line
     * @param array<string, string> $originColumns CDC code => column
     */
    private function __construct(
        private array $groups,
        private array $subgroupLines,
        private string $hispanic,
        private string $notHispanic,
        private CodeRange $originCodes,
        private string $otherOriginColumn,
        private array $originColumns,
    ) {
    }

    /**
     * @param list<string> $raceCodes      CDC codes for the patient's races (empty: not reported)
     * @param list<string> $ethnicityCodes CDC codes for the patient's ethnicity (empty: not reported)
     * @return array{line: string, column: string}
     */
    public function place(array $raceCodes, array $ethnicityCodes): array
    {
        $line = $this->raceLine($raceCodes);
        // No ethnicity answer: presume not Hispanic when a race was reported;
        // when neither was reported, it is the single Line 7 / Column C cell.
        $column = $this->ethnicityColumn($ethnicityCodes) ?? ($line === '7' ? 'C' : 'B');
        return ['line' => $line, 'column' => $column];
    }

    /**
     * @param list<string> $codes
     */
    public function raceLine(array $codes): string
    {
        $linesByGroup = [];
        $groupLine = [];
        foreach (array_unique($codes) as $code) {
            $group = $this->group($code);
            if ($group === null) {
                continue;
            }
            $linesByGroup[$group->name][] = $this->subgroupLines[$code] ?? $group->line;
            $groupLine[$group->name] = $group->line;
        }
        if (count($linesByGroup) > 1) {
            return '6';
        }
        foreach ($linesByGroup as $name => $lines) {
            $distinct = array_values(array_unique($lines));
            return count($distinct) === 1 ? $distinct[0] : $groupLine[$name];
        }
        return '7';
    }

    /**
     * @param list<string> $codes
     * @return ?string null when ethnicity was not reported
     */
    public function ethnicityColumn(array $codes): ?string
    {
        $specific = [];
        $general = false;
        $notHispanic = false;
        foreach (array_unique($codes) as $code) {
            if ($code === $this->notHispanic) {
                $notHispanic = true;
            } elseif ($code === $this->hispanic) {
                $general = true;
            } elseif ($this->originCodes->contains(CodeRange::stem($code))) {
                $specific[$this->originColumns[$code] ?? $this->otherOriginColumn] = true;
            }
        }
        if ($general || $specific !== []) {
            // Exactly one specific origin goes to its column. General "Hispanic"
            // alone, or more than one origin, goes to A5 (manual, Table 3B FAQ 3).
            $columns = array_keys($specific);
            return (!$general && count($columns) === 1) ? $columns[0] : 'A5';
        }
        return $notHispanic ? 'B' : null;
    }

    /**
     * Checks the decoded file, including that every subgroup and origin code
     * falls inside its own group's range and that no two groups share codes.
     */
    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $root = JsonShape::object($data, $file, ['about', 'basis', 'raceGroups', 'ethnicity']);
        JsonShape::text($root['about'], $file . ' about');
        JsonShape::text($root['basis'], $file . ' basis');

        $groups = [];
        $subgroupLines = [];
        foreach (JsonShape::list($root['raceGroups'], $file . ' raceGroups') as $i => $row) {
            $at = sprintf('%s raceGroups[%d]', $file, $i);
            $g = JsonShape::object($row, $at, ['group', 'name', 'codeRanges', 'line'], ['lineNote', 'subgroups']);
            $name = JsonShape::text($g['group'], $at . '.group');
            JsonShape::text($g['name'], $at . '.name');
            $line = JsonShape::oneOf($g['line'], $at . '.line', self::GROUP_LINES);
            if (array_key_exists('lineNote', $g)) {
                JsonShape::text($g['lineNote'], $at . '.lineNote');
            }
            foreach ($groups as $other) {
                if ($other->name === $name) {
                    throw new ReferenceDataException($at . '.group', sprintf('"%s" is listed twice', $name));
                }
            }
            $ranges = [];
            foreach (JsonShape::list($g['codeRanges'], $at . '.codeRanges') as $j => $value) {
                $rangeAt = sprintf('%s.codeRanges[%d]', $at, $j);
                $range = JsonShape::codeRange($value, $rangeAt);
                foreach ($groups as $other) {
                    foreach ($other->ranges as $otherRange) {
                        if ($range->overlaps($otherRange)) {
                            throw new ReferenceDataException($rangeAt, sprintf('overlaps the codes of group "%s"', $other->name));
                        }
                    }
                }
                $ranges[] = $range;
            }
            if ($ranges === []) {
                throw new ReferenceDataException($at . '.codeRanges', 'needs at least one range');
            }
            $group = new RaceGroup($name, $ranges, $line);
            foreach (JsonShape::list($g['subgroups'] ?? [], $at . '.subgroups') as $j => $value) {
                $subAt = sprintf('%s.subgroups[%d]', $at, $j);
                $s = JsonShape::object($value, $subAt, ['code', 'name', 'line']);
                $code = JsonShape::cdcCode($s['code'], $subAt . '.code');
                JsonShape::text($s['name'], $subAt . '.name');
                $subLine = JsonShape::oneOf($s['line'], $subAt . '.line', self::GROUP_LINES);
                if (!$group->contains(CodeRange::stem($code))) {
                    throw new ReferenceDataException($subAt . '.code', sprintf("%s is not in this group's codeRanges", $code));
                }
                if (self::lineNumber($subLine) !== self::lineNumber($line)) {
                    throw new ReferenceDataException($subAt . '.line', sprintf("line %s is not one of this group's lines (the group's line is %s)", $subLine, $line));
                }
                if (isset($subgroupLines[$code])) {
                    throw new ReferenceDataException($subAt . '.code', sprintf('%s is listed twice', $code));
                }
                $subgroupLines[$code] = $subLine;
            }
            $groups[] = $group;
        }

        $eat = $file . ' ethnicity';
        $e = JsonShape::object($root['ethnicity'], $eat, ['hispanic', 'notHispanic', 'originCodes', 'otherOriginColumn', 'origins']);
        $hispanic = self::namedCode($e['hispanic'], $eat . '.hispanic');
        $notHispanic = self::namedCode($e['notHispanic'], $eat . '.notHispanic');
        $originCodes = JsonShape::codeRange($e['originCodes'], $eat . '.originCodes');
        foreach (['hispanic' => $hispanic, 'notHispanic' => $notHispanic] as $field => $code) {
            if ($originCodes->contains(CodeRange::stem($code))) {
                throw new ReferenceDataException($eat . '.' . $field, sprintf('%s must not be inside originCodes', $code));
            }
        }
        $otherOriginColumn = JsonShape::oneOf($e['otherOriginColumn'], $eat . '.otherOriginColumn', self::ORIGIN_COLUMNS);
        $originColumns = [];
        foreach (JsonShape::list($e['origins'], $eat . '.origins') as $i => $value) {
            $at = sprintf('%s.origins[%d]', $eat, $i);
            $o = JsonShape::object($value, $at, ['code', 'name', 'column']);
            $code = JsonShape::cdcCode($o['code'], $at . '.code');
            JsonShape::text($o['name'], $at . '.name');
            if (!$originCodes->contains(CodeRange::stem($code))) {
                throw new ReferenceDataException($at . '.code', sprintf('%s is not inside originCodes', $code));
            }
            if (isset($originColumns[$code])) {
                throw new ReferenceDataException($at . '.code', sprintf('%s is listed twice', $code));
            }
            $originColumns[$code] = JsonShape::oneOf($o['column'], $at . '.column', self::ORIGIN_COLUMNS);
        }

        return new self($groups, $subgroupLines, $hispanic, $notHispanic, $originCodes, $otherOriginColumn, $originColumns);
    }

    private function group(string $code): ?RaceGroup
    {
        $stem = CodeRange::stem($code);
        foreach ($this->groups as $group) {
            if ($group->contains($stem)) {
                return $group;
            }
        }
        return null; // 2131 Other Race, ethnicity codes, anything unknown
    }

    private static function namedCode(mixed $value, string $where): string
    {
        $o = JsonShape::object($value, $where, ['code', 'name']);
        JsonShape::text($o['name'], $where . '.name');
        return JsonShape::cdcCode($o['code'], $where . '.code');
    }

    /** '1g' -> '1', '3' -> '3': lettered lines belong to their numbered group. */
    private static function lineNumber(string $line): string
    {
        return rtrim($line, 'abcdefg');
    }
}
