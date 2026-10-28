<?php

/**
 * HRSA's UDS edit checks, run on this module's figures before the report goes into EHBs.
 *
 * HRSA doesn't publish its edit checks, only the messages they raised, with each year's
 * UDS data. data/hrsa-checks.json rebuilds the ones that read only figures this module
 * computes: what each compares, the threshold (stated in the message, or fitted to which
 * health centers HRSA flagged in 2025), and how well it matched HRSA's flags. A raised
 * check is a question HRSA is likely to ask, to correct or explain; a check not raised
 * doesn't mean HRSA won't raise it.
 *
 * Kinds:
 * - zero: `a` is 0 though `when` is at least the threshold
 * - greater: `a` is more than `b` (or equal, with orEqual, when `a` isn't 0)
 * - share_above, share_below, share_outside: `a` as a share of `b` against the threshold
 *   (share_outside takes [low, high]); minA skips a check when `a` is smaller
 * - change: `a` against last year's published `a`: [share of last year, smallest difference]
 * - share_change: the share `a`/`b` against last year's, in percentage points
 * - national: `a`/`b` against last year's national average, as a multiple of it
 *   ([low, high], or a low limit with direction down)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\GrantReport;

final readonly class HrsaChecks
{
    /** Why a check wasn't run: a figure the module doesn't compute. */
    public const NOT_COMPUTED = 'not-computed';
    /** No published report last year to compare with (no grant number, or none on file). */
    public const NO_PRIOR_YEAR = 'no-prior-year';
    /** Last year's figure is a count of 1 to 15, which HRSA hides in its public file. */
    public const HIDDEN = 'hidden';
    /** The file's national averages are for a year other than the one before the report's. */
    public const NOT_THIS_YEAR = 'other-year';

    private const KINDS = ['zero', 'greater', 'share_above', 'share_below', 'share_outside', 'change', 'share_change', 'national'];

    /**
     * @param list<array<string, mixed>> $checks       the `checks` of data/hrsa-checks.json
     * @param int                        $nationalYear the year of the national averages in the file
     */
    public function __construct(public array $checks, public int $nationalYear)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__, 2) . '/data/hrsa-checks.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read $path");
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['checks'] ?? null) || !is_int($data['basis']['nationalAverageYear'] ?? null)) {
            throw new \RuntimeException("$path is not a checks file");
        }
        foreach ($data['checks'] as $check) {
            if (!is_array($check) || !in_array($check['kind'] ?? null, self::KINDS, true) || !is_array($check['a'] ?? null) || !is_array($check['reports'] ?? null)) {
                throw new \RuntimeException("$path has a check this version can't run");
            }
        }
        return new self(array_values($data['checks']), $data['basis']['nationalAverageYear']);
    }

    /** The report's name in the checks file and HRSA's data: universal, or the grant report. */
    public static function reportKey(?GrantReport $grant): string
    {
        return match ($grant) {
            null => 'universal',
            GrantReport::Homeless => 'hch',
            GrantReport::AgriculturalWorkers => 'msaw',
            GrantReport::PublicHousing => 'phpc',
        };
    }

    /**
     * @param array<string, int|float>      $current this year's figures (UdsFigures::$values)
     * @param ?array<string, int|float|null> $prior   last year's published figures, null when there are none
     * @param list<string>                   $partial codes the module counts only in part
     * @return array{raised: list<array{id: string, edits: list<string>, message: string, partial: bool, fit: array<string, mixed>}>, passed: list<string>, notRun: list<array{id: string, reason: string}>}
     */
    public function run(string $report, int $year, array $current, ?array $prior, array $partial = []): array
    {
        $raised = [];
        $passed = [];
        $notRun = [];
        foreach ($this->checks as $check) {
            $id = (string)$check['id'];
            $entry = $check['reports'][$report] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $result = $this->evaluate($check, $entry, $year, $current, $prior);
            if (is_string($result)) {
                $notRun[] = ['id' => $id, 'reason' => $result];
            } elseif ($result === null) {
                $passed[] = $id;
            } else {
                $codes = [...$check['a'], ...($check['b'] ?? []), ...($check['when'] ?? [])];
                $raised[] = [
                    'id' => $id,
                    'edits' => array_map('strval', $entry['edits'] ?? []),
                    'message' => self::fill((string)$check['text'], $result),
                    'partial' => array_intersect($codes, $partial) !== [],
                    'fit' => is_array($entry['fit'] ?? null) ? $entry['fit'] : [],
                ];
            }
        }
        return ['raised' => $raised, 'passed' => $passed, 'notRun' => $notRun];
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, mixed> $entry
     * @param array<string, int|float> $current
     * @param ?array<string, int|float|null> $prior
     * @return array<string, int|float>|string|null the values to quote when raised, a reason when not run, null when passed
     */
    private function evaluate(array $check, array $entry, int $year, array $current, ?array $prior): array|string|null
    {
        $a = self::sum($check['a'], $current);
        $b = isset($check['b']) ? self::sum($check['b'], $current) : 0;
        if ($a === null || (isset($check['b']) && $b === null)) {
            return self::NOT_COMPUTED;
        }
        $t = $entry['threshold'] ?? null;
        $direction = $check['direction'] ?? 'both';
        $vals = ['a' => $a, 'b' => $b];
        switch ($check['kind']) {
            case 'zero':
                $when = self::sum($check['when'] ?? [], $current);
                if ($when === null) {
                    return self::NOT_COMPUTED;
                }
                $vals['when'] = $when;
                return ($a == 0 && $when >= max((float)($t ?? 1), 1.0)) ? $vals : null;
            case 'greater':
                return ($a > $b || (($check['orEqual'] ?? false) && $a > 0 && $a == $b)) ? $vals : null;
            case 'share_above':
            case 'share_below':
            case 'share_outside':
                if ($b <= 0 || (isset($check['minA']) && $a < $check['minA'])) {
                    return null;
                }
                $share = $a / $b;
                $vals['share'] = $share;
                $raise = match ($check['kind']) {
                    'share_above' => $share > (float)$t,
                    'share_below' => $share < (float)$t,
                    default => is_array($t) && ($share < (float)$t[0] || $share > (float)$t[1]),
                };
                return $raise ? $vals : null;
            case 'change':
            case 'share_change':
                if ($prior === null) {
                    return self::NO_PRIOR_YEAR;
                }
                $priorA = self::sum($check['a'], $prior);
                $priorB = isset($check['b']) ? self::sum($check['b'], $prior) : 0;
                if ($priorA === null || (isset($check['b']) && $priorB === null)) {
                    return self::hiddenOrMissing($check, $prior);
                }
                if ($check['kind'] === 'change') {
                    if ($priorA <= 0 || !is_array($t)) {
                        return null;
                    }
                    $change = ($a - $priorA) / $priorA;
                    $vals += ['prior' => $priorA, 'change' => $change];
                    $big = abs($a - $priorA) >= (float)$t[1];
                    return $big && self::beyond($change, (float)$t[0], $direction) ? $vals : null;
                }
                if ($b <= 0 || $priorB <= 0) {
                    return null;
                }
                $share = $a / $b;
                $priorShare = $priorA / $priorB;
                $vals += ['share' => $share, 'priorShare' => $priorShare];
                return self::beyond($share - $priorShare, (float)$t, $direction) ? $vals : null;
            case 'national':
                $national = $entry['national'] ?? null;
                if (!is_int($national) && !is_float($national) || $national <= 0) {
                    return self::NOT_COMPUTED;
                }
                // HRSA compares with the national average of the year before the report.
                if ($year !== $this->nationalYear + 1) {
                    return self::NOT_THIS_YEAR;
                }
                if ($b <= 0) {
                    return null;
                }
                $ratio = $a / $b;
                $vals += ['ratio' => $ratio, 'national' => (float)$national];
                $multiple = $ratio / $national;
                $raise = $direction === 'down'
                    ? $multiple < (float)$t
                    : is_array($t) && ($multiple < (float)$t[0] || $multiple > (float)$t[1]);
                return $raise ? $vals : null;
        }
        return self::NOT_COMPUTED;
    }

    private static function beyond(float $change, float $limit, string $direction): bool
    {
        return match ($direction) {
            'up' => $change > $limit,
            'down' => $change < -$limit,
            default => abs($change) > $limit,
        };
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, int|float|null> $prior
     */
    private static function hiddenOrMissing(array $check, array $prior): string
    {
        foreach ([...$check['a'], ...($check['b'] ?? [])] as $code) {
            if (array_key_exists($code, $prior) && $prior[$code] === null) {
                return self::HIDDEN;
            }
        }
        return self::NOT_COMPUTED;
    }

    /**
     * @param list<string> $codes
     * @param array<string, int|float|null> $figures
     */
    private static function sum(array $codes, array $figures): int|float|null
    {
        $total = 0;
        foreach ($codes as $code) {
            if (!array_key_exists($code, $figures) || $figures[$code] === null) {
                return null;
            }
            $total += $figures[$code];
        }
        return $total;
    }

    /** @param array<string, int|float> $vals */
    private static function fill(string $text, array $vals): string
    {
        $out = [];
        foreach ($vals as $key => $value) {
            $out['{' . $key . '}'] = match ($key) {
                'share', 'priorShare' => number_format(100 * $value, 1) . '%',
                'change' => ($value >= 0 ? '+' : '') . number_format(100 * $value, 0) . '%',
                'ratio', 'national' => number_format((float)$value, 2),
                default => number_format((float)$value, is_float($value) && floor($value) != $value ? 2 : 0),
            };
        }
        return strtr($text, $out);
    }
}
