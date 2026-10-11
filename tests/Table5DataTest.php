<?php

/**
 * The strict loading of data/table-5.json and data/table-5-taxonomy.json, the
 * taxonomy suggestions, and how fee sheet modifiers are read.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\ProviderTaxonomy;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataException;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5;
use OpenEMR\Modules\UdsReport\Repository\Table5VisitRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Table5DataTest extends TestCase
{
    public function testTheShippedFilesLoad(): void
    {
        $reference = (new ReferenceDataLoader())->load();
        $lines = $reference->table5->lines;
        self::assertCount(55, $lines);
        $withVisits = array_values(array_map(
            static fn($l): string => $l->line,
            array_filter($lines, static fn($l): bool => $l->visits),
        ));
        self::assertSame(['1', '2', '3', '4', '5', '7', '9a', '9b', '10', '11', '16', '17', '17a', '20a', '20a1', '20a2', '20b', '20c', '21', '22', '22a', '22b', '24', '25'], $withVisits);
        $patients = array_values(array_map(
            static fn($l): string => $l->line,
            array_filter($lines, static fn($l): bool => $l->patients !== null),
        ));
        self::assertSame(['15', '19', '20', '21', '22', '22d', '29', '34'], $patients);
    }

    /**
     * @return array<string, array{string, ?string, ?bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function suggestions(): array
    {
        return [
            'family medicine' => ['207Q00000X', '1', true],
            'internal medicine' => ['207R00000X', '3', true],
            'an internal medicine subspecialty' => ['207RC0000X', '7', true],
            'pediatrics' => ['208000000X', '5', true],
            'a pediatric subspecialty' => ['2080P0204X', '7', true],
            'psychiatry' => ['2084P0800X', '20a', true],
            'neurology' => ['2084N0400X', '7', true],
            'radiology is not a provider line' => ['2085R0202X', '14', true],
            'psychiatric NP' => ['363LP0808X', '20b', true],
            'family NP' => ['363LF0000X', '9a', true],
            'PA' => ['363A00000X', '9b', true],
            'nurse midwife' => ['367A00000X', '10', true],
            'RN' => ['163W00000X', '11', true],
            'LPN is not a provider' => ['164W00000X', '11', false],
            'clinical social worker' => ['1041C0700X', '20a2', true],
            'addiction counselor' => ['101YA0400X', '21', true],
            'dentist' => ['1223G0001X', '16', true],
            'optometrist' => ['152W00000X', '22b', true],
            'physical therapy assistant is not a provider' => ['225200000X', '22', false],
            'lower case and spaces' => [' 363lf0000x ', '9a', true],
            'unknown' => ['390200000X', null, null],
            'empty' => ['', null, null],
        ];
    }

    #[DataProvider('suggestions')]
    public function testSuggestion(string $taxonomy, ?string $line, ?bool $provider): void
    {
        $suggestion = (new ReferenceDataLoader())->load()->providerTaxonomy->suggest($taxonomy);
        self::assertSame($line === null ? null : ['line' => $line, 'provider' => $provider], $suggestion);
    }

    public function testModifiersAreReadAsTheFeeSheetStoresThem(): void
    {
        self::assertSame(['25', '95'], Table5VisitRepository::modifiers('25:95'));
        self::assertSame(['GT', '93'], Table5VisitRepository::modifiers('gt 93'));
        self::assertSame([], Table5VisitRepository::modifiers(''));
        self::assertSame(['59'], Table5VisitRepository::modifiers('59::X'));
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function brokenTable5(): array
    {
        $line = static fn(array $d, int $i, array $patch): array => ['lines' => array_replace($d['lines'], [$i => array_merge($d['lines'][$i], $patch)])] + $d;
        return [
            'a line with both a category and a sum' => [static fn(array $d): array => $line($d, 0, ['sum' => ['1']]), 'not both'],
            'a personnel line without visits' => [static fn(array $d): array => ['lines' => array_replace($d['lines'], [0 => array_diff_key($d['lines'][0], ['visits' => 1])])] + $d, 'needs visits'],
            'a total of a later line' => [static fn(array $d): array => $line($d, 6, ['sum' => ['1', '9a']]), 'must come earlier'],
            'an unknown category' => [static fn(array $d): array => $line($d, 0, ['category' => 'surgery']), 'must be one of'],
            'two lines reporting the same patients' => [static fn(array $d): array => $line($d, 6, ['patients' => 'medical']), 'already does'],
            'a duplicated line' => [static fn(array $d): array => ['lines' => [...$d['lines'], $d['lines'][0]]] + $d, 'appears twice'],
            'Line 21 not a substance use line' => [static fn(array $d): array => ['lines' => array_map(
                static fn(array $l): array => $l['line'] === '21' ? array_merge($l, ['category' => 'mental_health', 'patients' => null]) : $l,
                $d['lines'],
            )] + $d, 'line 21'],
            'a place of service that is not two digits' => [static fn(array $d): array => ['virtual' => array_merge($d['virtual'], ['placeOfService' => ['2']])] + $d, 'expected form'],
            'a substance use code that is not a code' => [static fn(array $d): array => ['substanceUseDiagnoses' => array_merge($d['substanceUseDiagnoses'], ['icd10' => ['F10 or F11']])] + $d, 'is not a code'],
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenTable5')]
    public function testABrokenTable5FileStopsTheLoad(callable $break, string $message): void
    {
        $data = (new ReferenceDataLoader())->read(Table5::FILE);
        self::assertIsArray($data);
        $broken = $break($data);
        foreach ($broken['lines'] as $i => $l) {
            if (array_key_exists('patients', $l) && $l['patients'] === null) {
                unset($broken['lines'][$i]['patients']);
            }
        }
        $this->expectException(ReferenceDataException::class);
        $this->expectExceptionMessage($message);
        Table5::fromData($broken);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function brokenTaxonomy(): array
    {
        $rule = static fn(array $d, array $patch): array => ['rules' => [array_merge($d['rules'][0], $patch), ...array_slice($d['rules'], 1)]] + $d;
        return [
            'a rule for a line that does not exist' => [static fn(array $d): array => $rule($d, ['line' => '99']), 'not a personnel line'],
            'a rule for a total line' => [static fn(array $d): array => $rule($d, ['line' => '15']), 'not a personnel line'],
            'a match that is not a code' => [static fn(array $d): array => $rule($d, ['match' => '207q-family']), 'not a NUCC taxonomy code'],
            'a repeated match' => [static fn(array $d): array => ['rules' => [$d['rules'][0], ...$d['rules']]] + $d, 'as an earlier rule already does'],
            'a default that is not a full code' => [static fn(array $d): array => ['openemrDefault' => '207Q'] + $d, 'not a NUCC taxonomy code'],
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenTaxonomy')]
    public function testABrokenTaxonomyFileStopsTheLoad(callable $break, string $message): void
    {
        $loader = new ReferenceDataLoader();
        $data = $loader->read(ProviderTaxonomy::FILE);
        self::assertIsArray($data);
        $this->expectException(ReferenceDataException::class);
        $this->expectExceptionMessage($message);
        ProviderTaxonomy::fromData($break($data), Table5::fromData($loader->read(Table5::FILE)));
    }
}
