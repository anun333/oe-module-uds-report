<?php

/**
 * Tables 6B and 7 from hand-made eCQM results: the manual's column logic, the
 * multi-rate lines, and the Table 7 race and ethnicity lines.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Report\QualityMeasures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityMeasuresTest extends TestCase
{
    /** @param array<string, int> $counts */
    private static function r(int $pid, string $set, array $counts): array
    {
        return ['pid' => $pid, 'set' => $set] + $counts + ['IPP' => 1, 'DENOM' => 0, 'DENEX' => 0, 'DENEXCEP' => 0, 'NUMER' => 0, 'NUMEX' => 0];
    }

    public function testTheManualsColumnLogic(): void
    {
        $s = 'PopulationSet_1';
        $results = [
            self::r(1, $s, ['DENOM' => 1, 'NUMER' => 1]),                     // counted, met
            self::r(2, $s, ['DENOM' => 1]),                                   // counted, not met
            self::r(3, $s, ['DENOM' => 1, 'DENEX' => 1]),                     // excluded
            self::r(4, $s, ['DENOM' => 1, 'DENEXCEP' => 1]),                  // exception, not met: removed
            self::r(5, $s, ['DENOM' => 1, 'DENEXCEP' => 1, 'NUMER' => 1]),    // exception but met: stays
            self::r(6, $s, ['DENOM' => 1, 'NUMER' => 1, 'NUMEX' => 1]),       // numerator exclusion
            self::r(7, $s, ['IPP' => 1]),                                     // initial population only
            self::r(8, $s, ['DENOM' => 1, 'NUMER' => 1]),                     // no countable UDS visit
        ];
        $m = new QualityMeasures($results, [1, 2, 3, 4, 5, 6, 7]);
        self::assertSame(['A' => 4, 'B' => 4, 'C' => 2], $m->line(), 'A: 1, 2, 5, 6; C: 1 and 5');
    }

    /**
     * Guards the outcome: lines read their population sets by exact id, so a
     * stratification's rows add no patient whether or not they are filtered out.
     */
    public function testStratificationResultsAddNoPatients(): void
    {
        $results = [
            self::r(1, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 1]),
            self::r(1, 'PopulationSet_1_Stratification_1', ['DENOM' => 1, 'NUMER' => 1]),
            self::r(2, 'PopulationSet_1_Stratification_2', ['DENOM' => 1, 'NUMER' => 1]),
        ];
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], (new QualityMeasures($results, [1, 2]))->line());
    }

    public function testLine12NeedsAllThreeNumerators(): void
    {
        [, $sets] = QualityMeasures::LINE_SETS['12'];
        $results = [];
        // Child 1 has all three; child 2 lacks physical-activity counseling; child 3 only BMI.
        foreach ([1 => [1, 1, 1], 2 => [1, 1, 0], 3 => [1, 0, 0]] as $pid => $numer) {
            foreach ($sets as $i => $set) {
                $results[] = self::r($pid, $set, ['DENOM' => 1, 'NUMER' => $numer[$i]]);
            }
        }
        self::assertSame(['A' => 3, 'B' => 3, 'C' => 1], (new QualityMeasures($results, [1, 2, 3]))->line($sets));
    }

    public function testLine14aReportsTheCombinedRate(): void
    {
        [, $sets] = QualityMeasures::LINE_SETS['14a'];
        $results = [
            // Screened, a user, no intervention: set 1 met, set 3 not.
            self::r(1, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 1]),
            self::r(1, 'PopulationSet_3', ['DENOM' => 1, 'NUMER' => 0]),
            // Screened, not a user: set 3 met.
            self::r(2, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 1]),
            self::r(2, 'PopulationSet_3', ['DENOM' => 1, 'NUMER' => 1]),
        ];
        self::assertSame(['A' => 2, 'B' => 2, 'C' => 1], (new QualityMeasures($results, [1, 2]))->line($sets));
    }

    public function testLines23aAnd23bShareTheDenominatorAnd23bIsASubset(): void
    {
        $results = [
            self::r(1, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 1]),   // initiated and engaged
            self::r(1, 'PopulationSet_2', ['DENOM' => 1, 'NUMER' => 1]),
            self::r(2, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 1]),   // initiated only
            self::r(2, 'PopulationSet_2', ['DENOM' => 1, 'NUMER' => 0]),
            self::r(3, 'PopulationSet_1', ['DENOM' => 1, 'NUMER' => 0]),   // neither; set 2 says engaged, which 23b ignores
            self::r(3, 'PopulationSet_2', ['DENOM' => 1, 'NUMER' => 1]),
        ];
        $m = new QualityMeasures($results, [1, 2, 3]);
        $a = $m->line(QualityMeasures::LINE_SETS['23a'][1]);
        $b = $m->line(QualityMeasures::LINE_SETS['23b'][1]);
        self::assertSame(['A' => 3, 'B' => 3, 'C' => 2], $a);
        self::assertSame(['A' => 3, 'B' => 3, 'C' => 1], $b);
        self::assertSame($a['A'], $b['A'], 'the manual: the denominator for 23a and 23b must be the same');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function crosswalk(): iterable
    {
        // From the 2026 UDS Manual, Appendix B.
        yield 'Asian Indian, Mexican' => ['1a', 'A1', '1a1m'];
        yield 'Other Asian, Hispanic combined' => ['1g', 'A5', '1a7o'];
        yield 'Chinese, not Hispanic' => ['1b', 'B', '2a2'];
        yield 'Samoan, Cuban' => ['2d', 'A3', '1b4c'];
        yield 'Guamanian or Chamorro, not Hispanic' => ['2c', 'B', '2b3'];
        yield 'Black, Puerto Rican' => ['3', 'A2', '1cp'];
        yield 'American Indian/Alaska Native, another Spanish origin' => ['4', 'A4', '1da'];
        yield 'White, not Hispanic' => ['5', 'B', '2e'];
        yield 'More than one race, Mexican' => ['6', 'A1', '1fm'];
        yield 'Unreported race, Hispanic combined' => ['7', 'A5', '1go'];
        yield 'Unreported race, not Hispanic' => ['7', 'B', '2g'];
        yield 'Unreported race and ethnicity' => ['7', 'C', 'h'];
    }

    #[DataProvider('crosswalk')]
    public function testTable7Line(string $line, string $column, string $expected): void
    {
        self::assertSame($expected, QualityMeasures::table7Line($line, $column));
    }

    public function testTable7CellIsTheInverseForEveryCell(): void
    {
        $lines = [...array_map(static fn(string $l): string => '1' . $l, range('a', 'g')), ...array_map(static fn(string $l): string => '2' . $l, range('a', 'd')), '3', '4', '5', '6', '7'];
        $seen = [];
        foreach ($lines as $line) {
            foreach (['A1', 'A2', 'A3', 'A4', 'A5', 'B'] as $column) {
                $t7 = QualityMeasures::table7Line($line, $column);
                self::assertSame(['line' => $line, 'column' => $column], QualityMeasures::table7Cell($t7), $t7);
                $seen[$t7] = true;
            }
        }
        self::assertSame(['line' => '7', 'column' => 'C'], QualityMeasures::table7Cell('h'));
        self::assertCount(16 * 6, $seen, 'every Table 3B cell has its own Table 7 line');
        self::assertNull(QualityMeasures::table7Cell('2em'), 'a not-Hispanic line has no subgroup letter');
        self::assertNull(QualityMeasures::table7Cell('1e'), 'a Hispanic line needs one');
        self::assertNull(QualityMeasures::table7Cell('total'));
    }

    public function testTable7LineRejectsWhatIsNotACell(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QualityMeasures::table7Line('8', 'B');
    }

    public function testTable7GroupsByRaceAndEthnicityWithSubtotals(): void
    {
        $s = 'PopulationSet_1';
        // CMS165-style: numerator met means blood pressure under control.
        $results = [
            self::r(1, $s, ['DENOM' => 1, 'NUMER' => 1]),
            self::r(2, $s, ['DENOM' => 1, 'NUMER' => 0]),
            self::r(3, $s, ['DENOM' => 1, 'NUMER' => 1]),
            self::r(4, $s, ['DENOM' => 1, 'DENEX' => 1]),   // excluded: on no line
            self::r(5, $s, ['DENOM' => 1, 'NUMER' => 1]),
        ];
        $placement = [
            1 => ['line' => '5', 'column' => 'A1'],   // 1em
            2 => ['line' => '5', 'column' => 'A1'],   // 1em
            3 => ['line' => '3', 'column' => 'B'],    // 2c
            4 => ['line' => '5', 'column' => 'B'],
            5 => ['line' => '7', 'column' => 'C'],    // h
        ];
        $t = (new QualityMeasures($results, [1, 2, 3, 4, 5]))->table7($placement);
        self::assertSame(['A' => 2, 'B' => 2, 'C' => 1], $t['1em']);
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], $t['2c']);
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], $t['h']);
        self::assertArrayNotHasKey('2e', $t, 'the excluded patient is on no line');
        self::assertSame(['A' => 2, 'B' => 2, 'C' => 1], $t['subtotal_hispanic']);
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], $t['subtotal_not_hispanic']);
        self::assertSame(['A' => 4, 'B' => 4, 'C' => 3], $t['total']);
    }

    public function testTable7RowsFollowTheManualsOrder(): void
    {
        $s = 'PopulationSet_1';
        $placement = [1 => ['line' => '5', 'column' => 'B'], 2 => ['line' => '5', 'column' => 'A4'], 3 => ['line' => '5', 'column' => 'A1'], 4 => ['line' => '7', 'column' => 'C'], 5 => ['line' => '1a', 'column' => 'A3']];
        $results = array_map(static fn(int $pid): array => self::r($pid, $s, ['DENOM' => 1]), array_keys($placement));
        $keys = array_keys((new QualityMeasures($results, array_keys($placement)))->table7($placement));
        self::assertSame(['1a1c', '1em', '1ea', '2e', 'h', 'subtotal_hispanic', 'subtotal_not_hispanic', 'total'], $keys);
        self::assertCount(16 * 5 + 16 + 1, QualityMeasures::table7Lines());
        self::assertSame($lines = QualityMeasures::table7Lines(), array_values(array_unique($lines)));
    }
}
