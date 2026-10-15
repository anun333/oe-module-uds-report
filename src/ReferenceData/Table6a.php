<?php

/**
 * The lines of UDS Table 6A, loaded strictly from data/table-6a.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use OpenEMR\Modules\UdsReport\ValueSet\ValueSetCodes;

final readonly class Table6a
{
    public const FILE = 'table-6a';
    public const SYSTEMS = ['icd10', 'cpt', 'hcpcs', 'cdt', 'snomed'];

    /** @param list<Table6aLine> $lines */
    public function __construct(public array $lines)
    {
    }

    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $top = JsonShape::object($data, $file, ['about', 'basis', 'codeSystems', 'lines']);
        JsonShape::text($top['about'], "$file about");
        JsonShape::text($top['basis'], "$file basis");
        $systems = JsonShape::object($top['codeSystems'], "$file codeSystems", self::SYSTEMS);
        foreach ($systems as $system => $text) {
            JsonShape::text($text, "$file codeSystems.$system");
        }

        $lines = [];
        $seen = [];
        foreach (JsonShape::list($top['lines'], "$file lines") as $i => $raw) {
            $where = "$file lines[$i]";
            $f = JsonShape::object($raw, $where, ['line', 'section', 'group', 'label', 'codes', 'computed'], ['valueSets', 'withValueSets', 'ages', 'limit']);
            $id = JsonShape::text($f['line'], "$where line");
            $where = "$file line $id";
            if (isset($seen[$id])) {
                throw new ReferenceDataException($where, 'appears twice');
            }
            $seen[$id] = true;

            $codes = [];
            foreach (JsonShape::object($f['codes'], "$where codes", [], self::SYSTEMS) as $system => $list) {
                foreach (JsonShape::list($list, "$where codes.$system") as $j => $spec) {
                    $codes[$system][] = Table6aCode::parse(JsonShape::text($spec, "{$where} codes.{$system}[{$j}]"), "{$where} codes.{$system}[{$j}]");
                }
            }
            $valueSets = [];
            foreach (JsonShape::list($f['valueSets'] ?? [], "$where valueSets") as $j => $oid) {
                $oid = JsonShape::text($oid, "$where valueSets[$j]");
                if (preg_match('/^\d+(\.\d+)+$/', $oid) !== 1) {
                    throw new ReferenceDataException("$where valueSets[$j]", 'is not an OID');
                }
                $valueSets[] = $oid;
            }
            $computed = JsonShape::oneOf($f['computed'], "$where computed", [
                Table6aLine::COMPUTED_FULL, Table6aLine::COMPUTED_PARTIAL, Table6aLine::COMPUTED_NONE,
            ]);
            $limit = isset($f['limit']) ? JsonShape::text($f['limit'], "$where limit") : null;
            if ($computed !== Table6aLine::COMPUTED_FULL && $limit === null) {
                throw new ReferenceDataException($where, 'a partial or uncounted line needs a limit saying what is missing');
            }
            if ($computed === Table6aLine::COMPUTED_NONE ? $codes !== [] : $codes === []) {
                throw new ReferenceDataException($where, $computed === Table6aLine::COMPUTED_NONE
                    ? 'an uncounted line must have no codes'
                    : 'a counted line needs codes');
            }

            $withValueSets = null;
            if (isset($f['withValueSets'])) {
                if ($valueSets === []) {
                    throw new ReferenceDataException("$where withValueSets", 'needs valueSets');
                }
                $w = JsonShape::object($f['withValueSets'], "$where withValueSets", ['computed'], ['limit']);
                $withComputed = JsonShape::oneOf($w['computed'], "$where withValueSets computed", [
                    Table6aLine::COMPUTED_FULL, Table6aLine::COMPUTED_PARTIAL,
                ]);
                $withLimit = isset($w['limit']) ? JsonShape::text($w['limit'], "$where withValueSets limit") : null;
                if (($withComputed === Table6aLine::COMPUTED_PARTIAL) !== ($withLimit !== null)) {
                    throw new ReferenceDataException("$where withValueSets", 'a partial line needs a limit, and only a partial line has one');
                }
                $withValueSets = ['computed' => $withComputed, 'limit' => $withLimit];
            }

            $lines[] = new Table6aLine(
                $id,
                JsonShape::oneOf($f['section'], "$where section", [
                    Table6aLine::SECTION_DIAGNOSES, Table6aLine::SECTION_SERVICES, Table6aLine::SECTION_DENTAL,
                ]),
                JsonShape::text($f['group'], "$where group"),
                JsonShape::text($f['label'], "$where label"),
                $codes,
                $valueSets,
                isset($f['ages']) ? self::ages($f['ages'], "$where ages") : null,
                $computed,
                $limit,
                $withValueSets,
            );
        }
        if ($lines === []) {
            throw new ReferenceDataException("$file lines", 'is empty');
        }
        return new self($lines);
    }

    /** The lines with every one of their value sets fetched counted from them as well. */
    public function withValueSets(ValueSetCodes $fetched): self
    {
        return new self(array_map(static fn(Table6aLine $line): Table6aLine => $line->withValueSetCodes($fetched), $this->lines));
    }

    /**
     * Every value set the lines name, each once, in line order.
     *
     * @return list<string>
     */
    public function valueSetOids(): array
    {
        $oids = [];
        foreach ($this->lines as $line) {
            foreach ($line->valueSets as $oid) {
                $oids[$oid] = true;
            }
        }
        return array_keys($oids);
    }

    /** @return array{0: int, 1: int} inclusive months */
    private static function ages(mixed $value, string $where): array
    {
        $f = JsonShape::object($value, $where, [], ['fromYears', 'toYears', 'fromMonths', 'toMonths']);
        if (isset($f['fromYears'], $f['toYears']) && count($f) === 2) {
            $from = JsonShape::int($f['fromYears'], "$where fromYears", 0, 130);
            $to = JsonShape::int($f['toYears'], "$where toYears", $from, 130);
            return [$from * 12, $to * 12 + 11];
        }
        if (isset($f['fromMonths'], $f['toMonths']) && count($f) === 2) {
            $from = JsonShape::int($f['fromMonths'], "$where fromMonths", 0, 1560);
            return [$from, JsonShape::int($f['toMonths'], "$where toMonths", $from, 1560)];
        }
        throw new ReferenceDataException($where, 'needs fromYears and toYears, or fromMonths and toMonths');
    }
}
