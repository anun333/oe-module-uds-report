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
        // on a fresh install of OpenEMR 7.0 to 8.5. Before 0.15.1 two of ours shared those positions.
        $install = (string)file_get_contents(dirname(__DIR__) . '/sql/install.sql');
        preg_match_all("/VALUES \\('DEM', '(uds_\\w+)', '5', '[^']*', (\\d+),/", $install, $rows, PREG_SET_ORDER);
        self::assertCount(3, $rows, 'the three UDS fields are inserted');
        $positions = array_map(static fn(array $row): int => (int)$row[2], $rows);
        self::assertSame($positions, array_values(array_unique($positions)), 'each has a position of its own');
        self::assertGreaterThan(16, min($positions), 'after Tribal Affiliations (16)');
    }

    public function testOnAnUpgradedSiteTheyMoveAfterTheLastStockStatsField(): void
    {
        // A site upgraded from 7.0.3 or earlier has the stock Stats fields in tens (7.0.4's upgrade renumbers the
        // group): Language 10, Ethnicity 20 ... Tribal Affiliations 150. 17 to 19 is between Language and Ethnicity.
        $install = (string)file_get_contents(dirname(__DIR__) . '/sql/install.sql');
        $after = "\\(SELECT `after` FROM \\(SELECT GREATEST\\(16, COALESCE\\(MAX\\(`seq`\\), 16\\)\\) AS `after` FROM `layout_options` WHERE `form_id` = 'DEM' AND `group_id` = '5' AND `field_id` IN \\(([^)]*)\\)\\) AS `stock`\\)";
        preg_match_all("/^#IfRow3D layout_options form_id DEM field_id (uds_\\w+) seq (\\d+)\\nUPDATE `layout_options` SET `seq` = $after \\+ (\\d) WHERE `form_id` = 'DEM' AND `field_id` = '(uds_\\w+)' AND `seq` = (\\d+) AND $after > 19;\\n#EndIf$/m", $install, $moves, PREG_SET_ORDER);
        self::assertCount(3, $moves, 'one guarded move per field');
        foreach ($moves as [, $field, $guard, $stock, $offset, $updated, $at, $stockAgain]) {
            self::assertSame([$field, $guard], [$updated, $at], 'only where it is still where the module put it');
            self::assertSame($stock, $stockAgain);
        }
        self::assertSame(['17', '18', '19'], array_column($moves, 2));
        self::assertSame(['1', '2', '3'], array_column($moves, 4), 'in the same order, after the last stock field');
        self::assertCount(1, array_unique(array_column($moves, 3)), 'from the same stock fields');
        foreach (['language', 'religion', 'tribal_affiliations', 'interpreter_needed', 'interpretter'] as $stock) {
            self::assertStringContainsString("'$stock'", $moves[0][3]);
        }
        self::assertStringNotContainsString("'uds_", $moves[0][3], 'never counting the UDS fields themselves');
    }
}
