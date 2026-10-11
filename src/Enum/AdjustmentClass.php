<?php

/**
 * Where an adjustment goes on Table 9D (2026 UDS Manual, pages 162-167).
 * Contractual adjustments and discounts are both Column D; they are kept apart
 * only so the worksheet can show which is which.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum AdjustmentClass: string
{
    /** The difference between the charge and what a payer agreed to pay: Column D on that payer's line. */
    case Contractual = 'contractual';
    /** Sliding fee discounts, prompt pay discounts and hardship waivers: Column D. */
    case Discount = 'discount';
    /** Written off as uncollectable: Line 15, not Column D. */
    case BadDebt = 'bad_debt';
    /** No suggestion: whoever prepares Table 9D decides. */
    case Review = 'review';

    public function inColumnD(): bool
    {
        return $this === self::Contractual || $this === self::Discount;
    }

    public function label(): string
    {
        return match ($this) {
            self::Contractual => xl('Contractual (Column D)'),
            self::Discount => xl('Discount or waiver (Column D)'),
            self::BadDebt => xl('Bad debt (Line 15)'),
            self::Review => xl('Not classified'),
        };
    }
}
