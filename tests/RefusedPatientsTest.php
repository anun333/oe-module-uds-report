<?php

/**
 * A calculation that goes on when OpenEMR's eCQM engine refuses some patients:
 * retried one patient at a time, only for refusals, with the engine's reason.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Report\EcqmChecks;
use OpenEMR\Modules\UdsReport\Report\RefusedPatients;
use PHPUnit\Framework\TestCase;

final class RefusedPatientsTest extends TestCase
{
    /** @var list<list<int>> every call the fake engine got */
    private array $calls = [];

    /**
     * A fake engine: refuses any call that includes a patient in $refuse, the way
     * OpenEMR's wraps "Invalid UCUM unit" in "Measure ... cannot be calculated".
     *
     * @param list<int> $refuse
     */
    private function engine(array $refuse, ?\Throwable $other = null): callable
    {
        return function (array $pids) use ($refuse, $other): array {
            $this->calls[] = $pids;
            if ($other !== null) {
                throw $other;
            }
            foreach ($pids as $pid) {
                if (in_array($pid, $refuse, true)) {
                    throw new \RuntimeException('Measure CMS165v14 cannot be calculated', 0, new \InvalidArgumentException("Invalid UCUM unit: 'mEq/L'."));
                }
            }
            return ['CMS165v14' => array_map(static fn(int $p): array => ['pid' => $p, 'set' => 'PopulationSet_1', 'IPP' => 1], $pids)];
        };
    }

    private static function isRefusal(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'cannot be calculated');
    }

    public function testABatchTheEngineAcceptsIsOneCall(): void
    {
        $out = RefusedPatients::calculate([1, 2, 3], $this->engine([]), self::isRefusal(...));
        self::assertSame([[1, 2, 3]], $this->calls);
        self::assertSame([1, 2, 3], array_column($out['results']['CMS165v14'], 'pid'));
        self::assertSame([], $out['refused']);
    }

    public function testARefusedBatchIsTriedPatientByPatientAndOnlyTheRefusedAreLeftOut(): void
    {
        $out = RefusedPatients::calculate([1, 2, 3], $this->engine([2]), self::isRefusal(...));
        self::assertSame([[1, 2, 3], [1], [2], [3]], $this->calls);
        self::assertSame([1, 3], array_column($out['results']['CMS165v14'], 'pid'));
        self::assertSame([['pid' => 2, 'reason' => "Invalid UCUM unit: 'mEq/L'."]], $out['refused']);
    }

    public function testEveryPatientRefusedLeavesNoResults(): void
    {
        $out = RefusedPatients::calculate([4, 5], $this->engine([4, 5]), self::isRefusal(...));
        self::assertSame([], $out['results']);
        self::assertSame([4, 5], array_column($out['refused'], 'pid'));
    }

    public function testAnErrorThatIsNotARefusalStopsWithoutRetrying(): void
    {
        $db = new \RuntimeException('MySQL server has gone away');
        try {
            RefusedPatients::calculate([1, 2, 3], $this->engine([], $db), self::isRefusal(...));
            self::fail('the error was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame($db, $e);
        }
        self::assertSame([[1, 2, 3]], $this->calls, 'no per-patient retries for an error that is not a refusal');
    }

    public function testAnErrorDuringTheRetriesStillStops(): void
    {
        $failOnRetry = function (array $pids): array {
            $this->calls[] = $pids;
            if (count($pids) > 1) {
                throw new \RuntimeException('cannot be calculated');
            }
            throw new \LogicException('the database went away during the retries');
        };
        $this->expectException(\LogicException::class);
        RefusedPatients::calculate([1, 2], $failOnRetry, self::isRefusal(...));
    }

    public function testTheReasonIsTheInnermostCause(): void
    {
        $e = new \RuntimeException('outer', 0, new \RuntimeException('middle', 0, new \InvalidArgumentException("Invalid UCUM unit: 'UNK'.")));
        self::assertSame("Invalid UCUM unit: 'UNK'.", RefusedPatients::reason($e));
        self::assertSame('only', RefusedPatients::reason(new \RuntimeException('only')));
        self::assertSame(\RuntimeException::class, RefusedPatients::reason(new \RuntimeException('')));
    }

    public function testOnlyOpenEmrsOwnRefusalCounts(): void
    {
        self::assertFalse(RefusedPatients::isEngineRefusal(new \RuntimeException('Measure X cannot be calculated by the eCQM calculation engine')));
    }

    public function testRefusalsAreGroupedByReasonOnceEachMostCommonFirst(): void
    {
        $grouped = RefusedPatients::byReason([
            ['pid' => 7, 'reason' => 'b'], ['pid' => 3, 'reason' => 'a'], ['pid' => 9, 'reason' => 'b'], ['pid' => 7, 'reason' => 'b'],
        ]);
        self::assertSame(['b' => [7, 9], 'a' => [3]], $grouped);
    }

    public function testRejectedUnitsAreTheOnesTheEngineRefusesAndAnEmptyUnitIsNotOne(): void
    {
        $byUnit = [
            'mg/dL' => ['results' => 50, 'patients' => 9],
            'mEq/L' => ['results' => 4, 'patients' => 2],
            'UNK' => ['results' => 30, 'patients' => 5],
            '' => ['results' => 7, 'patients' => 3],
        ];
        $rejected = EcqmChecks::rejectedUnits($byUnit, static fn(string $u): bool => $u === 'mg/dL');
        self::assertSame(['UNK', 'mEq/L'], array_keys($rejected), 'most results first; no entry for results with no unit');
    }

    public function testEachUcumSuggestionReplacesARealUnitWithADifferentOne(): void
    {
        foreach (EcqmChecks::UCUM as $from => $to) {
            self::assertNotSame('', trim($from));
            self::assertNotSame($from, $to);
        }
        self::assertSame('meq/L', EcqmChecks::UCUM['mEq/L']);
    }
}
