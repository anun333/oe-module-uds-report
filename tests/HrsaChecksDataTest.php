<?php

/**
 * The shipped checks file (data/hrsa-checks.json) and last year's published figures
 * (data/hrsa-published-YEAR.jsonl.gz): they load, every cell a check reads is one the
 * module computes, and a real health center's published report reads back.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\DemographicTables;
use OpenEMR\Modules\UdsReport\Report\HrsaChecks;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\PublishedUds;
use OpenEMR\Modules\UdsReport\Report\QualityMeasures;
use OpenEMR\Modules\UdsReport\Report\UdsFigures;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use PHPUnit\Framework\TestCase;

final class HrsaChecksDataTest extends TestCase
{
    /** Every code the module produces for 2026, from empty tables with every line. */
    private static function producible(): array
    {
        $reference = (new ReferenceDataLoader())->load();
        $tables = new DemographicTables([], 2026, new UdsSettings(), $reference);
        $t5 = array_map(static fn($l): array => ['line' => $l, 'visits' => 0, 'virtual' => 0, 'patients' => 0], $reference->table5->lines);
        $t6a = array_map(
            static fn($l): array => ['line' => $l, 'visits' => $l->isCounted() ? 0 : null, 'patients' => $l->isCounted() ? 0 : null],
            $reference->table6a->lines,
        );
        $t6b = [];
        foreach (QualityMeasures::lines6B($reference->ecqmMeasures->forYear(2026)) as $l) {
            $t6b[$l['line']] = ['A' => 0, 'B' => 0, 'C' => 0];
        }
        $t7 = [];
        foreach ($reference->ecqmMeasures->forYear(2026) as $e) {
            if ($e['table'] === '7') {
                $t7[$e['measure']] = ['A' => 0, 'B' => 0, 'C' => 0];
            }
        }
        return UdsFigures::from($tables->table3A(), $tables->table3B(), $tables->table4(), Labels::raceLines(), [], $t5, $t6a, $t6b, $t7)->values;
    }

    public function testEveryCellACheckReadsIsOneTheModuleComputes(): void
    {
        $codes = self::producible();
        $checks = HrsaChecks::load();
        self::assertGreaterThan(30, count($checks->checks), 'the file has its checks');
        foreach ($checks->checks as $check) {
            foreach ([...$check['a'], ...($check['b'] ?? []), ...($check['when'] ?? [])] as $code) {
                self::assertArrayHasKey($code, $codes, "{$check['id']} reads $code");
            }
        }
    }

    public function testEachThresholdHasTheShapeItsKindNeeds(): void
    {
        $checks = HrsaChecks::load();
        self::assertSame(2025, $checks->nationalYear);
        foreach ($checks->checks as $check) {
            self::assertNotSame('', $check['text']);
            foreach ($check['reports'] as $report => $entry) {
                self::assertContains($report, ['universal', 'hch', 'msaw', 'phpc']);
                self::assertNotSame([], $entry['edits'], "{$check['id']} names HRSA's edit");
                $t = $entry['threshold'] ?? null;
                $pair = in_array($check['kind'], ['share_outside', 'change'], true)
                    || ($check['kind'] === 'national' && ($check['direction'] ?? 'both') === 'both');
                if ($check['kind'] === 'greater') {
                    self::assertNull($t);
                } elseif ($pair) {
                    self::assertIsArray($t, $check['id']);
                    self::assertCount(2, $t, $check['id']);
                } else {
                    self::assertIsNumeric($t, $check['id']);
                }
                if ($check['kind'] === 'national') {
                    self::assertGreaterThan(0, $entry['national'], "{$check['id']} has its national average");
                }
                self::assertGreaterThanOrEqual(0.75, $entry['fit']['precision'], "{$check['id']} ($report) agreed with HRSA often enough to ship");
            }
        }
    }

    public function testPublishedFiguresReadBackForOneHealthCenter(): void
    {
        $files = PublishedUds::files();
        self::assertArrayHasKey(2025, $files);
        // Pohnpei Community Health Center, which reports OpenEMR as its EHR in the same data.
        $pohnpei = PublishedUds::find($files[2025], 'H80CS04302');
        self::assertNotNull($pohnpei);
        self::assertSame('Pohnpei Community Health Center', $pohnpei->displayName());
        self::assertSame('FM', $pohnpei->state);
        self::assertFalse($pohnpei->lookAlike);
        self::assertSame(2025, $pohnpei->year);
        self::assertGreaterThan(1000, $pohnpei->values['T4_L6_Ca'], 'total patients');
        self::assertSame($pohnpei->values['T4_L6_Ca'], $pohnpei->values['T3a_L39_Ca'] + $pohnpei->values['T3a_L39_Cb'], 'Table 3A and Table 4 agree');
        self::assertContains(null, $pohnpei->values, 'small counts are hidden, not zero');
        self::assertArrayNotHasKey('T5_L20_Cc', $pohnpei->values, 'HRSA withheld its Table 5 (---): left out, not zeros');
    }

    public function testALookAlikeAndAMissingNumber(): void
    {
        $file = PublishedUds::files()[2025];
        $lookAlike = PublishedUds::find($file, 'LALCS00007');
        self::assertNotNull($lookAlike);
        self::assertTrue($lookAlike->lookAlike);
        self::assertNull(PublishedUds::find($file, 'H80CS99999'));
        self::assertNull(PublishedUds::find($file, 'LALCS00007', 'hch'), 'a look-alike files no Grant Report');
    }

    public function testATableHrsasFileHasNoRowForIsLeftOutNotReadAsZeros(): void
    {
        // HRSA's 2024 file has no Table 5 row for 645 health centers.
        $file = sys_get_temp_dir() . '/hrsa-published-test-' . getmypid() . '.jsonl.gz';
        file_put_contents($file, gzencode(
            json_encode(['year' => 2024, 'codes' => ['universal' => ['T4_L6_Ca', 'T5_L20_Cc', 'T6a_L26f_Cb']]]) . "\n"
            . json_encode(['grant' => 'H80CS00001', 'name' => 'A', 'state' => 'TX', 'lookAlike' => false,
                'reports' => ['universal' => [500, 0, null]], 'missing' => ['universal' => ['T5']]]) . "\n",
        ));
        try {
            $org = PublishedUds::find($file, 'H80CS00001');
            self::assertNotNull($org);
            self::assertSame(['T4_L6_Ca' => 500, 'T6a_L26f_Cb' => null], $org->values);
        } finally {
            unlink($file);
        }
    }

    public function testGrantNumbersAreReadAsHrsaWritesThem(): void
    {
        self::assertSame('H80CS04302', PublishedUds::normalizeGrant(' h80cs04302 '));
        self::assertSame('H80CS04302', PublishedUds::normalizeGrant('H80-CS-04302'));
        self::assertSame('LALCS00007', PublishedUds::normalizeGrant('lalcs00007'));
        self::assertNull(PublishedUds::normalizeGrant('Pohnpei'));
        self::assertNull(PublishedUds::normalizeGrant(''));
        self::assertNull(PublishedUds::normalizeGrant('H80CS04302; DROP'));
    }
}
