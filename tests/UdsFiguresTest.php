<?php

/**
 * The report's tables under HRSA's field codes, and last year's changes beside them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Report\PriorYearComparison;
use OpenEMR\Modules\UdsReport\Report\UdsFigures;
use PHPUnit\Framework\TestCase;

final class UdsFiguresTest extends TestCase
{
    private static function figures(): UdsFigures
    {
        $line = static fn(string $line, string $label, string $computed = 'full'): object => (object)['line' => $line, 'label' => $label, 'computed' => $computed];
        return UdsFigures::from(
            ['lines' => [1 => ['label' => 'Under age 1', 'male' => 3, 'female' => 4]], 'totalMale' => 30, 'totalFemale' => 40],
            ['cells' => ['7' => ['A1' => 1, 'A' => 1, 'B' => 2, 'C' => 5, 'D' => 8], '8' => ['D' => 70]], 'line12' => 9],
            ['income' => ['5' => 12, '6' => 70], 'insurance' => ['12' => ['child' => 20, 'adult' => 50]], 'special' => ['23' => 6]],
            ['7' => 'Unreported/chose not to disclose race'],
            ['23' => 'Total patients experiencing homelessness'],
            [['line' => $line('20', 'Total Mental Health Services'), 'visits' => 300, 'virtual' => 40, 'patients' => 60],
             ['line' => $line('8', 'Family physicians'), 'visits' => 100, 'virtual' => null, 'patients' => null]],
            [['line' => $line('1-2', 'Symptomatic/asymptomatic HIV'), 'visits' => 2, 'patients' => 1],
             ['line' => $line('26f', 'ADRD screening', 'partial'), 'visits' => 0, 'patients' => 0],
             ['line' => $line('38', 'Not counted', 'none'), 'visits' => null, 'patients' => null]],
            ['13' => ['A' => 45, 'B' => 45, 'C' => 30]],
            ['CMS165v14' => ['A' => 12, 'B' => 12, 'C' => 8], 'CMS122v14' => ['A' => 7, 'B' => 7, 'C' => 2]],
        );
    }

    public function testEachTableUnderHrsasCodes(): void
    {
        $v = self::figures()->values;
        self::assertSame(3, $v['T3a_L1_Ca']);
        self::assertSame(4, $v['T3a_L1_Cb']);
        self::assertSame(30, $v['T3a_L39_Ca']);
        self::assertSame(1, $v['T3b_L7_Ca1']);
        self::assertSame(8, $v['T3b_L7_Cd']);
        self::assertSame(9, $v['T3b_L12_Ca']);
        self::assertSame(12, $v['T4_L5_Ca']);
        self::assertSame(50, $v['T4_L12_Cb']);
        self::assertSame(6, $v['T4_L23_Ca']);
        self::assertSame(300, $v['T5_L20_Cb']);
        self::assertSame(40, $v['T5_L20_Cb2']);
        self::assertSame(60, $v['T5_L20_Cc']);
        self::assertSame(2, $v['T6a_L1and2_Ca'], 'HRSA writes Lines 1-2 as 1and2');
        self::assertSame(45, $v['T6b_L13_Ca']);
        self::assertSame(12, $v['T7_Li_C2a']);
        self::assertSame(8, $v['T7_Li_C2c']);
        self::assertSame(7, $v['T7_Li_C3a']);
        self::assertArrayNotHasKey('T7_Li_C3c', $v, 'the diabetes numerator spreads over several columns');
    }

    public function testCellsTheModuleDoesNotComputeAreLeftOutNotZero(): void
    {
        $f = self::figures();
        self::assertArrayNotHasKey('T5_L8_Cb2', $f->values);
        self::assertArrayNotHasKey('T5_L8_Cc', $f->values);
        self::assertArrayNotHasKey('T6a_L38_Ca', $f->values);
        self::assertSame(0, $f->values['T6a_L26f_Cb'], 'a computed zero is a zero');
        self::assertSame(['T6a_L26f_Ca', 'T6a_L26f_Cb'], $f->partial);
    }

    public function testACellIsDescribedWithItsLine(): void
    {
        $f = self::figures();
        self::assertSame('Table 5, Line 20, Column C (Total Mental Health Services)', $f->describe('T5_L20_Cc'));
        self::assertSame('Table 6A, Line 1-2, Column A (Symptomatic/asymptomatic HIV)', $f->describe('T6a_L1and2_Ca'));
        self::assertSame('Table 4, Line 23, Column A (Total patients experiencing homelessness)', $f->describe('T4_L23_Ca'));
        self::assertSame('Table 7, Line i, Column 2A', $f->describe('T7_Li_C2a'));
    }

    public function testLastYearsBigChangesInTableOrder(): void
    {
        $current = ['T5_L20_Cc' => 314, 'T3a_L1_Ca' => 200, 'T4_L23_Ca' => 120, 'T4_L24_Ca' => 70, 'T4_L25_Ca' => 10, 'T6a_L26f_Cb' => 80];
        $prior = ['T5_L20_Cc' => 97, 'T3a_L1_Ca' => 180, 'T4_L23_Ca' => 60, 'T4_L24_Ca' => 0, 'T4_L25_Ca' => null];
        $c = PriorYearComparison::compare($current, $prior);
        self::assertSame(['T4_L23_Ca', 'T4_L24_Ca', 'T5_L20_Cc'], array_column($c['changed'], 'code'));
        self::assertEqualsWithDelta(1.0, $c['changed'][0]['change'], 1e-9);
        self::assertNull($c['changed'][1]['change'], 'from zero');
        self::assertSame(1, $c['hidden']);
        self::assertSame(4, $c['compared'], 'a cell last year did not have is not compared');
    }
}
