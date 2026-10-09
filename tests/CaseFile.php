<?php

/**
 * Reads the UDS Report module's shared test cases (data/cases/*.json) with
 * typed accessors, so a malformed case fails loudly instead of passing by accident.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;

final class CaseFile
{
    private const DIR = ReferenceDataLoader::MODULE_DATA_DIR . '/cases';

    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        $json = file_get_contents(self::DIR . '/' . $name . '.json');
        if ($json === false) {
            throw new \RuntimeException(sprintf('Case file %s.json not found', $name));
        }
        return self::object(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * The cases in one list of a file, keyed by name (names must be unique).
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(string $name, string $list): array
    {
        $out = [];
        foreach (self::list(self::load($name)[$list] ?? null) as $value) {
            $case = self::object($value);
            $caseName = self::string($case['name'] ?? null);
            if (isset($out[$caseName])) {
                throw new \LogicException(sprintf('%s.json: case "%s" is listed twice', $name, $caseName));
            }
            $out[$caseName] = [$case];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Expected an object');
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string)$key] = $item;
        }
        return $out;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Expected a list');
        }
        return $value;
    }

    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Expected text');
        }
        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Expected a whole number');
        }
        return $value;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }

    public static function bool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \UnexpectedValueException('Expected true or false');
        }
        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_map(self::string(...), self::list($value));
    }

    /** @return list<int> */
    public static function ints(mixed $value): array
    {
        return array_map(self::int(...), self::list($value));
    }
}
