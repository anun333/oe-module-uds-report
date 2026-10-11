<?php

/**
 * The payer lines of Table 9D (2026 UDS Manual, pages 160-162), plus two rows
 * that hold money no line can take yet, so the total (Line 14) stays whole.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum Table9DLine: string
{
    /** Medicaid, including CHIP paid through Medicaid. */
    case Medicaid = '3';
    case Medicare = '6';
    /** Other public programs, including CHIP paid through private insurers. */
    case OtherPublic = '9';
    case Private = '12';
    /** Whatever the patient owes or paid, including insured patients' shares. */
    case SelfPay = '13';
    /**
     * An insurer the clinic mapped to None/Uninsured for Table 4. A program such
     * as Title X pays for services but isn't insurance, so its patients are
     * uninsured on Table 4 while its money is Other Public (Line 9) here.
     */
    case Uninsured = 'uninsured';
    /** An insurer nobody has categorized yet; fixed in Settings. */
    case Unmapped = 'unmapped';

    public static function forCategory(InsuranceCategory $category): self
    {
        return match ($category) {
            InsuranceCategory::Medicaid, InsuranceCategory::ChipMedicaid => self::Medicaid,
            InsuranceCategory::Medicare => self::Medicare,
            InsuranceCategory::OtherPublic, InsuranceCategory::OtherPublicChip => self::OtherPublic,
            InsuranceCategory::Private => self::Private,
            InsuranceCategory::None => self::Uninsured,
            InsuranceCategory::Unmapped => self::Unmapped,
        };
    }

    /**
     * The line a posting belongs to: the patient's for a patient posting, else
     * the insurer that paid it (the payment's payer), else the insurer the
     * patient had at that level on the date of service.
     *
     * @param array<int, InsuranceCategory> $insurers insurance level (1-3) => category on the date of service
     */
    public static function forPosting(int $payerType, ?InsuranceCategory $payer, array $insurers): self
    {
        if ($payerType === 0) {
            return self::SelfPay;
        }
        $category = $payer ?? $insurers[$payerType] ?? null;
        return $category === null ? self::Unmapped : self::forCategory($category);
    }

    /** False for the two rows that aren't Table 9D lines. */
    public function isLine(): bool
    {
        return $this !== self::Uninsured && $this !== self::Unmapped;
    }

    public function label(): string
    {
        return match ($this) {
            self::Medicaid => xl('Total Medicaid'),
            self::Medicare => xl('Total Medicare'),
            self::OtherPublic => xl('Total Other Public'),
            self::Private => xl('Total Private'),
            self::SelfPay => xl('Total Self-Pay'),
            self::Uninsured => xl('Insurers mapped to None/Uninsured: Line 9 if a program paid for the services, else Line 13'),
            self::Unmapped => xl('Insurers not mapped to a UDS category (fix in Settings)'),
        };
    }
}
