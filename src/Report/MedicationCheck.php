<?php

/**
 * Which tobacco cessation (Line 26c2) and opioid use disorder (Line 26c3)
 * medications Table 6A misses: a drug named like one of them whose RxNorm code
 * isn't in the line's value set. A prescription coded only by ingredient, or
 * not coded at all, is the usual case. Methadone and naltrexone have other
 * uses, so staff check each one; bupropion is left out, since it is mostly
 * prescribed as an antidepressant.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\ReferenceData\Table6aLine;

final class MedicationCheck
{
    /** The words that name each line's medications, as they appear in a drug name. */
    public const WORDS = [
        '26c2' => ['nicotine', 'varenicline'],
        '26c3' => ['buprenorphine', 'methadone', 'naltrexone'],
    ];

    /**
     * The line a medication is named for but not counted on, or null when it
     * names none of them, or its codes do put it on the line, or that line
     * isn't counted from its value set yet.
     *
     * @param list<string>              $codes normalized RxNorm codes on the record
     * @param array<string, Table6aLine> $lines Table 6A lines by line, with the fetched value sets applied
     */
    public static function uncountedLine(string $drugName, array $codes, array $lines): ?string
    {
        $name = strtolower($drugName);
        foreach (self::WORDS as $id => $words) {
            foreach ($words as $word) {
                if (!str_contains($name, $word)) {
                    continue;
                }
                $line = $lines[$id] ?? null;
                if ($line === null || !$line->usesValueSets()) {
                    return null;
                }
                return $line->matches(['rxnorm' => $codes]) ? null : $id;
            }
        }
        return null;
    }

    /**
     * Every word, for the query that finds candidate records.
     *
     * @return list<string>
     */
    public static function allWords(): array
    {
        return array_merge(...array_values(self::WORDS));
    }
}
