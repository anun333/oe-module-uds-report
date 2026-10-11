<?php

/**
 * A worksheet for Table 9D, Accrued Patient Service Revenue (2026 UDS Manual,
 * pages 159-170), from OpenEMR's accounts receivable. It is an input for whoever
 * prepares Table 9D, not the table: Table 9D follows the health center's
 * audited financial statements, and capitation, settlements, wrap payments,
 * allowances for doubtful accounts, pharmacy and incentive revenue are usually
 * in the general ledger rather than in OpenEMR.
 *
 * Charges (Column A) are by date of service, and reclassified as FAQ 4 requires
 * (page 169): each payer is charged what it settled (its payments and
 * adjustments), and the balance still open goes to whoever owes it now, the
 * next insurer or the patient. Collections (Column B) and adjustments (Column
 * D) are by posting date (FAQ 6). Bad debt write-offs go to Line 15 instead of
 * Column D (direct write-off method). Product sales are pharmacy or supplies,
 * which OpenEMR doesn't tell apart, so they are reported separately.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\AdjustmentClass;
use OpenEMR\Modules\UdsReport\Enum\Table9DLine;
use OpenEMR\Modules\UdsReport\Model\ChargedEncounter;
use OpenEMR\Modules\UdsReport\Model\Posting;
use OpenEMR\Modules\UdsReport\ReferenceData\AdjustmentReasons;

/**
 * @phpstan-type Amounts array{billedFirst: int, charges: int, collections: int, adjustments: int, unclassified: int, net: int}
 * @phpstan-type Reason array{line: Table9DLine, reason: string, class: AdjustmentClass, note: ?string, matched: bool, cents: int, postings: int}
 */
final readonly class Table9DWorksheet
{
    /**
     * @param list<ChargedEncounter> $charged encounters with charges and a date of service in the year
     * @param list<Posting>          $posted  postings with a posting date in the year, whatever the date of service
     */
    public function __construct(private AdjustmentReasons $reasons, private array $charged, private array $posted)
    {
    }

    /**
     * Amounts in cents.
     *
     * @return array{
     *     lines: array<string, Amounts>,
     *     total: Amounts,
     *     badDebt: int,
     *     netBeforeOther: int,
     *     reasons: list<Reason>,
     *     encounters: int,
     *     creditBalances: array{encounters: int, cents: int},
     *     products: array{collections: int, adjustments: int},
     * }
     */
    public function result(): array
    {
        $zero = ['billedFirst' => 0, 'charges' => 0, 'collections' => 0, 'adjustments' => 0, 'unclassified' => 0, 'net' => 0];
        $lines = [];
        foreach (Table9DLine::cases() as $line) {
            $lines[$line->value] = $zero;
        }

        $credit = ['encounters' => 0, 'cents' => 0];
        foreach ($this->charged as $encounter) {
            $first = isset($encounter->insurers[1]) ? Table9DLine::forCategory($encounter->insurers[1]) : Table9DLine::SelfPay;
            $lines[$first->value]['billedFirst'] += $encounter->chargeCents;
            $balance = $encounter->chargeCents;
            foreach ($encounter->postings as $posting) {
                if ($posting->product) {
                    continue;
                }
                $settled = $posting->paidCents + $posting->adjustedCents;
                $lines[$posting->line->value]['charges'] += $settled;
                $balance -= $settled;
            }
            $lines[self::owing($encounter)->value]['charges'] += $balance;
            if ($balance < 0) {
                $credit['encounters']++;
                $credit['cents'] += $balance;
            }
        }

        $badDebt = 0;
        $products = ['collections' => 0, 'adjustments' => 0];
        /** @var array<string, Reason> $reasons */
        $reasons = [];
        foreach ($this->posted as $posting) {
            if ($posting->product) {
                $products['collections'] += $posting->paidCents;
                $products['adjustments'] += $posting->adjustedCents;
                continue;
            }
            $lines[$posting->line->value]['collections'] += $posting->paidCents;
            if ($posting->adjustedCents === 0) {
                continue;
            }
            $suggestion = $this->reasons->classify($posting->reason);
            if ($suggestion['class']->inColumnD()) {
                $lines[$posting->line->value]['adjustments'] += $posting->adjustedCents;
            } elseif ($suggestion['class'] === AdjustmentClass::BadDebt) {
                $badDebt += $posting->adjustedCents;
            } else {
                $lines[$posting->line->value]['unclassified'] += $posting->adjustedCents;
            }
            $key = $posting->line->value . '|' . AdjustmentReasons::normalize($posting->reason);
            $reasons[$key] ??= ['line' => $posting->line, 'reason' => self::display($posting->reason)] + $suggestion + ['cents' => 0, 'postings' => 0];
            $reasons[$key]['cents'] += $posting->adjustedCents;
            $reasons[$key]['postings']++;
        }

        $total = $zero;
        foreach ($lines as &$amounts) {
            $amounts['net'] = $amounts['charges'] - $amounts['adjustments'];
            foreach ($amounts as $column => $cents) {
                $total[$column] += $cents;
            }
        }
        unset($amounts);

        $order = array_flip(array_map(static fn(Table9DLine $l): string => $l->value, Table9DLine::cases()));
        $reasons = array_values($reasons);
        usort($reasons, static fn(array $a, array $b): int => [$order[$a['line']->value], -abs($a['cents'])] <=> [$order[$b['line']->value], -abs($b['cents'])]);

        return [
            'lines' => $lines,
            'total' => $total,
            'badDebt' => $badDebt,
            'netBeforeOther' => $total['net'] - $badDebt,
            'reasons' => $reasons,
            'encounters' => count($this->charged),
            'creditBalances' => $credit,
            'products' => $products,
        ];
    }

    /**
     * Who owes an encounter's open balance: the insurer at the level after the
     * last one closed, or the patient once no insurer is left.
     */
    public static function owing(ChargedEncounter $encounter): Table9DLine
    {
        $next = $encounter->insurers[$encounter->lastLevelClosed + 1] ?? null;
        return $next === null ? Table9DLine::SelfPay : Table9DLine::forCategory($next);
    }

    /** The reason as posted, without the Ins1, Ins2 or Ins3 OpenEMR may append. */
    private static function display(string $reason): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        $text = preg_replace('/ ins[1-3]$/iD', '', $text) ?? $text;
        return $text === '' ? xl('(no reason given)') : $text;
    }
}
