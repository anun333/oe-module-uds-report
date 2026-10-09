<?php

/**
 * Typed reads from a database row (QueryUtils returns untyped arrays).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

final class Row
{
    /**
     * Text in a column; '' for NULL.
     *
     * @param array<mixed> $row
     */
    public static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string)$value,
            default => throw new \UnexpectedValueException(sprintf('Column %s is not a scalar', $column)),
        };
    }

    /**
     * Text in a column, or null for NULL.
     *
     * @param array<mixed> $row
     */
    public static function nullableText(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::text($row, $column);
    }

    /**
     * A whole number in a column.
     *
     * @param array<mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        return self::nullableInt($row, $column)
            ?? throw new \UnexpectedValueException(sprintf('Column %s is not a whole number', $column));
    }

    /**
     * A whole number in a column, or null when it is NULL or not a whole number.
     *
     * @param array<mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/D', $value) === 1 ? (int)$value : null;
    }
}
