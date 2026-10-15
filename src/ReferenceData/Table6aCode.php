<?php

/**
 * One code entry from a Table 6A line, in the manual's notation:
 *
 *   J45-                         every code starting J45
 *   B20                          exactly B20
 *   A15- through A19-            A15 to A19, each end read as a prefix
 *   D21xx through D29xx          x is any digit (D2100 to D2999)
 *   O24- (exclude O24.4-)        minus the codes the exclusions match
 *   J40 (unless U07.1 at the same visit)
 *
 * Dots and case are ignored. A range compares a code's first characters with
 * each end: a code is in "B16.0 through B16.2" when its first four characters
 * are at least B160 and at most B162.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class Table6aCode
{
    private const EXACT = 'exact';
    private const PREFIX = 'prefix';
    private const RANGE = 'range';

    /**
     * @param list<self> $exclusions
     */
    private function __construct(
        private string $kind,
        private string $from,
        private string $to,
        private array $exclusions,
        public ?string $unless,
    ) {
    }

    public static function normalize(string $code): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($code)));
    }

    public static function parse(string $spec, string $where): self
    {
        $spec = trim($spec);
        if (preg_match('/^(.+?) \(unless (\S+) at the same visit\)$/', $spec, $m) === 1) {
            $base = self::item($m[1], $where);
            return new self($base->kind, $base->from, $base->to, [], self::token($m[2], $where));
        }
        if (preg_match('/^(.+?) \(exclude (.+)\)$/', $spec, $m) === 1) {
            $base = self::item($m[1], $where);
            $parts = preg_split('/, | and /', $m[2]) ?: [];
            $exclusions = array_map(static fn(string $p): self => self::item($p, $where), $parts);
            return new self($base->kind, $base->from, $base->to, $exclusions, null);
        }
        return self::item($spec, $where);
    }

    /** Whether a code (any case, dots allowed) is in this entry, exclusions applied. */
    public function matches(string $code): bool
    {
        $code = self::normalize($code);
        if ($code === '' || !$this->covers($code)) {
            return false;
        }
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->covers($code)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether this entry's own "(exclude ...)" removes a code. The manual's
     * exclusions hold for a line's value set codes too: line 9's value set
     * has the gestational diabetes codes its O24- entry excludes.
     */
    public function excludes(string $code): bool
    {
        $code = self::normalize($code);
        if ($code === '' || !$this->covers($code)) {
            return false;
        }
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->covers($code)) {
                return true;
            }
        }
        return false;
    }

    private function covers(string $code): bool
    {
        return match ($this->kind) {
            self::EXACT => $code === $this->from,
            self::PREFIX => str_starts_with($code, $this->from),
            default => substr($code, 0, strlen($this->from)) >= $this->from
                && substr($code, 0, strlen($this->to)) <= $this->to,
        };
    }

    private static function item(string $text, string $where): self
    {
        $text = trim($text);
        if (preg_match('/^(\S+) through (\S+)$/', $text, $m) === 1) {
            // Lower-case x is the manual's digit wildcard; upper-case X starts real ICD-10 codes (X30-).
            $from = self::token(str_replace('x', '0', $m[1]), $where);
            $to = self::token(str_replace('x', '9', $m[2]), $where);
            if ($from > $to) {
                throw new ReferenceDataException($where, sprintf('"%s" runs backwards', $text));
            }
            return new self(self::RANGE, $from, $to, [], null);
        }
        if (str_ends_with($text, '-')) {
            return new self(self::PREFIX, self::token($text, $where), '', [], null);
        }
        return new self(self::EXACT, self::token($text, $where), '', [], null);
    }

    /** A code without its trailing dash, normalized; anything else stops the load. */
    private static function token(string $text, string $where): string
    {
        if (preg_match('/^[A-Za-z0-9.]+-?$/', $text) !== 1) {
            throw new ReferenceDataException($where, sprintf('"%s" is not a code', $text));
        }
        return self::normalize(rtrim($text, '-'));
    }
}
