<?php

/**
 * HRSA's edit checks, each kind against figures on both sides of its threshold, and the
 * reasons a check isn't run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Report\HrsaChecks;
use PHPUnit\Framework\TestCase;

final class HrsaChecksTest extends TestCase
{
    /**
     * @param array<string, mixed> $check
     * @param array<string, int|float> $current
     * @param ?array<string, int|float|null> $prior
     * @return array{raised: list<array<string, mixed>>, passed: list<string>, notRun: list<array{id: string, reason: string}>}
     */
    private static function check(array $check, array $current, ?array $prior = null, int $year = 2026, array $partial = []): array
    {
        $check += ['id' => 'c', 'text' => '{a} {b}'];
        return (new HrsaChecks([$check], 2025))->run('universal', $year, $current, $prior, $partial);
    }

    private static function raised(array $check, array $current, ?array $prior = null, int $year = 2026): bool
    {
        $r = self::check($check, $current, $prior, $year);
        self::assertSame([], $r['notRun'], 'the check ran');
        return $r['raised'] !== [];
    }

    public function testZeroIsRaisedOnlyWhenThePopulationReachesTheThreshold(): void
    {
        $check = ['kind' => 'zero', 'a' => ['X'], 'when' => ['W'], 'reports' => ['universal' => ['threshold' => 20]]];
        self::assertTrue(self::raised($check, ['X' => 0, 'W' => 20]));
        self::assertFalse(self::raised($check, ['X' => 0, 'W' => 19]), 'too few patients for HRSA to expect any');
        self::assertFalse(self::raised($check, ['X' => 3, 'W' => 500]));
    }

    public function testGreaterAndGreaterOrEqual(): void
    {
        $check = ['kind' => 'greater', 'a' => ['A'], 'b' => ['B'], 'reports' => ['universal' => []]];
        self::assertTrue(self::raised($check, ['A' => 6, 'B' => 5]));
        self::assertFalse(self::raised($check, ['A' => 5, 'B' => 5]));
        $orEqual = ['orEqual' => true] + $check;
        self::assertTrue(self::raised($orEqual, ['A' => 5, 'B' => 5]));
        self::assertFalse(self::raised($orEqual, ['A' => 0, 'B' => 0]), 'no virtual visits and no clinic visits is not a question');
    }

    public function testSharesAgainstALimitOrARange(): void
    {
        $above = ['kind' => 'share_above', 'a' => ['A'], 'b' => ['B'], 'reports' => ['universal' => ['threshold' => 0.5]]];
        self::assertTrue(self::raised($above, ['A' => 51, 'B' => 100]));
        self::assertFalse(self::raised($above, ['A' => 50, 'B' => 100]), 'more than half, not half');
        self::assertFalse(self::raised($above, ['A' => 5, 'B' => 0]), 'no total, no share');
        $below = ['kind' => 'share_below', 'a' => ['A'], 'b' => ['B'], 'reports' => ['universal' => ['threshold' => 0.2]]];
        self::assertTrue(self::raised($below, ['A' => 19, 'B' => 100]));
        self::assertFalse(self::raised($below, ['A' => 20, 'B' => 100]));
        $outside = ['kind' => 'share_outside', 'a' => ['A'], 'b' => ['B'], 'reports' => ['universal' => ['threshold' => [0.01, 0.2]]]];
        self::assertTrue(self::raised($outside, ['A' => 21, 'B' => 100]));
        self::assertTrue(self::raised($outside, ['A' => 0, 'B' => 1000]));
        self::assertFalse(self::raised($outside, ['A' => 10, 'B' => 100]));
    }

    public function testASmallerCountThanTheMinimumSkipsTheShare(): void
    {
        $prep = ['kind' => 'share_above', 'a' => ['A'], 'b' => ['B'], 'minA' => 50, 'reports' => ['universal' => ['threshold' => 0.05]]];
        self::assertFalse(self::raised($prep, ['A' => 49, 'B' => 100]), 'under 50 patients is expected');
        self::assertTrue(self::raised($prep, ['A' => 60, 'B' => 1000]));
    }

    public function testChangeNeedsBothTheShareAndTheDifference(): void
    {
        $check = ['kind' => 'change', 'a' => ['A'], 'reports' => ['universal' => ['threshold' => [0.25, 50]]]];
        self::assertTrue(self::raised($check, ['A' => 200], ['A' => 100]));
        self::assertTrue(self::raised($check, ['A' => 40], ['A' => 100]), 'a fall counts as well');
        self::assertFalse(self::raised($check, ['A' => 40], ['A' => 20]), 'doubled, but by fewer than 50');
        self::assertFalse(self::raised($check, ['A' => 120], ['A' => 100]));
        $down = ['direction' => 'down'] + $check;
        self::assertFalse(self::raised($down, ['A' => 200], ['A' => 100]), 'only a fall');
    }

    public function testShareChangeIsInPercentagePoints(): void
    {
        $check = ['kind' => 'share_change', 'direction' => 'down', 'a' => ['A'], 'b' => ['B'], 'reports' => ['universal' => ['threshold' => 0.05]]];
        self::assertTrue(self::raised($check, ['A' => 7, 'B' => 100], ['A' => 13, 'B' => 100]));
        self::assertFalse(self::raised($check, ['A' => 10, 'B' => 100], ['A' => 13, 'B' => 100]));
        self::assertFalse(self::raised($check, ['A' => 20, 'B' => 100], ['A' => 13, 'B' => 100]), 'a rise is the other check');
    }

    public function testNationalAverageAsAMultipleOnlyForTheYearAfterIt(): void
    {
        $check = ['kind' => 'national', 'a' => ['V'], 'b' => ['P'], 'reports' => ['universal' => ['threshold' => [0.5, 1.5], 'national' => 4.0]]];
        self::assertTrue(self::raised($check, ['V' => 700, 'P' => 100]));
        self::assertTrue(self::raised($check, ['V' => 150, 'P' => 100]));
        self::assertFalse(self::raised($check, ['V' => 400, 'P' => 100]));
        self::assertSame([['id' => 'c', 'reason' => HrsaChecks::NOT_THIS_YEAR]], self::check($check, ['V' => 700, 'P' => 100], null, 2027)['notRun']);
        $low = ['kind' => 'national', 'direction' => 'down', 'a' => ['V'], 'b' => ['P'], 'reports' => ['universal' => ['threshold' => 0.7, 'national' => 2.0]]];
        self::assertTrue(self::raised($low, ['V' => 130, 'P' => 100]));
        self::assertFalse(self::raised($low, ['V' => 500, 'P' => 100]));
    }

    public function testWhyAChecksIsNotRun(): void
    {
        $change = ['kind' => 'change', 'a' => ['A'], 'reports' => ['universal' => ['threshold' => [0.25, 50]]]];
        self::assertSame(HrsaChecks::NOT_COMPUTED, self::check($change, ['Other' => 1], ['A' => 1])['notRun'][0]['reason']);
        self::assertSame(HrsaChecks::NO_PRIOR_YEAR, self::check($change, ['A' => 1])['notRun'][0]['reason']);
        self::assertSame(HrsaChecks::HIDDEN, self::check($change, ['A' => 1], ['A' => null])['notRun'][0]['reason']);
        self::assertSame(HrsaChecks::NOT_COMPUTED, self::check($change, ['A' => 1], ['B' => 3])['notRun'][0]['reason'], 'a cell last year did not have');
    }

    public function testACheckWithNoEntryForTheReportIsLeftOut(): void
    {
        $check = ['kind' => 'greater', 'a' => ['A'], 'b' => ['B'], 'reports' => ['hch' => []]];
        self::assertSame(['raised' => [], 'passed' => [], 'notRun' => []], self::check($check, ['A' => 9, 'B' => 1]));
        self::assertSame('hch', HrsaChecks::reportKey(GrantReport::Homeless));
        self::assertSame('msaw', HrsaChecks::reportKey(GrantReport::AgriculturalWorkers));
        self::assertSame('phpc', HrsaChecks::reportKey(GrantReport::PublicHousing));
        self::assertSame('universal', HrsaChecks::reportKey(null));
    }

    public function testTheMessageQuotesTheFigures(): void
    {
        $check = ['kind' => 'change', 'a' => ['A'], 'text' => 'from {prior} to {a} ({change})', 'reports' => ['universal' => ['threshold' => [0.25, 50], 'edits' => ['4143'], 'fit' => ['precision' => 0.64]]]];
        $r = self::check($check, ['A' => 1314], ['A' => 970])['raised'][0];
        self::assertSame('from 970 to 1,314 (+35%)', $r['message']);
        self::assertSame(['4143'], $r['edits']);
        $share = ['kind' => 'share_above', 'a' => ['A'], 'b' => ['B'], 'text' => '{share} ({a} of {b})', 'reports' => ['universal' => ['threshold' => 0.5]]];
        self::assertSame('66.7% (2 of 3)', self::check($share, ['A' => 2, 'B' => 3])['raised'][0]['message']);
    }

    public function testARaisedCheckOnALineCountedInPartSaysSo(): void
    {
        $check = ['kind' => 'zero', 'a' => ['T6a_L26f_Cb'], 'when' => ['W'], 'reports' => ['universal' => ['threshold' => 1]]];
        self::assertTrue(self::check($check, ['T6a_L26f_Cb' => 0, 'W' => 40], null, 2026, ['T6a_L26f_Cb'])['raised'][0]['partial']);
        self::assertFalse(self::check($check, ['T6a_L26f_Cb' => 0, 'W' => 40])['raised'][0]['partial']);
    }
}
