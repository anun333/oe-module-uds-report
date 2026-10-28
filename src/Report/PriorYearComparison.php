<?php

/**
 * This year's figures beside last year's published report: the cells that changed the
 * most, which are the ones HRSA's reviewers ask about.
 *
 * A cell is listed when both years have it, last year's figure isn't hidden, and it
 * changed by more than a quarter and by at least 50. That is the size of change HRSA's
 * patients-by-service checks raised in 2025 (data/hrsa-checks.json, fitted from HRSA's
 * published edit messages); it applies here to every cell, as a guide to what to review.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final readonly class PriorYearComparison
{
    public const SHARE = 0.25;
    public const DIFFERENCE = 50;

    /** Tables in the manual's order, for sorting. */
    private const TABLE_ORDER = ['3a' => 1, '3b' => 2, '4' => 3, '5' => 4, '6a' => 5, '6b' => 6, '7' => 7, '9d' => 8];

    /**
     * @param array<string, int|float>      $current this year's figures
     * @param array<string, int|float|null> $prior   last year's published figures
     * @return array{changed: list<array{code: string, current: int|float, prior: int|float, change: ?float}>, compared: int, hidden: int}
     *         `change` is null where last year's figure was 0
     */
    public static function compare(array $current, array $prior, float $share = self::SHARE, int $difference = self::DIFFERENCE): array
    {
        $changed = [];
        $compared = 0;
        $hidden = 0;
        foreach ($current as $code => $value) {
            // The Table 9D worksheet isn't the audited Table 9D: comparing it with the filed one would mislead.
            if (str_starts_with($code, 'T9d_') || !array_key_exists($code, $prior)) {
                continue;
            }
            $before = $prior[$code];
            if ($before === null) {
                $hidden++;
                continue;
            }
            $compared++;
            if (abs($value - $before) < $difference) {
                continue;
            }
            $change = $before > 0 ? ($value - $before) / $before : null;
            if ($change === null || abs($change) > $share) {
                $changed[] = ['code' => $code, 'current' => $value, 'prior' => $before, 'change' => $change];
            }
        }
        usort($changed, static fn(array $x, array $y): int => self::sortKey($x['code']) <=> self::sortKey($y['code']));
        return ['changed' => $changed, 'compared' => $compared, 'hidden' => $hidden];
    }

    /** @return array{int, int, string, string} table, line number, line, column */
    private static function sortKey(string $code): array
    {
        if (preg_match('/^T([0-9a-z]+)_L([0-9a-z]+)_C([0-9a-z]+)$/D', $code, $m) !== 1) {
            return [99, 0, $code, ''];
        }
        return [self::TABLE_ORDER[$m[1]] ?? 99, (int)$m[2], $m[2], $m[3]];
    }
}
