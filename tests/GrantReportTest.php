<?php

/**
 * Who is in each UDS Grant Report, and the manual's rule that no Grant Report
 * cell may be larger than the same cell of the Universal Report.
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
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Enum\HousingStatus;
use OpenEMR\Modules\UdsReport\Enum\SexAtBirth;
use OpenEMR\Modules\UdsReport\Model\UdsPatient;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\DemographicTables;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantReportTest extends TestCase
{
    private const PUBLIC_HOUSING_SITE = 9003;

    /**
     * @return array<string, array{GrantReport, UdsPatient, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function membership(): array
    {
        $cases = [];
        foreach (AgriculturalWorker::cases() as $status) {
            $in = in_array($status, [AgriculturalWorker::Migratory, AgriculturalWorker::Seasonal, AgriculturalWorker::FormerMigratory], true);
            $cases["330(g), {$status->value}"] = [GrantReport::AgriculturalWorkers, Patients::make(agriculture: $status), $in];
        }
        $cases['330(g), not asked'] = [GrantReport::AgriculturalWorkers, Patients::make(agriculture: null), false];
        foreach (HousingStatus::cases() as $status) {
            $in = !in_array($status, [HousingStatus::Housed, HousingStatus::Declined], true);
            $cases["330(h), {$status->value}"] = [GrantReport::Homeless, Patients::make(housing: $status), $in];
        }
        $cases['330(h), not asked'] = [GrantReport::Homeless, Patients::make(housing: null), false];
        $cases['330(i), seen at a public housing site'] = [GrantReport::PublicHousing, Patients::make(sites: [3, self::PUBLIC_HOUSING_SITE]), true];
        $cases['330(i), seen only elsewhere'] = [GrantReport::PublicHousing, Patients::make(sites: [3]), false];
        return $cases;
    }

    #[DataProvider('membership')]
    public function testMembership(GrantReport $grant, UdsPatient $patient, bool $in): void
    {
        self::assertSame($in, $grant->includes($patient, self::settings()));
    }

    public function testEveryGrantReportIsASubsetOfTheUniversalReport(): void
    {
        $reference = (new ReferenceDataLoader())->load();
        $patients = [
            Patients::make(pid: 1),
            Patients::make(pid: 2, agriculture: AgriculturalWorker::Migratory, housing: HousingStatus::Shelter),
            Patients::make(pid: 3, dob: '2015-03-01', sex: SexAtBirth::Male, agriculture: AgriculturalWorker::Seasonal),
            Patients::make(pid: 4, housing: HousingStatus::Street, sites: [self::PUBLIC_HOUSING_SITE]),
            Patients::make(pid: 5, sites: [3, self::PUBLIC_HOUSING_SITE], race: ['2054-5']),
            Patients::make(pid: 6, housing: HousingStatus::DoublingUp, dob: '1950-01-01', sex: SexAtBirth::Male),
            Patients::make(pid: 7, agriculture: AgriculturalWorker::Declined, housing: HousingStatus::Declined),
        ];
        $settings = self::settings();
        $universal = new DemographicTables($patients, 2026, $settings, $reference);
        $universalCells = self::cells($universal);
        $expected = [GrantReport::AgriculturalWorkers->value => 2, GrantReport::Homeless->value => 3, GrantReport::PublicHousing->value => 2];

        foreach (GrantReport::cases() as $grant) {
            $subset = array_values(array_filter($patients, static fn(UdsPatient $p): bool => $grant->includes($p, $settings)));
            $tables = new DemographicTables($subset, 2026, $settings, $reference);
            $total = $tables->table3A()['totalMale'] + $tables->table3A()['totalFemale'];
            self::assertSame($expected[$grant->value], $total, "{$grant->value} patients");
            self::assertSame($universal->table4()['special'][$grant->table4Line()], $total, "{$grant->value} total equals Universal Table 4 Line {$grant->table4Line()}");
            foreach (self::cells($tables) as $cell => $value) {
                self::assertLessThanOrEqual($universalCells[$cell] ?? 0, $value, "{$grant->value} $cell is no larger than the Universal Report's");
            }
        }
    }

    private static function settings(): UdsSettings
    {
        return new UdsSettings(publicHousingFacilityIds: [self::PUBLIC_HOUSING_SITE]);
    }

    /** @return array<string, int> every count on Tables 3A, 3B and 4, by path */
    private static function cells(DemographicTables $tables): array
    {
        $out = [];
        $walk = static function (mixed $value, string $path) use (&$walk, &$out): void {
            if (is_int($value)) {
                $out[$path] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $key => $child) {
                    $walk($child, "$path.$key");
                }
            }
        };
        $walk(['3A' => $tables->table3A(), '3B' => $tables->table3B(), '4' => $tables->table4()], 'T');
        return $out;
    }
}
