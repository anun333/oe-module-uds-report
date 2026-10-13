<?php

/**
 * The Table 9D worksheet: charges split among the payers responsible for them
 * (FAQ 4, page 169), collections and adjustments by posting date, adjustments
 * sorted by reason into Column D, Line 15 or left for review. Every expected
 * figure below is worked out by hand from the postings listed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tests;

use OpenEMR\Modules\UdsReport\Enum\AdjustmentClass;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory as Ins;
use OpenEMR\Modules\UdsReport\Enum\Table9DLine as L;
use OpenEMR\Modules\UdsReport\Model\ChargedEncounter;
use OpenEMR\Modules\UdsReport\Model\Posting;
use OpenEMR\Modules\UdsReport\ReferenceData\AdjustmentReasons;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataException;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Report\Table9DWorksheet;
use OpenEMR\Modules\UdsReport\Repository\Table9DRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Table9DTest extends TestCase
{
    public function testWorksheetFromHandWorkedPostings(): void
    {
        // 1. Medicare then Medicaid (dual eligible), both finished: each is charged what it settled.
        $e1 = [
            self::paid(1, L::Medicare, 8000),
            self::adjusted(1, L::Medicare, 10000, 'Adjust code 45'),
            self::paid(2, L::Medicaid, 1500),
            self::adjusted(2, L::Medicaid, 500, 'Ins adjust Ins2'),
        ];
        // 2. Private, finished: copay and sliding discount are the patient's, and so is the balance left.
        $e2 = [
            self::paid(0, L::SelfPay, 2500),
            self::paid(1, L::Private, 7500),
            self::adjusted(1, L::Private, 3000, 'Ins adjust Ins1'),
            self::adjusted(0, L::SelfPay, 1000, 'Discount'),
        ];
        $product = new Posting(0, L::SelfPay, 4000, 0, '', '2026-05-01', true);
        // 3. Uninsured: sliding fee discount, a payment, the rest written off as bad debt.
        $e3 = [
            self::adjusted(0, L::SelfPay, 6000, 'Discount'),
            self::paid(0, L::SelfPay, 2000),
            self::adjusted(0, L::SelfPay, 2000, 'Bad debt'),
        ];
        // 4. Medicaid, claim still open: the balance stays with Medicaid.
        $e4 = [self::paid(0, L::SelfPay, 300)];
        // 5. Private overpaid: a credit balance, owed back by the patient's account.
        $e5 = [self::paid(1, L::Private, 5700)];
        $charged = [
            new ChargedEncounter(20000, 2, [1 => Ins::Medicare, 2 => Ins::Medicaid], $e1),
            new ChargedEncounter(15000, 1, [1 => Ins::Private], [...$e2, $product]),
            new ChargedEncounter(10000, 0, [], $e3),
            new ChargedEncounter(12000, 0, [1 => Ins::Medicaid], $e4),
            new ChargedEncounter(5000, 1, [1 => Ins::Private], $e5),
        ];
        $posted = [
            ...$e1, ...$e2, $product, ...$e3, ...$e4, ...$e5,
            self::paid(1, L::Medicare, 5000),                      // for a 2025 visit: Column B only
            self::adjusted(1, L::Medicare, 700, 'Adm adjust'),      // a rule that makes no suggestion
            self::paid(1, L::Unmapped, 1200),
            self::adjusted(1, L::Medicare, 400, 'Untimely filing Ins1'),
            self::adjusted(0, L::SelfPay, 250, 'Mystery reason'),   // no rule
            self::adjusted(1, L::Private, 0, 'Ins1 ptresp: 20.00'), // a note, not an adjustment
            self::paid(1, L::Private, -500),                        // a takeback
        ];

        $r = (new Table9DWorksheet(self::reasons(), $charged, $posted))->result();

        $expect = [
            //                  billed first, A,     B,     D,     not classified
            L::Medicaid->value => [12000, 13700, 1500, 500, 0],
            L::Medicare->value => [20000, 18000, 13000, 10000, 700],
            L::OtherPublic->value => [0, 0, 0, 0, 0],
            L::Private->value => [20000, 16200, 12700, 3000, 0],
            L::SelfPay->value => [10000, 14100, 4800, 7000, 250],
            L::Uninsured->value => [0, 0, 0, 0, 0],
            L::Unmapped->value => [0, 0, 1200, 0, 0],
        ];
        foreach ($expect as $line => [$first, $a, $b, $d, $review]) {
            $got = $r['lines'][$line];
            self::assertSame(
                ['billedFirst' => $first, 'charges' => $a, 'collections' => $b, 'adjustments' => $d, 'unclassified' => $review, 'net' => $a - $d],
                $got,
                "line $line",
            );
        }
        self::assertSame(62000, $r['total']['charges'], 'every charge is on exactly one line');
        self::assertSame(62000, $r['total']['billedFirst']);
        self::assertSame(20500, $r['total']['adjustments']);
        self::assertSame(41500, $r['total']['net']);
        self::assertSame(2400, $r['badDebt'], 'Bad debt and Untimely filing');
        self::assertSame(39100, $r['netBeforeOther'], 'Line 16 = Line 14 G - Line 15');
        self::assertSame(['encounters' => 1, 'cents' => -700], $r['creditBalances']);
        self::assertSame(['collections' => 4000, 'adjustments' => 0], $r['products']);
        self::assertSame(5, $r['encounters']);

        $reasons = array_map(
            static fn(array $x): array => [$x['line']->value, $x['reason'], $x['class']->value, $x['matched'], $x['cents'], $x['postings']],
            $r['reasons'],
        );
        self::assertSame([
            ['3', 'Ins adjust', 'contractual', true, 500, 1],
            ['6', 'Adjust code 45', 'contractual', true, 10000, 1],
            ['6', 'Adm adjust', 'review', true, 700, 1],
            ['6', 'Untimely filing', 'bad_debt', true, 400, 1],
            ['12', 'Ins adjust', 'contractual', true, 3000, 1],
            ['13', 'Discount', 'discount', true, 7000, 2],
            ['13', 'Bad debt', 'bad_debt', true, 2000, 1],
            ['13', 'Mystery reason', 'review', false, 250, 1],
        ], $reasons);
    }

    public function testWhoOwesTheOpenBalance(): void
    {
        $owing = static fn(int $closed, array $insurers): L => Table9DWorksheet::owing(new ChargedEncounter(100, $closed, $insurers, []));
        self::assertSame(L::Medicare, $owing(0, [1 => Ins::Medicare, 2 => Ins::Private]), 'nothing closed: the primary');
        self::assertSame(L::Private, $owing(1, [1 => Ins::Medicare, 2 => Ins::Private]), 'primary closed: the secondary');
        self::assertSame(L::SelfPay, $owing(2, [1 => Ins::Medicare, 2 => Ins::Private]), 'every insurer closed: the patient');
        self::assertSame(L::SelfPay, $owing(1, [1 => Ins::Medicare]), 'no secondary: the patient');
        self::assertSame(L::SelfPay, $owing(0, []), 'uninsured');
        self::assertSame(L::Uninsured, $owing(0, [1 => Ins::None]), 'an insurer mapped to None stays visible');
    }

    public function testPostingLine(): void
    {
        $insurers = [1 => Ins::Medicaid, 2 => Ins::Private];
        self::assertSame(L::SelfPay, L::forPosting(0, Ins::Medicare, $insurers), 'a patient posting is Self-Pay whoever the batch names');
        self::assertSame(L::Medicare, L::forPosting(1, Ins::Medicare, $insurers), 'the insurer that paid, over the one on file');
        self::assertSame(L::Private, L::forPosting(2, null, $insurers), 'no payer named: the insurer on file at that level');
        self::assertSame(L::Unmapped, L::forPosting(3, null, $insurers), 'nobody on file at that level');
        self::assertSame(L::OtherPublic, L::forPosting(1, Ins::OtherPublicChip, []), 'CHIP through a private plan is Other Public');
        self::assertSame(L::Medicaid, L::forPosting(1, Ins::ChipMedicaid, []), 'CHIP through Medicaid is Medicaid');
        self::assertSame(L::Uninsured, L::forPosting(1, Ins::None, []));
    }

    public function testInsurersOnTheDateOfService(): void
    {
        $companies = [10 => Ins::Medicaid, 20 => Ins::Private, 30 => Ins::Medicare];
        $rows = [
            ['type' => 'primary', 'provider' => '20', 'date' => '2024-01-01', 'date_end' => null],
            ['type' => 'primary', 'provider' => '10', 'date' => '2026-02-01', 'date_end' => null],
            ['type' => 'secondary', 'provider' => '30', 'date' => '2025-01-01', 'date_end' => '2026-03-31'],
            ['type' => 'tertiary', 'provider' => '99', 'date' => null, 'date_end' => null],
            ['type' => 'secondary', 'provider' => '20', 'date' => '2026-09-01', 'date_end' => null],
        ];
        self::assertSame([1 => Ins::Private, 2 => Ins::Medicare, 3 => Ins::Unmapped], Table9DRepository::insurersAt($rows, '2026-01-15', $companies));
        self::assertSame([1 => Ins::Medicaid, 3 => Ins::Unmapped], Table9DRepository::insurersAt($rows, '2026-05-01', $companies), 'the later primary; the secondary ended');
        self::assertSame([1 => Ins::Medicaid, 2 => Ins::Private, 3 => Ins::Unmapped], Table9DRepository::insurersAt($rows, '2026-09-01', $companies));
        self::assertSame([], Table9DRepository::insurersAt([], '2026-09-01', $companies));
    }

    public function testShippedReasonsSuggestATreatment(): void
    {
        $reasons = self::reasons();
        $class = static fn(string $reason): string => $reasons->classify($reason)['class']->value;
        self::assertSame('contractual', $class('Ins adjust Ins1'), 'Ins1 is removed before matching');
        self::assertSame('bad_debt', $class('  BAD   debt '), 'case and spacing are ignored');
        self::assertSame('bad_debt', $class('Adjust code 29'), 'an exact rule before the prefix rule');
        self::assertSame('contractual', $class('Adjust code 45'));
        self::assertSame('contractual', $class('Adjust code 253'));
        self::assertSame('discount', $class('Hardship w/o'));
        self::assertSame('discount', $class('Sliding fee'), 'a prefix rule');
        self::assertSame('discount', $class('Sliding Fee Discount'));
        self::assertSame('discount', $class('sliding  scale'));
        self::assertSame('review', $class('Fee sliding'), 'the prefix must start the reason');
        self::assertSame('bad_debt', $class('Adj from collt report'));
        self::assertSame(['class' => AdjustmentClass::Review, 'note' => null, 'matched' => false], $reasons->classify('Mystery reason'));
        self::assertTrue($reasons->classify('Pt adjust')['matched'], 'the batch screen reason has a rule, which leaves it for review');
    }

    /**
     * Every reason in OpenEMR's stock Adjustment Reasons list that posts an
     * amount (option_value 1) has a rule, so a clinic using the stock list sees
     * no reason the module doesn't know. The list is sql/database.sql at
     * OpenEMR commit 88a85cead1.
     */
    public function testEveryStockAdjustmentReasonHasARule(): void
    {
        $stock = ['Adm adjust', 'After hrs calls', 'Bad check', 'Bad debt', 'Coll w/o', 'Discount', 'Hardship w/o', 'Ins adjust',
            'Ins bundling', 'Pt released', 'Sm debt w/o', 'Untimely filing'];
        $reasons = self::reasons();
        foreach ($stock as $reason) {
            self::assertTrue($reasons->classify($reason)['matched'], $reason);
        }
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function badReasonFiles(): array
    {
        $rule = ['match' => 'exact', 'label' => 'Bad debt', 'class' => 'bad_debt'];
        $file = static fn(array $rules): array => ['about' => 'a', 'basis' => 'b', 'rules' => $rules];
        return [
            'same reason twice, spelled differently' => [$file([$rule, ['label' => 'BAD  DEBT Ins1'] + $rule]), 'rules[1] label'],
            'unknown class' => [$file([['class' => 'writeoff'] + $rule]), 'rules[0] class'],
            'unknown match' => [$file([['match' => 'contains'] + $rule]), 'rules[0] match'],
            'misspelled field' => [$file([['nte' => 'x'] + $rule]), 'unknown field "nte"'],
            'no rules' => [$file([]), 'rules: is empty'],
        ];
    }

    /** @param array<mixed> $data */
    #[DataProvider('badReasonFiles')]
    public function testBadReasonFileIsRefused(array $data, string $where): void
    {
        try {
            AdjustmentReasons::fromData($data);
            self::fail('a bad file was accepted');
        } catch (ReferenceDataException $e) {
            self::assertStringContainsString('data/table-9d-adjustments.json', $e->getMessage());
            self::assertStringContainsString($where, $e->getMessage());
        }
    }

    private static function reasons(): AdjustmentReasons
    {
        return (new ReferenceDataLoader())->load()->adjustmentReasons;
    }

    private static function paid(int $payerType, L $line, int $cents): Posting
    {
        return new Posting($payerType, $line, $cents, 0, '', '2026-06-01');
    }

    private static function adjusted(int $payerType, L $line, int $cents, string $reason): Posting
    {
        return new Posting($payerType, $line, 0, $cents, $reason, '2026-06-01');
    }
}
