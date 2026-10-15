<?php

/**
 * Tobacco cessation and opioid use disorder medications that Table 6A can't
 * count. The RxNorm codes are made up: the module never ships VSAC's codes.
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
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aLine;
use OpenEMR\Modules\UdsReport\Report\MedicationCheck;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MedicationCheckTest extends TestCase
{
    private const CESSATION_PRODUCT = '9100001';
    private const MOUD_PRODUCT = '9200001';
    private const AN_INGREDIENT = '9900001';

    /**
     * Lines 26c2 and 26c3 by line, counted from value sets holding one product each.
     *
     * @return array<string, Table6aLine>
     */
    private static function lines(bool $fetched = true): array
    {
        $table = (new ReferenceDataLoader())->load()->table6a;
        $sets = [];
        if ($fetched) {
            foreach ($table->lines as $line) {
                $code = ['26c2' => self::CESSATION_PRODUCT, '26c3' => self::MOUD_PRODUCT][$line->line] ?? null;
                foreach ($code === null ? [] : $line->valueSets as $oid) {
                    $sets[$oid] = ['title' => 'Test', 'version' => '1', 'fetchedAt' => '2026-10-15 12:00:00', 'codes' => ['rxnorm' => [$code => true]]];
                }
            }
        }
        $lines = [];
        foreach ($table->withValueSets(new ValueSetCodes($sets))->lines as $line) {
            if (isset(MedicationCheck::WORDS[$line->line])) {
                $lines[$line->line] = $line;
            }
        }
        return $lines;
    }

    /**
     * @return array<string, array{string, list<string>, ?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function medications(): array
    {
        return [
            'MOUD product in the value set' => ['Buprenorphine 8 MG Sublingual Tablet', [self::MOUD_PRODUCT], null],
            'MOUD coded by ingredient' => ['buprenorphine', [self::AN_INGREDIENT], '26c3'],
            'MOUD with no code' => ['Methadone 10 mg tablet', [], '26c3'],
            'naltrexone, any case' => ['NALTREXONE 50 MG', [], '26c3'],
            'cessation product in the value set' => ['Nicotine 21 MG/Day Transdermal Patch', [self::CESSATION_PRODUCT], null],
            'cessation with no code' => ['nicotine gum', [], '26c2'],
            'varenicline with another code' => ['Varenicline 1 MG', [self::AN_INGREDIENT], '26c2'],
            'bupropion is left out' => ['Bupropion SR 150 MG', [], null],
            'something else' => ['Ibuprofen 200 MG', [], null],
        ];
    }

    /** @param list<string> $codes */
    #[DataProvider('medications')]
    public function testUncountedLine(string $name, array $codes, ?string $expected): void
    {
        self::assertSame($expected, MedicationCheck::uncountedLine($name, $codes, self::lines()));
    }

    public function testNothingIsFlaggedBeforeTheValueSetsAreFetched(): void
    {
        self::assertNull(MedicationCheck::uncountedLine('buprenorphine', [], self::lines(false)));
    }

    public function testEveryWordIsSearchedFor(): void
    {
        self::assertSame(['nicotine', 'varenicline', 'buprenorphine', 'methadone', 'naltrexone'], MedicationCheck::allWords());
    }
}
