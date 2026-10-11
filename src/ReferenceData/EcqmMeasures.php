<?php

/**
 * The eCQMs that UDS Tables 6B and 7 use, by reporting year
 * (data/ecqm-measures.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

/**
 * @phpstan-type EcqmMeasure array{table: string, line: string, title: string, measure: string, page: int}
 */
final readonly class EcqmMeasures
{
    public const FILE = 'ecqm-measures';

    /** @param array<int, list<EcqmMeasure>> $years */
    public function __construct(private array $years)
    {
    }

    /** @return list<EcqmMeasure> empty for a year the file doesn't cover */
    public function forYear(int $year): array
    {
        return $this->years[$year] ?? [];
    }

    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $top = JsonShape::object($data, $file, ['about', 'basis', 'years']);
        JsonShape::text($top['about'], "$file about");
        JsonShape::text($top['basis'], "$file basis");
        if (!is_array($top['years']) || $top['years'] === [] || array_is_list($top['years'])) {
            throw new ReferenceDataException("$file years", 'must be an object of years');
        }
        $years = [];
        foreach ($top['years'] as $year => $list) {
            if (preg_match('/^20\d\d$/D', (string)$year) !== 1) {
                throw new ReferenceDataException("$file years", sprintf('"%s" is not a year', $year));
            }
            $seen = [];
            foreach (JsonShape::list($list, "$file years.$year") as $i => $raw) {
                $where = "$file years.$year" . "[$i]";
                $f = JsonShape::object($raw, $where, ['table', 'line', 'title', 'measure', 'page']);
                $measure = JsonShape::text($f['measure'], "$where measure");
                if (preg_match('/^CMS\d+v\d+$/D', $measure) !== 1) {
                    throw new ReferenceDataException("$where measure", 'must be a CMS id with its version, such as CMS165v14');
                }
                if (isset($seen[$measure])) {
                    throw new ReferenceDataException("$where measure", "$measure is listed twice");
                }
                $seen[$measure] = true;
                $years[(int)$year][] = [
                    'table' => JsonShape::oneOf($f['table'], "$where table", ['6B', '7']),
                    'line' => JsonShape::text($f['line'], "$where line"),
                    'title' => JsonShape::text($f['title'], "$where title"),
                    'measure' => $measure,
                    'page' => JsonShape::int($f['page'], "$where page", 1, 400),
                ];
            }
        }
        return new self($years);
    }
}
