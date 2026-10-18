<?php

/**
 * Small-cell suppression, and the grant evidence counts built on it.
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
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Enum\VeteranStatus;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\Report\GrantEvidence;
use OpenEMR\Modules\UdsReport\Report\SmallCells;
use PHPUnit\Framework\TestCase;

final class GrantEvidenceTest extends TestCase
{
    public function testCountsFromOneToTenAreHiddenAndZeroIsShown(): void
    {
        self::assertSame([0, null, null, 11, 250], array_map(SmallCells::one(...), [0, 1, 10, 11, 250]));
    }

    public function testAOneCellGapHidesTheNextSmallestToo(): void
    {
        // Total 120: with only the 5 hidden, 120 - 40 - 75 would give it away, so the 40 goes as well.
        self::assertSame(['a' => null, 'b' => null, 'c' => 75], SmallCells::breakdown(['a' => 5, 'b' => 40, 'c' => 75]));
        // Two hidden already: nothing more to hide.
        self::assertSame(['a' => null, 'b' => null, 'c' => 75], SmallCells::breakdown(['a' => 5, 'b' => 3, 'c' => 75]));
        // Nothing hidden: nothing changes. A zero never counts as the next smallest.
        self::assertSame(['a' => 0, 'b' => 12, 'c' => 75], SmallCells::breakdown(['a' => 0, 'b' => 12, 'c' => 75]));
        self::assertSame(['a' => 0, 'b' => null, 'c' => null], SmallCells::breakdown(['a' => 0, 'b' => 7, 'c' => 75]));
    }

    public function testAPercentNeedsBothCountsShown(): void
    {
        self::assertSame(25, SmallCells::percent(25, 100));
        self::assertNull(SmallCells::percent(null, 100));
        self::assertNull(SmallCells::percent(25, null));
        self::assertNull(SmallCells::percent(0, 0));
    }

    /** @return list<UdsPatient> 60 patients: 40 children in ZIP 11111, 15 adults in 22222, 5 older adults in 33333 */
    private static function patients(): array
    {
        $patients = [];
        for ($pid = 1; $pid <= 60; $pid++) {
            [$dob, $zip] = match (true) {
                $pid <= 40 => ['2015-01-01', '11111'],
                $pid <= 55 => ['1980-01-01', '22222'],
                default => ['1950-01-01', '33333'],
            };
            $patients[] = Patients::make(
                pid: $pid,
                dob: $dob,
                sex: $pid % 2 === 0 ? SexAtBirth::Male : SexAtBirth::Female,
                zip: $zip,
                insurance: $pid <= 30 ? InsuranceCategory::Medicaid : InsuranceCategory::Private,
                housing: $pid <= 12 ? HousingStatus::Shelter : HousingStatus::Housed,
                agriculture: $pid === 1 ? AgriculturalWorker::Seasonal : AgriculturalWorker::NotAgricultural,
                veteran: $pid >= 59 ? VeteranStatus::Veteran : VeteranStatus::NotVeteran,
            );
        }
        return $patients;
    }

    /** @return array<int, true> */
    private static function pids(int ...$pids): array
    {
        return array_fill_keys($pids, true);
    }

    public function testWhoWeServeAddsUpAndHidesSmallGroups(): void
    {
        $table4 = ['income' => ['1' => 30, '2' => 20, '3' => 6, '4' => 4, '5' => 0], 'insurance' => [], 'insuranceUnmapped' => 0,
            'insuranceAgeUnknown' => 0, 'special' => [], 'povertyTableAvailable' => true];
        $evidence = new GrantEvidence(self::patients(), 2026, $table4, []);
        $who = $evidence->whoWeServe();

        self::assertSame(60, $evidence->total());
        // 65+ has 5 patients: hidden, and the next smallest (15) with it, so the total can't give it away.
        self::assertSame(['0-17' => 40, '18-64' => null, '65+' => null, 'unknown' => 0], $who['age']);
        self::assertSame(['female' => 30, 'male' => 30, 'unknown' => 0], $who['sex']);
        self::assertSame(['none' => 0, 'public' => 30, 'medicare' => 0, 'private' => 30, 'unknown' => 0], $who['insurance']);
        // 6 and 4 hidden; two hidden already, so the 20 stays.
        self::assertSame(['under100' => 30, '101to150' => 20, '151to200' => null, 'over200' => null, 'unknown' => 0], $who['income']);
        // ZIP 33333 has 5 patients: folded into other (5, hidden). One hidden cell, so the next smallest (15) goes too.
        self::assertSame([11111 => 40, 22222 => null, 'other' => null, 'unknown' => 0], $who['zip']);
        self::assertSame(['homeless' => 12, 'agricultural' => null, 'veterans' => null], $who['special']);
        self::assertSame(0, $who['language']);
    }

    public function testSocialNeedsAreCountedOnlyWhenTheirLinesAre(): void
    {
        $evidence = new GrantEvidence(self::patients(), 2026, ['income' => []] + self::emptyTable4(), []);
        self::assertFalse($evidence->socialNeedsCounted());
        self::assertSame(['all' => ['patients' => 60]], $evidence->socialNeeds());
    }

    public function testSocialNeedsByGroup(): void
    {
        $screened = self::pids(...range(1, 50));
        $food = self::pids(...range(1, 30));
        $evidence = new GrantEvidence(self::patients(), 2026, self::emptyTable4(), ['39' => $screened, '40' => $food, '41' => [], '42' => self::pids(2, 4)]);

        self::assertTrue($evidence->socialNeedsCounted());
        self::assertSame(['all' => ['patients' => 60, '39' => 50, '40' => 30, '41' => 0, '42' => null]], $evidence->socialNeeds());
        // Each column is its own breakdown: patients 40/15/5 hide 5 and then 15; screened 40/10/0 hide 10
        // and then 40; the two 42s are hidden, with only zeros beside them to hide.
        self::assertSame([
            '0-17' => ['patients' => 40, '39' => null, '40' => 30, '41' => 0, '42' => null],
            '18-64' => ['patients' => null, '39' => null, '40' => 0, '41' => 0, '42' => 0],
            '65+' => ['patients' => null, '39' => 0, '40' => 0, '41' => 0, '42' => 0],
        ], $evidence->socialNeeds('age'));
        $byZip = $evidence->socialNeeds('zip');
        self::assertSame(['patients' => 40, '39' => null, '40' => 30, '41' => 0, '42' => null], $byZip[11111]);
        self::assertArrayHasKey('other', $byZip, 'ZIP 33333 (5 patients) folded into other');
        self::assertArrayNotHasKey(33333, $byZip);
    }

    /** @return array{income: array<int|string, int>, insurance: array<int|string, array{child: int, adult: int}>, insuranceUnmapped: int, insuranceAgeUnknown: int, special: array<int|string, int>, povertyTableAvailable: bool} */
    private static function emptyTable4(): array
    {
        return ['income' => [], 'insurance' => [], 'insuranceUnmapped' => 0, 'insuranceAgeUnknown' => 0, 'special' => [], 'povertyTableAvailable' => true];
    }
}
