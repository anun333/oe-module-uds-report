<?php

/**
 * The Table 6A code notation, and the strict loading of data/table-6a.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataException;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6a;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Table6aCodeTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>, list<string>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function notation(): array
    {
        return [
            'prefix' => ['J45-', ['J45', 'J45.20', 'j45.909'], ['J44.9', 'J4', 'J46']],
            'exact' => ['B20', ['B20', 'b20'], ['B20.1', 'B2']],
            'exact with a dot' => ['F43.0', ['F43.0', 'F430'], ['F43.10', 'F43']],
            'category range' => ['A15- through A19-', ['A15', 'A17.0', 'A19.9'], ['A14.9', 'A20']],
            'range of full codes' => ['B16.0 through B16.2', ['B16.0', 'B16.2'], ['B16.9', 'B15.9']],
            'range to a longer end' => ['G80 through G80.9', ['G80', 'G80.1', 'G80.9'], ['G81.0', 'G79']],
            'numeric range' => ['86701 through 86703', ['86701', '86703'], ['86700', '86704']],
            'x wildcard' => ['D21xx through D29xx', ['D2100', 'D2391', 'D2999'], ['D2099', 'D3000', 'D1206']],
            'upper-case X is a real code' => ['X30-', ['X30.XXXA', 'X30'], ['X31.0']],
            'exclusion' => ['O24- (exclude O24.4-)', ['O24.011', 'O24.9'], ['O24.410', 'O24.4']],
            'exclusion of one code' => ['I02- (exclude I02.9)', ['I02.0'], ['I02.9']],
            'exclusion ranges' => ['Z68- (exclude Z68.0 through Z68.24, Z68.5 through Z68.52)', ['Z68.25', 'Z68.30', 'Z68.45', 'Z68.53', 'Z68.54'], ['Z68.1', 'Z68.20', 'Z68.24', 'Z68.51', 'Z68.52']],
            'exclusions joined by and' => ['F43- through F48- (exclude F43.0- and F43.1-)', ['F43.2', 'F45.1'], ['F43.0', 'F43.10', 'F43.12']],
        ];
    }

    /**
     * @param list<string> $in
     * @param list<string> $out
     */
    #[DataProvider('notation')]
    public function testNotation(string $spec, array $in, array $out): void
    {
        $code = Table6aCode::parse($spec, 'test');
        foreach ($in as $c) {
            self::assertTrue($code->matches($c), "$c should match $spec");
        }
        foreach ($out as $c) {
            self::assertFalse($code->matches($c), "$c should not match $spec");
        }
    }

    public function testUnlessCarriesTheOtherCode(): void
    {
        $code = Table6aCode::parse('J40 (unless U07.1 at the same visit)', 'test');
        self::assertTrue($code->matches('J40'));
        self::assertSame('U071', $code->unless);
    }

    public function testTheShippedFileLoads(): void
    {
        $lines = (new ReferenceDataLoader())->load()->table6a->lines;
        $ids = array_map(static fn($l): string => $l->line, $lines);
        self::assertCount(60, $lines);
        self::assertSame($ids, array_values(array_unique($ids)));
        $uncounted = array_values(array_map(static fn($l): string => $l->line, array_filter($lines, static fn($l): bool => !$l->isCounted())));
        self::assertSame(['26c2', '26c3', '38', '39', '40', '41', '42'], $uncounted);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function brokenFiles(): array
    {
        $line = static fn(array $d, array $patch): array => ['lines' => [array_merge($d['lines'][0], $patch)]] + $d;
        return [
            'a code that is not a code' => [static fn(array $d): array => $line($d, ['codes' => ['icd10' => ['B20 or B21']]]), 'is not a code'],
            'a range that runs backwards' => [static fn(array $d): array => $line($d, ['codes' => ['icd10' => ['A19- through A15-']]]), 'runs backwards'],
            'an unknown code system' => [static fn(array $d): array => $line($d, ['codes' => ['icd9' => ['250']]]), 'unknown field "icd9"'],
            'a partial line without a limit' => [static fn(array $d): array => $line($d, ['computed' => 'partial', 'limit' => null]), 'needs a limit'],
            'an uncounted line with codes' => [static fn(array $d): array => $line($d, ['computed' => 'none', 'limit' => 'x']), 'must have no codes'],
            'a duplicated line' => [static fn(array $d): array => ['lines' => [$d['lines'][0], $d['lines'][0]]] + $d, 'appears twice'],
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenFiles')]
    public function testABrokenFileStopsTheLoad(callable $break, string $message): void
    {
        $data = (new ReferenceDataLoader())->read(Table6a::FILE);
        self::assertIsArray($data);
        $broken = $break($data);
        if (array_key_exists('limit', $broken['lines'][0]) && $broken['lines'][0]['limit'] === null) {
            unset($broken['lines'][0]['limit']);
        }
        $this->expectException(ReferenceDataException::class);
        $this->expectExceptionMessage($message);
        Table6a::fromData($broken);
    }
}
