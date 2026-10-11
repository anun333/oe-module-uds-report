<?php

/**
 * Table 5 against the hand-worked cases in data/cases/table-5.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Model\Provider;
use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\Table5;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Table5Test extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function cases(): array
    {
        return CaseFile::cases('table-5', 'cases');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $reference = (new ReferenceDataLoader())->load();
        $providers = [];
        foreach (CaseFile::list($case['providers'] ?? null) as $raw) {
            $p = CaseFile::object($raw);
            $id = CaseFile::int($p['id'] ?? null);
            $providers[$id] = new Provider($id, CaseFile::string($p['name'] ?? null), CaseFile::nullableString($p['taxonomy'] ?? null) ?? '');
        }
        $visits = [];
        foreach (CaseFile::list($case['visits'] ?? null) as $raw) {
            $v = CaseFile::object($raw);
            $codes = [];
            foreach (['cpt', 'hcpcs'] as $system) {
                if (isset($v[$system])) {
                    $codes[$system] = CaseFile::strings($v[$system]);
                }
            }
            $visits[] = new Table5Visit(
                CaseFile::int($v['pid'] ?? null),
                CaseFile::int($v['encounter'] ?? null),
                CaseFile::string($v['day'] ?? null),
                CaseFile::int($v['site'] ?? null),
                CaseFile::int($v['provider'] ?? null),
                CaseFile::int($v['category'] ?? null),
                CaseFile::nullableString($v['pos'] ?? null),
                CaseFile::nullableString($v['class'] ?? null) ?? 'AMB',
                $codes,
                isset($v['modifiers']) ? CaseFile::strings($v['modifiers']) : [],
                CaseFile::nullableString($v['dx'] ?? null),
            );
        }
        $settings = CaseFile::object($case['settings'] ?? []);
        $patients = [];
        foreach (CaseFile::ints($case['patients'] ?? []) as $pid) {
            $patients[$pid] = true;
        }

        $result = (new Table5(
            $reference->table5,
            $reference->providerTaxonomy,
            $visits,
            $providers,
            new UdsSettings(
                providerLines: self::lineMap($settings['providerLines'] ?? []),
                categoryLines: self::lineMap($settings['categoryLines'] ?? []),
            ),
            $patients,
        ))->result();

        $expected = CaseFile::object($case['expected'] ?? null);
        $found = [];
        foreach ($result['rows'] as $row) {
            $line = $row['line'];
            $got = [$row['visits'], $row['virtual'], $row['patients']];
            if (array_key_exists($line->line, $expected)) {
                $want = array_map(CaseFile::nullableInt(...), CaseFile::list($expected[$line->line]));
                self::assertSame($want, $got, "Line {$line->line}");
                $found[] = (string) $line->line;
                continue;
            }
            self::assertSame($row['visits'] === null ? null : 0, $row['visits'], "Line {$line->line} clinic visits");
            self::assertSame($row['virtual'] === null ? null : 0, $row['virtual'], "Line {$line->line} virtual visits");
            self::assertSame($row['patients'] === null ? null : 0, $row['patients'], "Line {$line->line} patients");
        }
        $wanted = array_map('strval', array_keys($expected));
        sort($wanted);
        sort($found);
        self::assertSame($wanted, $found, 'every expected line exists in table-5.json');

        $review = CaseFile::object($case['review'] ?? null);
        self::assertSame(CaseFile::int($review['notCounted'] ?? null), $result['notCounted'], 'encounters not counted');
        self::assertSame(CaseFile::int($review['merged'] ?? null), $result['merged'], 'encounters merged into another visit');
        self::assertSame(CaseFile::int($review['behavioralWithoutDiagnosis'] ?? null), $result['behavioralWithoutDiagnosis'], 'behavioral visits without a diagnosis');
        self::assertSame(CaseFile::ints($review['patientsWithoutVisit'] ?? []), $result['patientsWithoutVisit'], 'UDS patients with no Table 5 visit');
    }

    /** @return array<int, string> */
    private static function lineMap(mixed $value): array
    {
        $map = [];
        foreach (CaseFile::object($value) as $id => $line) {
            $map[CaseFile::int(is_numeric($id) ? (int) $id : null)] = CaseFile::string($line);
        }
        return $map;
    }
}
