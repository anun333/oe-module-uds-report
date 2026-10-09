<?php

/**
 * Writes one row of the UDS CSV download.
 *
 * Text cells that a spreadsheet would read as a formula (starting with =, +, -
 * or @) are prefixed with an apostrophe, so opening the file can't run one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class CsvRow
{
    /**
     * @param resource         $out
     * @param array<int|string> $cells
     */
    public static function write($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), array_values($cells)), escape: '');
    }

    public static function cell(int|string $value): int|string
    {
        return is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
