<?php

/**
 * Each upgrade file only repeats install.sql's guarded blocks, so an upgraded
 * install ends up with exactly what a new one gets.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use PHPUnit\Framework\TestCase;

final class SqlUpgradeTest extends TestCase
{
    public function testEveryUpgradeBlockIsInInstallSql(): void
    {
        $root = dirname(__DIR__) . '/sql';
        $install = (string)file_get_contents("$root/install.sql");
        $upgrades = glob("$root/*_upgrade.sql") ?: [];
        self::assertNotSame([], $upgrades, 'the 0.6.0 to 0.7.0 upgrade file exists');
        foreach ($upgrades as $file) {
            self::assertMatchesRegularExpression('/^\d+_\d+_\d+-to-\d+_\d+_\d+_upgrade\.sql$/', basename($file), 'Module Manager only runs files named like 0_6_0-to-0_7_0_upgrade.sql');
            preg_match_all('/^#If\w+.*?^#EndIf$/ms', (string)file_get_contents($file), $blocks);
            self::assertNotSame([], $blocks[0], basename($file) . ' has guarded blocks');
            foreach ($blocks[0] as $block) {
                self::assertStringContainsString($block, $install, basename($file) . ' has a block install.sql lacks: ' . strtok($block, "\n"));
            }
        }
    }

    public function testTheUdsFieldsComeAfterTheStockStatsFields(): void
    {
        // The stock Demographics Stats group (5) ends with Religion (15) and Tribal Affiliations (16)
        // in OpenEMR 7.0 to 8.5. Before 0.15.1 two of ours shared those positions.
        $install = (string)file_get_contents(dirname(__DIR__) . '/sql/install.sql');
        preg_match_all("/VALUES \\('DEM', '(uds_\\w+)', '5', '[^']*', (\\d+),/", $install, $rows, PREG_SET_ORDER);
        self::assertCount(3, $rows, 'the three UDS fields are inserted');
        $positions = array_map(static fn(array $row): int => (int)$row[2], $rows);
        self::assertSame($positions, array_values(array_unique($positions)), 'each has a position of its own');
        self::assertGreaterThan(16, min($positions), 'after Tribal Affiliations (16)');
    }
}
