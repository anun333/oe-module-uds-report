<?php

/**
 * Strict checks for the decoded reference data files.
 *
 * Each check narrows a decoded JSON value to the expected type or throws a
 * ReferenceDataException naming the entry. Objects must have exactly the
 * expected fields, so a misspelled field fails instead of being ignored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final class JsonShape
{
    /**
     * A JSON object with exactly the given fields (optional ones may be left out).
     *
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $where, array $required, array $optional = []): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ReferenceDataException($where, 'must be an object');
        }
        $fields = [];
        foreach ($value as $field => $fieldValue) {
            if (!is_string($field) || (!in_array($field, $required, true) && !in_array($field, $optional, true))) {
                throw new ReferenceDataException($where, sprintf('unknown field "%s"', $field));
            }
            $fields[$field] = $fieldValue;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $fields)) {
                throw new ReferenceDataException($where, sprintf('the field "%s" is missing', $field));
            }
        }
        return $fields;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $where): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ReferenceDataException($where, 'must be a list');
        }
        return $value;
    }

    public static function text(mixed $value, string $where): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ReferenceDataException($where, 'must be text');
        }
        return $value;
    }

    public static function int(mixed $value, string $where, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ReferenceDataException($where, sprintf('must be a whole number from %d to %d', $min, $max));
        }
        return $value;
    }

    /**
     * @param non-empty-list<string> $allowed
     */
    public static function oneOf(mixed $value, string $where, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ReferenceDataException($where, 'must be one of ' . implode(', ', $allowed));
        }
        return $value;
    }

    /** A CDC Race & Ethnicity code, such as 2034-7. */
    public static function cdcCode(mixed $value, string $where): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d$/D', $value) !== 1) {
            throw new ReferenceDataException($where, 'must be a CDC code such as 2034-7');
        }
        return $value;
    }

    /** A {from, to} range of the first four digits of CDC codes. */
    public static function codeRange(mixed $value, string $where): CodeRange
    {
        $range = self::object($value, $where, ['from', 'to']);
        $from = self::codeStem($range['from'], $where . '.from');
        $to = self::codeStem($range['to'], $where . '.to');
        if ($from > $to) {
            throw new ReferenceDataException($where, '"from" is after "to"');
        }
        return new CodeRange($from, $to);
    }

    private static function codeStem(mixed $value, string $where): int
    {
        if (!is_string($value) || preg_match('/^\d{4}$/D', $value) !== 1) {
            throw new ReferenceDataException($where, 'must be the first four digits of a CDC code, such as "2028"');
        }
        return (int)$value;
    }
}
