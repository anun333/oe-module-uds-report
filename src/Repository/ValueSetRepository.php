<?php

/**
 * Keeps the value sets this site fetched from VSAC: one row per value set in
 * uds_value_set, its codes in uds_value_set_code. Fetching again replaces a
 * value set whole.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\ReferenceData\Table6aCode;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetCodes;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetExpansion;

final class ValueSetRepository
{
    private const INSERT_BATCH = 500;

    /** None when the tables aren't there yet: the module's files updated, its SQL upgrade not yet run. */
    public function load(): ValueSetCodes
    {
        $tables = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS n FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('uds_value_set', 'uds_value_set_code')",
        );
        if ((Row::nullableInt($tables[0] ?? [], 'n') ?? 0) < 2) {
            return ValueSetCodes::none();
        }
        $sets = [];
        foreach (QueryUtils::fetchRecords('SELECT `oid`, `title`, `version`, `fetched_at` FROM `uds_value_set`') as $row) {
            $sets[Row::text($row, 'oid')] = [
                'title' => Row::text($row, 'title'),
                'version' => Row::text($row, 'version'),
                'fetchedAt' => Row::text($row, 'fetched_at'),
                'codes' => [],
            ];
        }
        foreach (QueryUtils::fetchRecords('SELECT `oid`, `system`, `code` FROM `uds_value_set_code`') as $row) {
            $oid = Row::text($row, 'oid');
            if (isset($sets[$oid])) {
                $sets[$oid]['codes'][Row::text($row, 'system')][Table6aCode::normalize(Row::text($row, 'code'))] = true;
            }
        }
        return new ValueSetCodes($sets);
    }

    public function save(ValueSetExpansion $set, int $userId): void
    {
        QueryUtils::inTransaction(static function () use ($set, $userId): void {
            QueryUtils::sqlStatementThrowException('DELETE FROM `uds_value_set_code` WHERE `oid` = ?', [$set->oid]);
            $rows = [];
            foreach ($set->codes as $system => $codes) {
                // The key ignores case, as the column's collation does.
                $unique = [];
                foreach ($codes as $code) {
                    $unique[strtoupper($code)] = $code;
                }
                foreach ($unique as $code) {
                    $rows[] = [$set->oid, $system, $code];
                }
            }
            foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
                QueryUtils::sqlStatementThrowException(
                    'INSERT INTO `uds_value_set_code` (`oid`, `system`, `code`) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')),
                    array_merge(...$chunk),
                );
            }
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `uds_value_set` (`oid`, `title`, `version`, `codes`, `fetched_by`, `fetched_at`) VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `version` = VALUES(`version`), `codes` = VALUES(`codes`),
                    `fetched_by` = VALUES(`fetched_by`), `fetched_at` = VALUES(`fetched_at`)',
                [$set->oid, mb_substr($set->title, 0, 255), mb_substr($set->version, 0, 63), count($rows), $userId > 0 ? $userId : null],
            );
        });
    }
}
