<?php

/**
 * The service area from the year's patient origin (HRSA Scope of Project Policy Manual, 2026,
 * Chapter 2): the fewest ZIP codes that reach 75% of patients with a known ZIP, and the clinic's
 * Form 5B ZIP codes against them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Report\ServiceArea;
use PHPUnit\Framework\TestCase;

final class ServiceAreaTest extends TestCase
{
    public function testTheMinimumIsTheFewestZipCodesReaching75Percent(): void
    {
        $area = ServiceArea::from(['78701' => 50, '78702' => 30, '78703' => 15, '78704' => 5], 0, []);
        self::assertSame([['zip' => '78701', 'patients' => 50, 'cumulative' => 50.0], ['zip' => '78702', 'patients' => 30, 'cumulative' => 80.0]], $area->minimum);
        self::assertSame(100, $area->known);
    }

    public function testReaching75PercentExactlyIsEnough(): void
    {
        self::assertSame(['78701'], array_column(ServiceArea::from(['78701' => 75, '78702' => 25], 0, [])->minimum, 'zip'));
        self::assertSame(['78701', '78702'], array_column(ServiceArea::from(['78701' => 74, '78702' => 26], 0, [])->minimum, 'zip'), '74% is short');
    }

    public function testZipCodesWithTheSameCountAreTakenInZipOrder(): void
    {
        $area = ServiceArea::from(['78704' => 20, '78701' => 40, '78703' => 20, '78702' => 20], 0, []);
        self::assertSame(['78701', '78702', '78703'], array_column($area->minimum, 'zip'));
    }

    public function testUnknownResidenceIsLeftOutOfBothTheCountAndTheTotal(): void
    {
        $area = ServiceArea::from(['78701' => 50, '78702' => 30, '78703' => 20], 1000, []);
        self::assertSame([100, 1000], [$area->known, $area->unknown]);
        self::assertSame(['78701', '78702'], array_column($area->minimum, 'zip'));
    }

    public function testZipCodesTheTableFoldsIntoOtherStillCount(): void
    {
        // Twenty ZIP codes of five patients each: none is on the Patients by ZIP Code table, yet 15 make 75%.
        $counts = [];
        for ($i = 0; $i < 20; $i++) {
            $counts[(string)(78700 + $i)] = 5;
        }
        self::assertCount(15, ServiceArea::from($counts, 0, [])->minimum);
    }

    public function testForm5bIsComparedWithTheMinimum(): void
    {
        $area = ServiceArea::from(['78701' => 50, '78702' => 30, '78703' => 20], 0, ['78799', '78701', '78701']);
        self::assertSame(['78702'], $area->missing, 'in the 75% but not on Form 5B: a scope change to request');
        self::assertSame(['78799'], $area->noPatients, 'on Form 5B with no patients: information only');
        self::assertSame(['78701', '78799'], $area->form5b, 'once each, in order');
        self::assertSame(50, $area->inForm5b);
    }

    public function testAYearWithNoKnownZipHasNoMinimum(): void
    {
        $area = ServiceArea::from([], 12, ['78701']);
        self::assertSame([[], 0, 12, [], ['78701']], [$area->minimum, $area->known, $area->unknown, $area->missing, $area->noPatients]);
    }

    public function testWhatAClinicTypesIsReadAsFiveDigitZipCodes(): void
    {
        self::assertSame(
            ['zips' => ['12345', '23456', '34567'], 'rejected' => ['1234', 'abcde']],
            ServiceArea::parse("12345, 23456-7890\n34567 ; 1234 abcde 12345"),
        );
        self::assertSame(['zips' => [], 'rejected' => []], ServiceArea::parse("  \n "));
    }
}
