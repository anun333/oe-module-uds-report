<?php

/**
 * Amounts in whole cents, so that sums of OpenEMR's decimal(12,2) columns are
 * exact: a float total of thousands of postings can be off by a cent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final class Money
{
    /**
     * Cents in a decimal amount as MySQL returns it ("150.00", "-12.5", "0"); null or empty is 0.
     *
     * @throws \UnexpectedValueException for anything else, which a decimal column can't hold
     */
    public static function cents(?string $amount): int
    {
        $amount = trim($amount ?? '');
        if ($amount === '') {
            return 0;
        }
        if (preg_match('/^(-?)(\d{1,15})(?:\.(\d{1,2}))?$/D', $amount, $m) !== 1) {
            throw new \UnexpectedValueException(sprintf('"%s" is not an amount', $amount));
        }
        $cents = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    /** For people: 1,234.56 and -12.00. */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . number_format(intdiv(abs($cents), 100)) . sprintf('.%02d', abs($cents) % 100);
    }

    /** For a spreadsheet: 1234.56 and -12.00. */
    public static function plain(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . sprintf('.%02d', abs($cents) % 100);
    }
}
