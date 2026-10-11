<?php

/**
 * Suggested Table 5 line for an OpenEMR user, from the NUCC taxonomy code in the
 * user's Taxonomy field (data/table-5-taxonomy.json). The clinic confirms or
 * changes it in the module's settings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class ProviderTaxonomy
{
    public const FILE = 'table-5-taxonomy';

    /**
     * @param string                                                   $openemrDefault the code OpenEMR gives every new user
     * @param list<array{match: string, line: string, provider: bool}> $rules          first match wins
     */
    public function __construct(public string $openemrDefault, private array $rules)
    {
    }

    /**
     * The suggested line, and whether the person is a provider on it; null when no rule matches.
     *
     * @return ?array{line: string, provider: bool}
     */
    public function suggest(string $taxonomy): ?array
    {
        $code = strtoupper(trim($taxonomy));
        if ($code === '') {
            return null;
        }
        foreach ($this->rules as $rule) {
            if (strlen($rule['match']) === 10 ? $code === $rule['match'] : str_starts_with($code, $rule['match'])) {
                return ['line' => $rule['line'], 'provider' => $rule['provider']];
            }
        }
        return null;
    }

    public static function fromData(mixed $data, Table5 $table5): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $top = JsonShape::object($data, $file, ['about', 'basis', 'openemrDefault', 'rules']);
        JsonShape::text($top['about'], "$file about");
        JsonShape::text($top['basis'], "$file basis");
        $default = self::code(JsonShape::text($top['openemrDefault'], "$file openemrDefault"), "$file openemrDefault", true);

        $rules = [];
        $seen = [];
        foreach (JsonShape::list($top['rules'], "$file rules") as $i => $raw) {
            $where = "$file rules[$i]";
            $f = JsonShape::object($raw, $where, ['match', 'line'], ['provider', 'note']);
            $match = self::code(JsonShape::text($f['match'], "$where match"), "$where match", false);
            if (isset($seen[$match])) {
                throw new ReferenceDataException($where, "matches $match, as an earlier rule already does");
            }
            $seen[$match] = true;
            $id = JsonShape::text($f['line'], "$where line");
            $line = $table5->line($id);
            if ($line === null || !$line->isPersonnel()) {
                throw new ReferenceDataException("$where line", "$id is not a personnel line of Table 5");
            }
            if (isset($f['note'])) {
                JsonShape::text($f['note'], "$where note");
            }
            $rules[] = [
                'match' => $match,
                'line' => $id,
                'provider' => isset($f['provider']) ? JsonShape::bool($f['provider'], "$where provider") : true,
            ];
        }
        if ($rules === []) {
            throw new ReferenceDataException("$file rules", 'is empty');
        }
        return new self($default, $rules);
    }

    /** A full NUCC code is ten characters ending in X; a prefix is the start of one. */
    private static function code(string $value, string $where, bool $full): string
    {
        $pattern = $full ? '/^[0-9A-Z]{9}X$/' : '/^(?:[0-9A-Z]{9}X|[0-9A-Z]{1,9})$/';
        if (preg_match($pattern, $value) !== 1) {
            throw new ReferenceDataException($where, $full ? 'is not a NUCC taxonomy code' : 'is not a NUCC taxonomy code or the start of one');
        }
        return $value;
    }
}
