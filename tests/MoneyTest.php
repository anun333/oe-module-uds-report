<?php

/**
 * Amounts are exact cents, read from MySQL's decimal text and written back for
 * people and spreadsheets.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Report\CsvRow;
use OpenEMR\Modules\UdsReport\Report\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{?string, int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function amounts(): array
    {
        return [
            'decimal' => ['150.00', 15000],
            'negative' => ['-12.50', -1250],
            'one decimal' => ['-12.5', -1250],
            'whole' => ['7', 700],
            'zero' => ['0.00', 0],
            'negative zero' => ['-0.00', 0],
            'large' => ['1234567.89', 123456789],
            'null' => [null, 0],
            'empty' => ['', 0],
        ];
    }

    #[DataProvider('amounts')]
    public function testCents(?string $amount, int $cents): void
    {
        self::assertSame($cents, Money::cents($amount));
    }

    public function testSumsAreExact(): void
    {
        // Ten 0.10 postings are 1.00 exactly, which a float total is not.
        self::assertSame(100, array_sum(array_map(Money::cents(...), array_fill(0, 10, '0.10'))));
        self::assertNotSame(1.0, array_sum(array_fill(0, 10, 0.1)));
    }

    /** @return array<string, array{string}> */
    public static function notAmounts(): array
    {
        return ['three decimals' => ['12.345'], 'words' => ['twelve'], 'exponent' => ['1e3'], 'comma' => ['1,200.00']];
    }

    #[DataProvider('notAmounts')]
    public function testOnlyDecimalAmountsAreRead(string $amount): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Money::cents($amount);
    }

    public function testFormats(): void
    {
        self::assertSame('1,234,567.89', Money::format(123456789));
        self::assertSame('-12.00', Money::format(-1200));
        self::assertSame('0.05', Money::format(5));
        self::assertSame('-0.05', Money::format(-5));
        self::assertSame('1234567.89', Money::plain(123456789));
        self::assertSame('-12.00', Money::plain(-1200));
    }

    public function testNegativeAmountsStayNumbersInTheCsv(): void
    {
        self::assertSame('-12.00', CsvRow::cell('-12.00'));
        self::assertSame('-5', CsvRow::cell('-5'));
        self::assertSame("'-1+2", CsvRow::cell('-1+2'), 'anything else starting with - is still guarded');
        self::assertSame("'-12.00 ", CsvRow::cell('-12.00 '));
    }
}
