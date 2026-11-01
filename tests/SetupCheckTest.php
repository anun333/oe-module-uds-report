<?php

/**
 * tools/setup-check.php: a site's layouts and lists, read from the old Backup page's SQL file or a
 * Layout Export file as data, checked against what the module reads. The fixture is trimmed from
 * a real 7.0.3 export of a synthetic site.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Tools\SetupCheck\ConfigFile;
use OpenEMR\Modules\UdsReport\Tools\SetupCheck\SetupCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetupCheckTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/backup-703-uds.sql';

    private static function check(ConfigFile $file): SetupCheck
    {
        return new SetupCheck($file, RaceEthnicity::fromData((new ReferenceDataLoader())->read(RaceEthnicity::FILE)));
    }

    /** @return list<string> the texts of the findings with this status in this section */
    private static function texts(array $findings, string $section, string $status): array
    {
        return array_values(array_map(
            static fn(array $f): string => $f['text'],
            array_filter($findings, static fn(array $f): bool => str_starts_with($f['section'], $section) && $f['status'] === $status),
        ));
    }

    public function testReadsTheBackupPagesFile(): void
    {
        $file = ConfigFile::read(self::FIXTURE);
        $this->assertSame(['DEM', 'LBFsocial'], array_keys($file->layouts));
        $this->assertEqualsCanonicalizing(['race', 'ethnicity', 'language', 'sex'], array_keys($file->lists));
        $this->assertSame('2094-1', array_column($file->lists['race']['options'], 'notes', 'option_id')['pohnpeian']);
    }

    public function testFindsWhatMapsAndWhatDoesnt(): void
    {
        $findings = self::check(ConfigFile::read(self::FIXTURE))->findings();

        $race = implode("\n", self::texts($findings, 'Race', SetupCheck::OK));
        $this->assertStringContainsString('Line 2b: Chuukese, Pohnpeian', $race);
        $this->assertStringContainsString('Line 5: White', $race);
        $this->assertSame(1, count(self::texts($findings, 'Race', SetupCheck::LOOK)));
        $this->assertStringContainsString('Mortlockese (`mortlockese`)', self::texts($findings, 'Race', SetupCheck::LOOK)[0]);

        $this->assertStringContainsString('English (`English`)', self::texts($findings, 'Language', SetupCheck::OK)[0]);
        $this->assertStringContainsString('Pohnpeian', self::texts($findings, 'Language', SetupCheck::INFO)[0]);

        $dem = self::texts($findings, 'Demographics', SetupCheck::LOOK);
        $this->assertCount(1, $dem);
        $this->assertStringContainsString('Family Size (`family_size`) is turned off', $dem[0]);
        $this->assertStringContainsString('only the free-text Interpreter field', implode("\n", self::texts($findings, 'Demographics', SetupCheck::INFO)));

        $other = self::texts($findings, 'Other forms', SetupCheck::LOOK);
        $this->assertCount(4, $other);
        $this->assertStringContainsString('household_income', $other[0]);

        $this->assertStringContainsString('FSM MiCare', self::texts($findings, 'Not in this file', SetupCheck::ABSENT)[0]);
    }

    public function testAFieldSavedToDemographicsUnderAReadNameCounts(): void
    {
        $json = json_encode(['format' => 'openemr-layout-export', 'version' => 1, 'openemr' => '8.5.0', 'lists' => [], 'layouts' => [
            'LBFintake' => ['properties' => [['grp_form_id' => 'LBFintake', 'grp_group_id' => '', 'grp_title' => 'Intake']], 'fields' => [
                ['form_id' => 'LBFintake', 'field_id' => 'monthly_income', 'title' => 'Monthly Income', 'uor' => '1', 'source' => 'D', 'list_id' => ''],
                ['form_id' => 'LBFintake', 'field_id' => 'income_note', 'title' => 'Income note', 'uor' => '1', 'source' => 'D', 'list_id' => ''],
            ]],
        ]]);
        $findings = self::check(ConfigFile::fromJson((string)$json))->findings();
        $this->assertStringContainsString('so the module reads it', self::texts($findings, 'Other forms', SetupCheck::OK)[0]);
        $this->assertStringContainsString('under a name the module doesn\'t read', self::texts($findings, 'Other forms', SetupCheck::LOOK)[0]);
        $this->assertStringContainsString('Demographics layout (DEM) is not in the file', self::texts($findings, 'Demographics', SetupCheck::ABSENT)[0]);
    }

    /** @return array<string, array{string, bool}> the SQL, and whether it follows the whole fixture */
    public static function refusedFiles(): array
    {
        return [
            'another table' => ["INSERT INTO `patient_data` (`pid`, `fname`) VALUES (1,'A');", true],
            'a statement the page never wrote' => ['DROP TABLE list_options;', true],
            'a delete the page never wrote' => ['DELETE FROM list_options WHERE 1;', true],
            'nothing in it' => ['SET character_set_client = utf8;', false],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusesAFileWithAnythingElseInIt(string $sql, bool $afterFixture): void
    {
        $this->expectException(\RuntimeException::class);
        ConfigFile::fromSql(($afterFixture ? (string)file_get_contents(self::FIXTURE) . "\n" : '') . $sql);
    }

    public function testTheCommandLinePrintsTheReport(): void
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/setup-check.php') . ' ' . escapeshellarg(self::FIXTURE));
        $this->assertIsString($out);
        $this->assertStringStartsWith("# UDS setup check: backup-703-uds.sql\n", $out);
        $this->assertStringContainsString('**6 to look at**', $out);
    }
}
