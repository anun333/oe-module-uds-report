<?php

/**
 * Table 6A against the hand-worked cases in data/cases/table-6a.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use DateTimeImmutable;
use OpenEMR\Modules\UdsReport\Model\EncounterCodes;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\Table6A;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Table6ATest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function cases(): array
    {
        return CaseFile::cases('table-6a', 'cases');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $encounters = [];
        foreach (CaseFile::list($case['encounters'] ?? null) as $raw) {
            $e = CaseFile::object($raw);
            $codes = [];
            foreach (CaseFile::object($e['codes'] ?? []) as $system => $list) {
                $codes[$system] = CaseFile::strings($list);
            }
            $birth = CaseFile::nullableString($e['birthDate'] ?? null);
            $encounters[] = new EncounterCodes(
                CaseFile::int($e['pid'] ?? null),
                CaseFile::int($e['encounter'] ?? null),
                new DateTimeImmutable(CaseFile::string($e['date'] ?? null)),
                CaseFile::bool($e['countable'] ?? null),
                CaseFile::bool($e['inScope'] ?? null),
                $birth === null ? null : new DateTimeImmutable($birth),
                $codes,
            );
        }
        $patients = [];
        foreach (CaseFile::list($case['patients'] ?? null) as $pid) {
            $patients[CaseFile::int($pid)] = true;
        }
        $expected = CaseFile::object($case['expected'] ?? null);

        $table = new Table6A((new ReferenceDataLoader())->load()->table6a, $encounters, $patients);
        $seen = [];
        foreach ($table->rows() as $row) {
            $line = $row['line']->line;
            $got = $row['visits'] === null ? null : [$row['visits'], $row['patients']];
            if (array_key_exists($line, $expected)) {
                $want = $expected[$line] === null ? null : array_map(CaseFile::int(...), CaseFile::list($expected[$line]));
                self::assertSame($want, $got, "Line $line");
                $seen[$line] = true;
            } elseif ($row['line']->isCounted()) {
                self::assertSame([0, 0], $got, "Line $line should be empty");
            } else {
                self::assertNull($got, "Line $line is not counted");
            }
        }
        $wanted = array_map('strval', array_keys($expected));
        $found = array_map('strval', array_keys($seen));
        sort($wanted);
        sort($found);
        self::assertSame($wanted, $found, 'every expected line exists in table-6a.json');
    }
}
