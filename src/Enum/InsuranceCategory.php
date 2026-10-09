<?php

/**
 * UDS primary medical insurance category (2026 UDS Manual, Table 4 Lines 7-11).
 *
 * Backed because the value is stored (uds_insurance_map) and appears in the
 * module's reference data files.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum InsuranceCategory: string
{
    case None = 'none';
    case Medicaid = 'medicaid';
    case ChipMedicaid = 'chip_medicaid';
    case Medicare = 'medicare';
    case OtherPublic = 'other_public';
    case OtherPublicChip = 'other_public_chip';
    case Private = 'private';
    /** Not a UDS line: an insurer nobody has categorized yet. The clinic fixes it in Settings. */
    case Unmapped = 'unmapped';

    /**
     * The categories a clinic or a reference file can assign (everything except Unmapped).
     *
     * @return non-empty-list<self>
     */
    public static function assignable(): array
    {
        return [self::None, self::Medicaid, self::ChipMedicaid, self::Medicare, self::OtherPublic, self::OtherPublicChip, self::Private];
    }

    /**
     * Table 4 line, or null for an unmapped insurer, which no line can hold.
     *
     * @return '7'|'8a'|'8b'|'9'|'10a'|'10b'|'11'|null
     */
    public function table4Line(): ?string
    {
        return match ($this) {
            self::None => '7',
            self::Medicaid => '8a',
            self::ChipMedicaid => '8b',
            self::Medicare => '9',
            self::OtherPublic => '10a',
            self::OtherPublicChip => '10b',
            self::Private => '11',
            self::Unmapped => null,
        };
    }

    /** Column of the Patients by ZIP Code table, which merges Medicaid, CHIP and other public; null when unmapped. */
    public function zipColumn(): ?string
    {
        return match ($this) {
            self::None => 'none',
            self::Medicaid, self::ChipMedicaid, self::OtherPublic, self::OtherPublicChip => 'public',
            self::Medicare => 'medicare',
            self::Private => 'private',
            self::Unmapped => null,
        };
    }

    /** Medicaid or CHIP Medicaid, for detecting dual eligibility (Line 9a). */
    public function isMedicaid(): bool
    {
        return $this === self::Medicaid || $this === self::ChipMedicaid;
    }
}
