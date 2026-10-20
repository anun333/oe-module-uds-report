<?php

/**
 * Income as staff type it into OpenEMR's Demographics form: one amount is read,
 * anything else is reported with the reason, and nothing is guessed.
 *
 * The cases come from an end-to-end run through OpenEMR's own save path
 * (dev/uds_ingest_check.py in the openhealthcare project): "9/15/2026" in the
 * Financial Review Date box is stored as 0000-00-00, and "$2,000 - $2,500" in
 * Monthly Income used to land on the Over 200% line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\DataGapFinder;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncomeEntryTest extends TestCase
{
    /** @return array<string, array{string, ?int}> */
    public static function monthlyIncome(): array
    {
        return [
            'plain' => ['2000', 24000],
            'dollar sign and thousands' => ['$2,000', 24000],
            'cents' => ['2,000.50', 24006],
            'spaces' => [' $ 2,000 ', 24000],
            'no income' => ['0', 0],
            'a range' => ['$2,000 - $2,500', null],
            'thousands as k' => ['1.5k', null],
            'a unit' => ['2000/mo', null],
            'negative' => ['-500', null],
            'bad grouping' => ['1,00', null],
            'three decimals' => ['2000.555', null],
            'words' => ['about two thousand', null],
            'blank' => ['', null],
        ];
    }

    #[DataProvider('monthlyIncome')]
    public function testMonthlyIncomeIsOneAmountOrNothing(string $typed, ?int $annual): void
    {
        self::assertSame($annual, UdsPatientRepository::annualIncome($typed));
    }

    /** @return array<string, array{string, string, string, list<DataGap>}> */
    public static function incomeEntries(): array
    {
        return [
            'complete and in the window' => ['$2,000', '4', '2026-08-01 00:00:00', []],
            'nothing entered' => ['', '', '', [DataGap::IncomeNotRecorded, DataGap::FamilySizeNotRecorded, DataGap::ReviewDateMissing]],
            'a date OpenEMR did not save' => ['$2,000', '4', '0000-00-00 00:00:00', [DataGap::ReviewDateNotSaved]],
            'a range and words' => ['$2,000 - $2,500', '4 people', '2026-08-01', [DataGap::IncomeNotUnderstood, DataGap::FamilySizeNotUnderstood]],
            'family of zero' => ['2000', '0', '2026-08-01', [DataGap::FamilySizeNotUnderstood]],
            'reviewed too long ago' => ['2000', '3', '2025-08-31', [DataGap::ReviewDateOutsideWindow]],
            'reviewed after the visit' => ['2000', '3', '2026-09-02', [DataGap::ReviewDateOutsideWindow]],
        ];
    }

    /** @param list<DataGap> $expected */
    #[DataProvider('incomeEntries')]
    public function testEachReasonIsNamed(string $income, string $family, string $reviewed, array $expected): void
    {
        $lastVisit = new \DateTimeImmutable('2026-09-01 10:00');
        self::assertSame($expected, UdsPatientRepository::incomeGaps($income, $family, $reviewed, $lastVisit));
    }

    public function testTheFinderReportsTheReasonInsteadOfTheCatchAll(): void
    {
        $finder = new DataGapFinder((new ReferenceDataLoader())->load()->raceEthnicity);

        $withReason = Patients::make(incomeVerified: false, incomeGaps: [DataGap::ReviewDateNotSaved]);
        self::assertContains(DataGap::ReviewDateNotSaved, $finder->forPatient($withReason));
        self::assertNotContains(DataGap::IncomeUnverified, $finder->forPatient($withReason));

        // A patient built without the raw values, as the conformance cases are.
        $withoutReason = Patients::make(incomeVerified: false);
        self::assertContains(DataGap::IncomeUnverified, $finder->forPatient($withoutReason));
    }

    public function testEveryGapHasALabelAndAVerdict(): void
    {
        foreach (DataGap::cases() as $gap) {
            self::assertNotSame('', Labels::gap($gap), $gap->value);
            $gap->isBlocking(); // throws UnhandledMatchError if a case is missing
        }
        foreach ([DataGap::IncomeNotRecorded, DataGap::IncomeNotUnderstood, DataGap::FamilySizeNotRecorded,
                     DataGap::FamilySizeNotUnderstood, DataGap::ReviewDateMissing, DataGap::ReviewDateNotSaved,
                     DataGap::ReviewDateOutsideWindow] as $gap) {
            self::assertFalse($gap->isBlocking(), "$gap->value counts the patient as Unknown; it must not block");
        }
    }

    public function testOnlyChartProblemsSendStaffToDemographics(): void
    {
        $elsewhere = array_values(array_filter(DataGap::cases(), static fn(DataGap $g): bool => !$g->isDemographic()));
        self::assertSame([DataGap::MedicationNotCounted, DataGap::DentalServiceNotCounted, DataGap::PrepNotCounted, DataGap::SdohFormNotCounted], $elsewhere);
        self::assertTrue(DataGap::NoZip->isDemographic());
    }
}
