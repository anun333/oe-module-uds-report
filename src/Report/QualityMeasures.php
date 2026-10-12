<?php

/**
 * Tables 6B and 7 from OpenEMR's eCQM results: which patients count in each
 * column, by the 2026 UDS Manual's column logic (pages 84-85), and which
 * population set each line reports. Kept apart from the engine and the database
 * so the rules can be tested with hand-made results.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class QualityMeasures
{
    /**
     * The population sets each line's numerator needs, all of them for the same
     * patient. The denominator is the first set's. Measures not listed report
     * PopulationSet_1. Read from each measure's CQL in the 2026 bundle:
     * - CMS155v14 (line 12, page 98): Numerator 1 is BMI percentile with height
     *   and weight, 2 counseling for nutrition, 3 counseling for physical
     *   activity; UDS counts a child with all three.
     * - CMS138v14 (line 14a, page 101): Numerator 3 is "not a user, or a user
     *   who received cessation intervention".
     * - CMS137v14 (lines 23a and 23b, page 114): Numerator 1 is initiation,
     *   2 engagement, over the same denominator.
     */
    public const LINE_SETS = [
        '12' => ['CMS155v14', ['PopulationSet_1', 'PopulationSet_2', 'PopulationSet_3']],
        '14a' => ['CMS138v14', ['PopulationSet_3']],
        '23a' => ['CMS137v14', ['PopulationSet_1']],
        '23b' => ['CMS137v14', ['PopulationSet_1', 'PopulationSet_2']],
    ];

    /** Table 3B ethnicity columns A1-A5 as the letter ending a Hispanic Table 7 line (Appendix B). */
    private const HISPANIC_SUFFIX = ['A1' => 'm', 'A2' => 'p', 'A3' => 'c', 'A4' => 'a', 'A5' => 'o'];

    /** Table 3B race lines 3-7 as the letter in a Table 7 line (Appendix B). */
    private const RACE_LETTER = ['3' => 'c', '4' => 'd', '5' => 'e', '6' => 'f', '7' => 'g'];

    /** @var array<int, array<string, array<string, int>>> pid => set => population => count */
    private array $byPatient = [];

    /**
     * @param list<array<string, mixed>> $results one measure's engine results: pid, set (the
     *        population set id), and the population counts IPP, DENOM, DENEX, DENEXCEP, NUMER, NUMEX
     * @param list<int> $udsPatients patients with a countable UDS visit in the year
     */
    public function __construct(array $results, array $udsPatients)
    {
        $uds = array_fill_keys($udsPatients, true);
        foreach ($results as $r) {
            $pid = $r['pid'] ?? null;
            $set = $r['set'] ?? null;
            // Stratifications repeat a set for a subgroup; UDS reports the whole set.
            if (!is_int($pid) || !is_string($set) || !isset($uds[$pid]) || str_contains($set, '_Stratification_')) {
                continue;
            }
            foreach (['IPP', 'DENOM', 'DENEX', 'DENEXCEP', 'NUMER', 'NUMEX'] as $population) {
                $value = $r[$population] ?? 0;
                $this->byPatient[$pid][$set][$population] = is_int($value) ? $value : 0;
            }
        }
    }

    /**
     * Whether a patient is in the line's denominator (Column A) and numerator (Column C).
     * Denominator: in the first set's denominator, not excluded, and not an exception
     * unless they meet the numerator. Numerator: in every listed set's numerator and not
     * a numerator exclusion. Each patient counts once.
     *
     * @param list<string> $sets
     * @return array{denominator: bool, numerator: bool}
     */
    public function patient(int $pid, array $sets): array
    {
        $first = $this->byPatient[$pid][$sets[0]] ?? [];
        $met = true;
        foreach ($sets as $set) {
            $p = $this->byPatient[$pid][$set] ?? [];
            if (($p['NUMER'] ?? 0) <= 0 || ($p['NUMEX'] ?? 0) > 0) {
                $met = false;
            }
        }
        $denominator = ($first['DENOM'] ?? 0) > 0
            && ($first['DENEX'] ?? 0) <= 0
            && !(($first['DENEXCEP'] ?? 0) > 0 && !$met);
        return ['denominator' => $denominator, 'numerator' => $denominator && $met];
    }

    /**
     * A Table 6B line's cells. Column B equals Column A: the whole EHR is reviewed.
     *
     * @param list<string> $sets
     * @return array{A: int, B: int, C: int}
     */
    public function line(array $sets = ['PopulationSet_1']): array
    {
        $a = 0;
        $c = 0;
        foreach (array_keys($this->byPatient) as $pid) {
            $at = $this->patient($pid, $sets);
            $a += (int)$at['denominator'];
            $c += (int)$at['numerator'];
        }
        return ['A' => $a, 'B' => $a, 'C' => $c];
    }

    /**
     * A Table 7 section's cells by race and ethnicity line, with the subtotals.
     *
     * @param array<int, array{line: string, column: string}> $placement each patient's Table 3B cell
     * @return array<string, array{A: int, B: int, C: int}>
     */
    public function table7(array $placement): array
    {
        $cells = [];
        foreach (array_keys($this->byPatient) as $pid) {
            $at = $this->patient($pid, ['PopulationSet_1']);
            if (!$at['denominator'] || !isset($placement[$pid])) {
                continue;
            }
            $line = self::table7Line($placement[$pid]['line'], $placement[$pid]['column']);
            $cells[$line] ??= ['A' => 0, 'B' => 0, 'C' => 0];
            $cells[$line]['A']++;
            $cells[$line]['B']++;
            $cells[$line]['C'] += (int)$at['numerator'];
        }
        ksort($cells, SORT_NATURAL);
        $sum = static function (callable $keep) use ($cells): array {
            $t = ['A' => 0, 'B' => 0, 'C' => 0];
            foreach ($cells as $line => $c) {
                if ($keep((string)$line)) {
                    $t['A'] += $c['A'];
                    $t['B'] += $c['B'];
                    $t['C'] += $c['C'];
                }
            }
            return $t;
        };
        return $cells + [
            'subtotal_hispanic' => $sum(static fn(string $l): bool => $l[0] === '1'),
            'subtotal_not_hispanic' => $sum(static fn(string $l): bool => $l[0] === '2'),
            'total' => $sum(static fn(string $l): bool => true),
        ];
    }

    /**
     * The Table 7 line for a patient's Table 3B cell (2026 UDS Manual, Appendix B):
     * "1" for Hispanic, "2" for not, then the race (a1-a7 for the Asian lines 1a-1g,
     * b1-b4 for the Native Hawaiian and Pacific Islander lines 2a-2d, then c-g for
     * lines 3-7), then for Hispanic patients the ethnicity column (m, p, c, a, o).
     * Race and ethnicity both unreported is line h.
     */
    public static function table7Line(string $line, string $column): string
    {
        if ($column === 'C') {
            return 'h';
        }
        $race = match (true) {
            preg_match('/^1([a-g])$/D', $line, $m) === 1 => 'a' . (ord($m[1]) - ord('a') + 1),
            preg_match('/^2([a-d])$/D', $line, $m) === 1 => 'b' . (ord($m[1]) - ord('a') + 1),
            isset(self::RACE_LETTER[$line]) => self::RACE_LETTER[$line],
            default => throw new \InvalidArgumentException("not a Table 3B race line: $line"),
        };
        if ($column === 'B') {
            return '2' . $race;
        }
        if (!isset(self::HISPANIC_SUFFIX[$column])) {
            throw new \InvalidArgumentException("not a Table 3B ethnicity column: $column");
        }
        return '1' . $race . self::HISPANIC_SUFFIX[$column];
    }
}
