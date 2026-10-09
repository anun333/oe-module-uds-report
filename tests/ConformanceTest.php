<?php

/**
 * Runs the UDS Report module's shared test cases against this implementation.
 *
 * The expected results live in the module as plain data
 * (data/cases/*.json in this package):
 * race and ethnicity placement on Table 3B, insurance categories, poverty
 * thresholds and income lines, and whole tables built from synthetic patients
 * worked out by hand from the 2026 UDS Manual. Keeping them as data lets people
 * who know the UDS review them, and holds any other implementation of the same
 * rules to the same results.
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
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceData;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\DemographicTables;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    private const PATIENT_FIELDS = [
        'pid', 'dob', 'sexAtBirth', 'raceCodes', 'ethnicityCodes', 'limitedEnglish', 'zip', 'visitSiteZip',
        'annualIncome', 'familySize', 'incomeVerified', 'insurance', 'dualEligible', 'insuranceRecorded',
        'housingStatus', 'agriculturalWorker', 'veteranStatus', 'visitSiteIds', 'lastVisit',
    ];

    private const TABLES = ['zipTable', 'table3A', 'table3B', 'table4', 'crossCheck'];

    private static function reference(): ReferenceData
    {
        return (new ReferenceDataLoader())->load();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function raceEthnicityCases(): array
    {
        return CaseFile::cases('race-ethnicity', 'cases');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function insuranceCases(): array
    {
        return CaseFile::cases('insurance-categories', 'cases');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function thresholdCases(): array
    {
        return CaseFile::cases('poverty-guidelines', 'thresholds');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function incomeLineCases(): array
    {
        return CaseFile::cases('poverty-guidelines', 'incomeLines');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function tableCases(): array
    {
        return CaseFile::cases('demographic-tables', 'cases');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('raceEthnicityCases')]
    public function testRaceEthnicity(array $case): void
    {
        $placed = self::reference()->raceEthnicity->place(CaseFile::strings($case['race'] ?? null), CaseFile::strings($case['ethnicity'] ?? null));
        self::assertSame(
            ['line' => CaseFile::string($case['line'] ?? null), 'column' => CaseFile::string($case['column'] ?? null)],
            $placed,
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('insuranceCases')]
    public function testInsuranceCategory(array $case): void
    {
        $choice = CaseFile::nullableString($case['clinicChoice'] ?? null);
        $category = self::reference()->insuranceCategories->forCompany(
            $choice === null ? null : InsuranceCategory::tryFrom($choice),
            CaseFile::nullableString($case['paymentTypology'] ?? null),
            CaseFile::nullableInt($case['claimType'] ?? null),
        );
        self::assertSame(CaseFile::string($case['category'] ?? null), $category->value);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('thresholdCases')]
    public function testPovertyThreshold(array $case): void
    {
        self::assertSame(
            CaseFile::nullableInt($case['threshold'] ?? null),
            self::reference()->povertyGuidelines->annualThreshold(
                CaseFile::int($case['year'] ?? null),
                PovertyRegion::from(CaseFile::string($case['region'] ?? null)),
                CaseFile::int($case['familySize'] ?? null),
            ),
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('incomeLineCases')]
    public function testIncomeLine(array $case): void
    {
        self::assertSame(
            CaseFile::string($case['line'] ?? null),
            self::reference()->povertyGuidelines->incomeLine(
                CaseFile::nullableInt($case['annualIncome'] ?? null),
                CaseFile::nullableInt($case['familySize'] ?? null),
                CaseFile::int($case['year'] ?? null),
                PovertyRegion::from(CaseFile::string($case['region'] ?? null)),
            ),
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('tableCases')]
    public function testDemographicTables(array $case): void
    {
        $defaults = CaseFile::object(CaseFile::load('demographic-tables')['patientDefaults'] ?? null);
        $patients = [];
        foreach (CaseFile::list($case['patients'] ?? null) as $i => $fields) {
            $patients[] = self::patient(CaseFile::object($fields) + $defaults + ['pid' => $i + 1]);
        }
        $settings = CaseFile::object($case['settings'] ?? null);
        $region = CaseFile::nullableString($settings['povertyRegion'] ?? null);
        $tables = new DemographicTables(
            $patients,
            CaseFile::int($case['year'] ?? null),
            new UdsSettings(
                schoolBasedFacilityIds: CaseFile::ints($settings['schoolBasedFacilityIds'] ?? []),
                publicHousingFacilityIds: CaseFile::ints($settings['publicHousingFacilityIds'] ?? []),
                povertyRegion: $region === null ? PovertyRegion::Contiguous : PovertyRegion::from($region),
            ),
            self::reference(),
        );

        $built = [];
        foreach (CaseFile::list($case['expect'] ?? null) as $value) {
            $expectation = CaseFile::object($value);
            $at = CaseFile::string($expectation['at'] ?? null);
            $why = $at . (isset($expectation['why']) ? ' (' . CaseFile::string($expectation['why']) . ')' : '');
            $path = explode('.', $at);
            $table = array_shift($path);
            self::assertContains($table, self::TABLES, 'unknown table in "' . $at . '"');
            $actual = $built[$table] ??= match ($table) {
                'zipTable' => $tables->zipTable(),
                'table3A' => $tables->table3A(),
                'table3B' => $tables->table3B(),
                'table4' => $tables->table4(),
                default => $tables->crossCheck(),
            };
            $last = array_pop($path);
            self::assertNotNull($last, 'no cell named in "' . $at . '"');
            foreach ($path as $key) {
                self::assertIsArray($actual, $why);
                self::assertArrayHasKey($key, $actual, $why);
                $actual = $actual[$key];
            }
            self::assertIsArray($actual, $why);
            if (($expectation['absent'] ?? false) === true) {
                self::assertArrayNotHasKey($last, $actual, $why);
                continue;
            }
            self::assertArrayHasKey($last, $actual, $why);
            $expected = $expectation['is'] ?? null;
            $cell = $actual[$last];
            if (is_array($expected) && is_array($cell)) {
                ksort($expected);
                ksort($cell);
            }
            self::assertSame($expected, $cell, $why);
        }
    }

    /** @param array<string, mixed> $f */
    private static function patient(array $f): UdsPatient
    {
        self::assertSame([], array_values(array_diff(array_keys($f), self::PATIENT_FIELDS)), 'unknown patient fields');
        self::assertSame([], array_values(array_diff(self::PATIENT_FIELDS, array_keys($f))), 'missing patient fields');
        $dob = CaseFile::nullableString($f['dob']);
        $sex = CaseFile::nullableString($f['sexAtBirth']);
        $housing = CaseFile::nullableString($f['housingStatus']);
        $agriculture = CaseFile::nullableString($f['agriculturalWorker']);
        $veteran = CaseFile::nullableString($f['veteranStatus']);
        return new UdsPatient(
            pid: CaseFile::int($f['pid']),
            dob: $dob === null ? null : new \DateTimeImmutable($dob),
            sexAtBirth: $sex === null ? null : SexAtBirth::from($sex),
            raceCodes: CaseFile::strings($f['raceCodes']),
            ethnicityCodes: CaseFile::strings($f['ethnicityCodes']),
            limitedEnglish: CaseFile::bool($f['limitedEnglish']),
            zip: CaseFile::nullableString($f['zip']),
            visitSiteZip: CaseFile::nullableString($f['visitSiteZip']),
            annualIncome: CaseFile::nullableInt($f['annualIncome']),
            familySize: CaseFile::nullableInt($f['familySize']),
            incomeVerified: CaseFile::bool($f['incomeVerified']),
            insurance: InsuranceCategory::from(CaseFile::string($f['insurance'])),
            dualEligible: CaseFile::bool($f['dualEligible']),
            insuranceRecorded: CaseFile::bool($f['insuranceRecorded']),
            housingStatus: $housing === null ? null : HousingStatus::from($housing),
            agriculturalWorker: $agriculture === null ? null : AgriculturalWorker::from($agriculture),
            veteranStatus: $veteran === null ? null : VeteranStatus::from($veteran),
            visitSiteIds: CaseFile::ints($f['visitSiteIds']),
            lastVisit: new \DateTimeImmutable(CaseFile::string($f['lastVisit'])),
        );
    }
}
