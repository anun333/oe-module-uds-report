<?php

/**
 * UDS Report rules not covered by the shared cases (see ConformanceTest):
 * record problems, the reporting year, the enums' table lines, and the parsing
 * of OpenEMR's own field formats.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\AgriculturalWorker;
use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\DataGapFinder;
use OpenEMR\Modules\UdsReport\Report\DemographicTables;
use OpenEMR\Modules\UdsReport\Report\ReportYear;
use OpenEMR\Modules\UdsReport\Repository\Row;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UdsReportRulesTest extends TestCase
{
    private static function gapFinder(): DataGapFinder
    {
        return new DataGapFinder((new ReferenceDataLoader())->load()->raceEthnicity);
    }

    public function testFullyRecordedPatientHasNoGaps(): void
    {
        self::assertSame([], self::gapFinder()->forPatient(Patients::make()));
    }

    public function testEveryGapIsFound(): void
    {
        $patient = Patients::make(sex: null, dob: null, race: [], zip: null, incomeVerified: false,
            insurance: InsuranceCategory::Unmapped, housing: null, agriculture: null, veteran: null);
        self::assertSame(
            [DataGap::NoBirthSex, DataGap::NoDob, DataGap::InsurerUnmapped, DataGap::RaceUnreported, DataGap::IncomeUnverified,
                DataGap::NoZip, DataGap::HousingNotAsked, DataGap::AgricultureNotAsked, DataGap::VeteranNotAsked],
            self::gapFinder()->forPatient($patient),
        );
    }

    public function testCoresOwnHomelessAndMigrantFieldsAreNamedNotRead(): void
    {
        // OpenEMR's Demographics "Homeless, etc." and "Migrant/Seasonal" are free text: the gap says they hold
        // something, in place of "not recorded", and nothing is counted from them.
        $patient = Patients::make(housing: null, agriculture: null, coreHomeless: 'yes, shelter', coreMigrantSeasonal: 'seasonal');
        $gaps = self::gapFinder()->forPatient($patient);
        self::assertContains(DataGap::HousingInCoreField, $gaps);
        self::assertContains(DataGap::AgricultureInCoreField, $gaps);
        self::assertNotContains(DataGap::HousingNotAsked, $gaps, 'not counted twice');
        self::assertNotContains(DataGap::AgricultureNotAsked, $gaps);
        self::assertFalse($patient->isHomeless(), 'free text is never read as a housing status');
        self::assertNotContains(DataGap::HousingInCoreField, self::gapFinder()->forPatient(Patients::make(coreHomeless: 'yes')), 'a UDS status recorded is no gap');
    }

    public function testHomelessWithoutZipIsNotAGap(): void
    {
        self::assertNotContains(DataGap::NoZip, self::gapFinder()->forPatient(Patients::make(zip: null, housing: HousingStatus::Street)));
    }

    public function testGapsAreGroupedWithEveryGapPresent(): void
    {
        $byGap = self::gapFinder()->byGap([Patients::make(pid: 7, sex: null), Patients::make(pid: 8)]);
        self::assertSame(array_map(static fn(DataGap $g): string => $g->value, DataGap::cases()), array_keys($byGap));
        self::assertSame([7], $byGap[DataGap::NoBirthSex->value]);
        self::assertSame([], $byGap[DataGap::NoDob->value]);
    }

    public function testOnlyRecordingProblemsBlock(): void
    {
        $blocking = array_values(array_filter(DataGap::cases(), static fn(DataGap $g): bool => $g->isBlocking()));
        self::assertSame([DataGap::NoBirthSex, DataGap::NoDob, DataGap::InsurerUnmapped], $blocking);
    }

    /**
     * @return array<string, array{string, string, int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function yearCases(): array
    {
        return [
            'march reports last year' => ['', '2026-03-31', 2025],
            'april reports this year' => ['', '2026-04-01', 2026],
            'asked-for year' => ['2025', '2026-06-01', 2025],
            'future year falls back' => ['2027', '2026-06-01', 2026],
            'too early falls back' => ['2019', '2026-06-01', 2026],
            'not a year falls back' => ['20x6', '2026-06-01', 2026],
        ];
    }

    #[DataProvider('yearCases')]
    public function testReportYear(string $query, string $today, int $expected): void
    {
        self::assertSame($expected, ReportYear::fromQuery($query, new \DateTimeImmutable($today)));
    }

    public function testInsuranceCategoryLines(): void
    {
        self::assertSame('9', InsuranceCategory::Medicare->table4Line());
        self::assertNull(InsuranceCategory::Unmapped->table4Line(), 'an unmapped insurer has no line');
        foreach ([InsuranceCategory::Medicaid, InsuranceCategory::ChipMedicaid, InsuranceCategory::OtherPublic, InsuranceCategory::OtherPublicChip] as $c) {
            self::assertSame('public', $c->zipColumn(), 'the ZIP table merges public programs');
        }
        self::assertNotContains(InsuranceCategory::Unmapped, InsuranceCategory::assignable());
    }

    public function testSpecialPopulationLines(): void
    {
        self::assertSame('14', AgriculturalWorker::FormerMigratory->table4Line(), 'former migratory workers count as migratory');
        self::assertSame('15', AgriculturalWorker::Seasonal->table4Line());
        self::assertNull(AgriculturalWorker::Declined->table4Line());
        self::assertSame('22', HousingStatus::HomelessUnknown->table4Line());
        self::assertFalse(HousingStatus::Declined->isHomeless());
    }

    public function testAgeLines(): void
    {
        self::assertCount(38, DemographicTables::ageLines());
        self::assertSame('Under age 1', DemographicTables::ageLines()[1]);
        self::assertSame('Ages 80–84', DemographicTables::ageLines()[37]);
        self::assertSame(38, DemographicTables::ageLine(85));
    }

    public function testSexAtBirthFromPatientData(): void
    {
        self::assertSame(SexAtBirth::Male, SexAtBirth::fromPatientData('Male'));
        self::assertNull(SexAtBirth::fromPatientData('UNK'));
        self::assertNull(SexAtBirth::fromPatientData(''));
    }

    public function testPatientDataParsing(): void
    {
        self::assertSame('12345', UdsPatientRepository::zip('12345-6789'));
        self::assertSame('12345', UdsPatientRepository::zip(' 123456789 '));
        self::assertNull(UdsPatientRepository::zip('1234'));
        self::assertNull(UdsPatientRepository::zip('K1A 0B1'));
        self::assertSame(24000, UdsPatientRepository::annualIncome('$2,000'));
        self::assertNull(UdsPatientRepository::annualIncome(''));
        $last = new \DateTimeImmutable('2026-09-01 10:00');
        self::assertTrue(UdsPatientRepository::incomeVerified('2026-09-01 15:00:00', $last), 'same day');
        self::assertTrue(UdsPatientRepository::incomeVerified('2025-09-01', $last), 'exactly 12 months before');
        self::assertFalse(UdsPatientRepository::incomeVerified('2025-08-31', $last));
        self::assertFalse(UdsPatientRepository::incomeVerified('2026-09-02', $last), 'after the last visit');
        self::assertFalse(UdsPatientRepository::incomeVerified('', $last));
        self::assertFalse(UdsPatientRepository::incomeVerified('not a date', $last));
    }

    public function testInsuranceAtLastVisit(): void
    {
        $companies = [1 => InsuranceCategory::Medicare, 2 => InsuranceCategory::Medicaid, 3 => InsuranceCategory::Private];
        $last = new \DateTimeImmutable('2026-09-01');
        $rows = [
            ['type' => 'primary', 'provider' => '3', 'date' => '2020-01-01', 'date_end' => '2026-06-30'], // ended before the visit
            ['type' => 'primary', 'provider' => '1', 'date' => '2026-07-01', 'date_end' => null],
            ['type' => 'secondary', 'provider' => '2', 'date' => '2026-07-01', 'date_end' => null],
        ];
        $atVisit = UdsPatientRepository::insuranceAt($rows, $last, $companies);
        self::assertSame(InsuranceCategory::Medicare, $atVisit->category);
        self::assertTrue($atVisit->dualEligible, 'Medicaid behind a Medicare primary');
        self::assertTrue($atVisit->recorded);

        $none = UdsPatientRepository::insuranceAt([], $last, $companies);
        self::assertSame(InsuranceCategory::None, $none->category);
        self::assertFalse($none->recorded);

        $unknown = UdsPatientRepository::insuranceAt([['type' => 'primary', 'provider' => '9', 'date' => null, 'date_end' => null]], $last, $companies);
        self::assertSame(InsuranceCategory::Unmapped, $unknown->category);
        self::assertTrue($unknown->recorded);
    }

    public function testCsvCellsCannotStartAFormula(): void
    {
        self::assertSame("'=HYPERLINK(1)", CsvRow::cell('=HYPERLINK(1)'));
        self::assertSame("'+1", CsvRow::cell('+1'));
        self::assertSame("'-1+1", CsvRow::cell('-1+1'));
        self::assertSame("'-A1", CsvRow::cell('-A1'));
        self::assertSame('-1', CsvRow::cell('-1'), 'a negative amount is a number, not a formula');
        self::assertSame("'@SUM(A1)", CsvRow::cell('@SUM(A1)'));
        self::assertSame('Table 3A', CsvRow::cell('Table 3A'));
        self::assertSame(-1, CsvRow::cell(-1), 'numbers are written as numbers');
    }

    public function testRowReads(): void
    {
        $row = ['a' => '42', 'b' => null, 'c' => 'x', 'd' => 7];
        self::assertSame(42, Row::int($row, 'a'));
        self::assertSame(7, Row::int($row, 'd'));
        self::assertNull(Row::nullableInt($row, 'c'));
        self::assertSame('', Row::text($row, 'b'));
        self::assertNull(Row::nullableText($row, 'b'));
        self::assertSame('7', Row::text($row, 'd'));
        $this->expectException(\UnexpectedValueException::class);
        Row::int($row, 'c');
    }
}
