<?php

/**
 * The value sets this site has fetched, with their codes normalized the way
 * visit codes are (Table6aCode::normalize), ready for matching.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ValueSet;

final readonly class ValueSetCodes
{
    /**
     * @param array<string, array{title: string, version: string, fetchedAt: string, codes: array<string, array<string, true>>}> $sets by OID; codes by code system
     */
    public function __construct(public array $sets)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @param list<string> $oids */
    public function hasAll(array $oids): bool
    {
        foreach ($oids as $oid) {
            if (!isset($this->sets[$oid])) {
                return false;
            }
        }
        return $oids !== [];
    }

    /**
     * The codes of several value sets together, by code system.
     *
     * @param list<string> $oids
     * @return array<string, array<string, true>>
     */
    public function codesFor(array $oids): array
    {
        $codes = [];
        foreach ($oids as $oid) {
            foreach ($this->sets[$oid]['codes'] ?? [] as $system => $set) {
                $codes[$system] = ($codes[$system] ?? []) + $set;
            }
        }
        return $codes;
    }

    /**
     * The code systems any fetched value set uses.
     *
     * @return list<string>
     */
    public function systems(): array
    {
        $systems = [];
        foreach ($this->sets as $set) {
            foreach (array_keys($set['codes']) as $system) {
                $systems[$system] = true;
            }
        }
        return array_keys($systems);
    }
}
