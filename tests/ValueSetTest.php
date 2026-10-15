<?php

/**
 * Fetching value sets from VSAC, and counting Table 6A lines from them. Every
 * code here is made up or comes from the UDS Manual itself: the module never
 * ships VSAC's codes, its tests included.
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
use OpenEMR\Modules\UdsReport\ReferenceData\Table6a as Table6aDefinition;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aLine;
use OpenEMR\Modules\UdsReport\Report\Table6A;
use OpenEMR\Modules\UdsReport\Repository\ClinicalCodeRepository;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetCodes;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetException;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetExpansion;
use OpenEMR\Modules\UdsReport\ValueSet\VsacClient;
use PHPUnit\Framework\TestCase;

final class ValueSetTest extends TestCase
{
    private const KEY = 'test-key-0000';
    private const SNOMED = 'http://snomed.info/sct';
    private const RXNORM = 'http://www.nlm.nih.gov/research/umls/rxnorm';

    /**
     * A VSAC that answers from a list of pages and records each URL asked for.
     *
     * @param list<array{int, string}> $answers
     * @param list<string>             $urls
     * @param-out list<string>         $urls
     */
    private static function vsac(array $answers, array &$urls): VsacClient
    {
        return new VsacClient(static function (string $url, string $apiKey) use (&$answers, &$urls): array {
            self::assertSame(self::KEY, $apiKey);
            $urls[] = $url;
            return array_shift($answers) ?? [500, ''];
        });
    }

    /** @param list<array{string, string}> $codes system URI and code */
    private static function page(array $codes, ?int $total = null, string $version = '20260101'): string
    {
        return (string)json_encode([
            'resourceType' => 'ValueSet',
            'title' => 'Test Set',
            'version' => $version,
            'expansion' => [
                'total' => $total ?? count($codes),
                'contains' => array_map(static fn(array $c): array => ['system' => $c[0], 'code' => $c[1]], $codes),
            ],
        ]);
    }

    public function testExpandsByCodeSystemAndLeavesOtherSystemsOut(): void
    {
        $urls = [];
        $set = self::vsac([[200, self::page([[self::SNOMED, '1001'], [self::RXNORM, '2002'], ['http://example.org/other', 'X'], [self::SNOMED, '1001']])]], $urls)
            ->expand('2.16.840.1.999.1', self::KEY);
        self::assertSame(['snomed' => ['1001'], 'rxnorm' => ['2002']], $set->codes);
        self::assertSame(1, $set->otherSystems);
        self::assertSame(2, $set->count());
        self::assertSame(['Test Set', '20260101'], [$set->title, $set->version]);
        self::assertSame([VsacClient::BASE_URL . '2.16.840.1.999.1/$expand?count=1000&offset=0'], $urls);
        self::assertStringNotContainsString(self::KEY, $urls[0], 'the key is never in the URL');
    }

    public function testFollowsPagesUntilTheTotal(): void
    {
        $urls = [];
        $set = self::vsac([
            [200, self::page([[self::SNOMED, '1'], [self::SNOMED, '2']], 3)],
            [200, self::page([[self::SNOMED, '3']], 3)],
        ], $urls)->expand('2.16.840.1.999.1', self::KEY);
        self::assertSame(['snomed' => ['1', '2', '3']], $set->codes);
        self::assertStringEndsWith('offset=2', $urls[1]);
    }

    public function testFewerCodesThanTheTotalIsAnError(): void
    {
        $urls = [];
        $this->expectException(ValueSetException::class);
        $this->expectExceptionMessage('VSAC sent 2 of the 5 codes');
        self::vsac([
            [200, self::page([[self::SNOMED, '1'], [self::SNOMED, '2']], 5)],
            [200, self::page([], 5)],
        ], $urls)->expand('2.16.840.1.999.1', self::KEY);
    }

    public function testNestedEntriesAreRead(): void
    {
        $body = (string)json_encode(['resourceType' => 'ValueSet', 'expansion' => ['contains' => [
            ['display' => 'a group', 'contains' => [['system' => self::SNOMED, 'code' => '7']]],
        ]]]);
        $urls = [];
        self::assertSame(['snomed' => ['7']], self::vsac([[200, $body]], $urls)->expand('2.16.840.1.999.1', self::KEY)->codes);
    }

    /**
     * @return array<string, array{array{int, string}, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function failures(): array
    {
        return [
            'refused key' => [[401, 'Unauthorized'], 'VSAC refused the API key (HTTP 401)'],
            'unknown value set' => [[404, ''], 'VSAC has no value set 2.16.840.1.999.1'],
            'server error' => [[503, ''], 'VSAC answered HTTP 503'],
            'not JSON' => [[200, '<html>'], "isn't JSON"],
            'not a value set' => [[200, '{"resourceType":"OperationOutcome"}'], "isn't a value set"],
        ];
    }

    /** @param array{int, string} $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresSayWhatWentWrongWithoutTheKey(array $answer, string $message): void
    {
        $urls = [];
        try {
            self::vsac([$answer], $urls)->expand('2.16.840.1.999.1', self::KEY);
            self::fail('no exception');
        } catch (ValueSetException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testNoKeyOrABadOidIsRefusedBeforeAsking(): void
    {
        $urls = [];
        $client = self::vsac([], $urls);
        foreach ([['2.16.840.1.999.1', ' '], ['not an oid', self::KEY]] as [$oid, $key]) {
            try {
                $client->expand($oid, $key);
                self::fail('no exception');
            } catch (ValueSetException) {
            }
        }
        self::assertSame([], $urls);
    }

    /** @param array<string, list<string>> $codes */
    private static function fetched(array $codes, string ...$oids): ValueSetCodes
    {
        $sets = [];
        foreach ($oids as $oid) {
            $normalized = [];
            foreach ($codes as $system => $list) {
                foreach ($list as $code) {
                    $normalized[$system][\OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode::normalize($code)] = true;
                }
            }
            $sets[$oid] = ['title' => 'Test', 'version' => '1', 'fetchedAt' => '2026-10-15 12:00:00', 'codes' => $normalized];
        }
        return new ValueSetCodes($sets);
    }

    private static function table(): Table6aDefinition
    {
        return (new ReferenceDataLoader())->load()->table6a;
    }

    private static function line(Table6aDefinition $table, string $id): Table6aLine
    {
        foreach ($table->lines as $line) {
            if ($line->line === $id) {
                return $line;
            }
        }
        self::fail("no line $id");
    }

    public function testTheShippedFileNamesEachValueSetOnce(): void
    {
        $oids = self::table()->valueSetOids();
        self::assertCount(21, $oids);
        self::assertSame($oids, array_values(array_unique($oids)));
    }

    public function testALineIsCountedOnlyOnceAllItsValueSetsAreFetched(): void
    {
        $table = self::table();
        $line40 = self::line($table, '40');
        self::assertSame(3, count($line40->valueSets));
        $two = self::fetched(['snomed' => ['900001']], $line40->valueSets[0], $line40->valueSets[1]);
        self::assertFalse(self::line($table->withValueSets($two), '40')->isCounted());

        $all = self::fetched(['snomed' => ['900001']], ...$line40->valueSets);
        $counted = self::line($table->withValueSets($all), '40');
        self::assertTrue($counted->isCounted());
        self::assertSame(Table6aLine::COMPUTED_FULL, $counted->computed, 'from the fee sheet and completed care plan entries');
        self::assertTrue($counted->matches(['snomed' => ['900001']]));
        self::assertFalse($counted->matches(['snomed' => ['900002']]));
        self::assertFalse($counted->matches(['icd10' => ['900001']]), 'a code counts only in its own system');
    }

    public function testTheManualsExclusionsHoldForValueSetCodes(): void
    {
        $table = self::table();
        $line9 = self::line($table, '9');
        $fetched = self::line($table->withValueSets(self::fetched(['icd10' => ['O24.41', 'O24.11', 'Z99.991']], ...$line9->valueSets)), '9');
        self::assertSame(Table6aLine::COMPUTED_FULL, $fetched->computed);
        self::assertNull($fetched->limit);
        self::assertFalse($fetched->matches(['icd10' => ['O2441']]), 'line 9 excludes gestational diabetes (O24.4-)');
        self::assertTrue($fetched->matches(['icd10' => ['O2411']]));
        self::assertTrue($fetched->matches(['icd10' => ['Z99991']]), 'a value set code beyond the listed ones counts');
        self::assertFalse($line9->matches(['icd10' => ['Z99991']]), 'but not before the value set is fetched');
    }

    public function testMedicationsAndScreeningsCountAtVisitsOfTheirOwn(): void
    {
        $table = self::table();
        $line26c3 = self::line($table, '26c3');
        $line39 = self::line($table, '39');
        $fetched = $table->withValueSets(new ValueSetCodes(
            self::fetched(['rxnorm' => ['3000001']], ...$line26c3->valueSets)->sets + self::fetched(['loinc' => ['99999-9']], ...$line39->valueSets)->sets,
        ));
        $day = new DateTimeImmutable('2026-03-01');
        $birth = new DateTimeImmutable('1980-01-01');
        $encounters = [
            // A prescription written outside an encounter, and one at a visit with a screening.
            new EncounterCodes(1, 0, $day, false, true, $birth, ['rxnorm' => ['3000001']]),
            new EncounterCodes(1, 10, $day, true, true, $birth, ['rxnorm' => ['3000001'], 'loinc' => ['99999-9']]),
            // Not a UDS patient.
            new EncounterCodes(2, 0, $day, false, true, $birth, ['rxnorm' => ['3000001']]),
        ];
        $rows = [];
        foreach ((new Table6A($fetched, $encounters, [1 => true]))->rows() as $row) {
            $rows[$row['line']->line] = [$row['visits'], $row['patients']];
        }
        self::assertSame([2, 1], $rows['26c3']);
        self::assertSame([1, 1], $rows['39']);
        self::assertSame([null, null], $rows['26c2'], 'its value set is not fetched');
    }

    public function testStoredCodesAreReadWithOrWithoutTheirSystem(): void
    {
        self::assertSame(['123', '456'], ClinicalCodeRepository::codes('RXCUI:123;RXCUI:456', 'rxnorm'));
        self::assertSame(['123'], ClinicalCodeRepository::codes(' 123 ', 'rxnorm'));
        self::assertSame(['123'], ClinicalCodeRepository::codes('rxnorm:123', 'rxnorm'));
        self::assertSame([], ClinicalCodeRepository::codes('NDC:00000000000', 'rxnorm'), 'another system is not RxNorm');
        self::assertSame(['99999-9'], ClinicalCodeRepository::codes('LOINC:99999-9', 'loinc'));
        self::assertSame(['99999-9', '88888-8'], ClinicalCodeRepository::codes('LOINC:99999-9; LOINC:88888-8', 'loinc'));
        self::assertSame([], ClinicalCodeRepository::codes('SNOMED-CT:1234', 'loinc'));
        self::assertSame([], ClinicalCodeRepository::codes('', 'loinc'));
    }

    public function testCarePlanCodesNeedTheirCodeType(): void
    {
        self::assertSame(['900001'], ClinicalCodeRepository::codes('SNOMED-CT:900001', 'snomed', true));
        self::assertSame(['900002'], ClinicalCodeRepository::codes('SNOMED-PR:900002', 'snomed', true));
        self::assertSame(['99999'], ClinicalCodeRepository::codes('CPT4:99999', 'cpt', true));
        self::assertSame(['Z9999'], ClinicalCodeRepository::codes('HCPCS:Z9999', 'hcpcs', true));
        self::assertSame([], ClinicalCodeRepository::codes('CPT4:99999', 'snomed', true), 'a CPT code is not a SNOMED CT code');
        self::assertSame([], ClinicalCodeRepository::codes('900001', 'snomed', true), 'an unprefixed care plan code has no known system');
    }

    public function testCarePlanInterventionsCountOnTheirLine(): void
    {
        $table = self::table();
        $line41 = self::line($table, '41');
        $fetched = $table->withValueSets(self::fetched(['snomed' => ['900001']], ...$line41->valueSets));
        $encounters = [
            // A completed care plan entry at a visit, and one with no encounter.
            new EncounterCodes(1, 10, new DateTimeImmutable('2026-03-01'), true, true, null, ['snomed' => ['900001']]),
            new EncounterCodes(1, 0, new DateTimeImmutable('2026-04-01'), false, true, null, ['snomed' => ['900001']]),
        ];
        $rows = [];
        foreach ((new Table6A($fetched, $encounters, [1 => true]))->rows() as $row) {
            $rows[$row['line']->line] = [$row['line']->computed, $row['visits'], $row['patients']];
        }
        self::assertSame(['full', 2, 1], $rows['41']);
    }

    public function testFromFhirNeedsAValueSet(): void
    {
        $this->expectException(ValueSetException::class);
        ValueSetExpansion::fromFhir('2.16.840.1.999.1', []);
    }
}
