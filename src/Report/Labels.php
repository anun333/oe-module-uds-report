<?php

/**
 * Display labels (translated) for the UDS pages. Wording follows the 2026 UDS Manual.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Enum\DataGap;
use OpenEMR\Modules\UdsReport\Enum\GrantReport;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;

final class Labels
{
    public static function gap(DataGap $gap): string
    {
        return match ($gap) {
            DataGap::NoBirthSex => xl('Birth sex not recorded as male or female (Table 3A has no unknown column)'),
            DataGap::NoDob => xl('No date of birth'),
            DataGap::InsurerUnmapped => xl('Insurance company not mapped to a UDS category (fix in Settings)'),
            DataGap::RaceUnreported => xl('Race not reported (counted on Line 7)'),
            DataGap::IncomeUnverified => xl('Income or family size not recorded within 12 months before the last visit (counted as Unknown)'),
            DataGap::IncomeNotRecorded => xl('Monthly income not recorded (counted as Unknown)'),
            DataGap::IncomeNotUnderstood => xl('Monthly income is not a single amount: enter it like 2000 or $2,000.50, not a range or words (counted as Unknown)'),
            DataGap::FamilySizeNotRecorded => xl('Family size not recorded (counted as Unknown)'),
            DataGap::FamilySizeNotUnderstood => xl('Family size is not a whole number of 1 or more (counted as Unknown)'),
            DataGap::ReviewDateMissing => xl('Financial Review Date not recorded (counted as Unknown)'),
            DataGap::ReviewDateNotSaved => xl('Financial Review Date was not saved: OpenEMR keeps it only when typed as YYYY-MM-DD, for example 2026-09-15 (counted as Unknown)'),
            DataGap::ReviewDateOutsideWindow => xl('Financial review is more than 12 months before the last visit, or after it (counted as Unknown)'),
            DataGap::NoInsuranceOnFile => xl('No insurance on file for the last visit (counted as uninsured)'),
            DataGap::NoZip => xl('No residence ZIP code (counted as Unknown Residence)'),
            DataGap::HousingNotAsked => xl('Housing status (UDS) not recorded'),
            DataGap::AgricultureNotAsked => xl('Agricultural worker status (UDS) not recorded'),
            DataGap::VeteranNotAsked => xl('Military service (UDS) not recorded'),
        };
    }

    /** The report's name: the Universal Report, or a population's Grant Report. */
    public static function report(?GrantReport $grant): string
    {
        return match ($grant) {
            null => xl('Universal Report'),
            GrantReport::AgriculturalWorkers => xl('Migratory and Seasonal Agricultural Workers Grant Report (330(g))'),
            GrantReport::Homeless => xl('Homeless Population Grant Report (330(h))'),
            GrantReport::PublicHousing => xl('Residents of Public Housing Grant Report (330(i))'),
        };
    }

    public static function insuranceCategory(InsuranceCategory $category): string
    {
        return match ($category) {
            InsuranceCategory::None => xl('None/Uninsured'),
            InsuranceCategory::Medicaid => xl('Medicaid (Title XIX)'),
            InsuranceCategory::ChipMedicaid => xl('CHIP Medicaid'),
            InsuranceCategory::Medicare => xl('Medicare'),
            InsuranceCategory::OtherPublic => xl('Other Public Insurance (Non-CHIP)'),
            InsuranceCategory::OtherPublicChip => xl('Other Public Insurance CHIP'),
            InsuranceCategory::Private => xl('Private Insurance'),
            InsuranceCategory::Unmapped => xl('Not mapped'),
        };
    }

    public static function povertyRegion(PovertyRegion $region): string
    {
        return match ($region) {
            PovertyRegion::Contiguous => xl('48 contiguous states, DC and territories'),
            PovertyRegion::Alaska => xl('Alaska'),
            PovertyRegion::Hawaii => xl('Hawaii'),
        };
    }

    /** @return array<int|string, string> Table 3B line => label, in the manual's order (numeric lines are int keys) */
    public static function raceLines(): array
    {
        return [
            '1a' => xl('Asian Indian'), '1b' => xl('Chinese'), '1c' => xl('Filipino'), '1d' => xl('Japanese'),
            '1e' => xl('Korean'), '1f' => xl('Vietnamese'), '1g' => xl('Other Asian'), '1' => xl('Total Asian'),
            '2a' => xl('Native Hawaiian'), '2b' => xl('Other Pacific Islander'), '2c' => xl('Guamanian or Chamorro'),
            '2d' => xl('Samoan'), '2' => xl('Total Native Hawaiian/Other Pacific Islander'),
            '3' => xl('Black/African American'), '4' => xl('American Indian/Alaska Native'), '5' => xl('White'),
            '6' => xl('More than one race'), '7' => xl('Unreported/Chose not to disclose race'), '8' => xl('Total Patients'),
        ];
    }

    /** @return array<string, string> Table 3B column => label, in the manual's order */
    public static function ethnicityColumns(): array
    {
        return [
            'A1' => xl('Mexican, Mexican American, Chicano/a'), 'A2' => xl('Puerto Rican'), 'A3' => xl('Cuban'),
            'A4' => xl('Another Hispanic, Latino/a, or Spanish origin'), 'A5' => xl('Hispanic, Latino/a, or Spanish origin combined'),
            'A' => xl('Total Hispanic, Latino/a, or Spanish origin'), 'B' => xl('Not Hispanic, Latino/a, or Spanish origin'),
            'C' => xl('Unreported/Chose not to disclose ethnicity'), 'D' => xl('Total'),
        ];
    }

    /** @return array<int, string> Table 4 line => label */
    public static function table4Income(): array
    {
        return [
            '1' => xl('100% and below'), '2' => xl('101–150%'), '3' => xl('151–200%'),
            '4' => xl('Over 200%'), '5' => xl('Unknown'), '6' => xl('TOTAL (Sum of Lines 1–5)'),
        ];
    }

    /** @return array<int|string, string> Table 4 line => label (numeric lines are int keys) */
    public static function table4Insurance(): array
    {
        return [
            '7' => xl('None/Uninsured'), '8a' => xl('Medicaid (Title XIX)'), '8b' => xl('CHIP Medicaid'),
            '8' => xl('Total Medicaid (Line 8a + 8b)'), '9a' => xl('Dually Eligible (Medicare and Medicaid)'),
            '9' => xl('Medicare (inclusive of dually eligible and other Title XVIII beneficiaries)'),
            '10a' => xl('Other Public Insurance (Non-CHIP)'), '10b' => xl('Other Public Insurance CHIP'),
            '10' => xl('Total Public Insurance (Line 10a + 10b)'), '11' => xl('Private Insurance'),
            '12' => xl('TOTAL (Sum of Lines 7 + 8 + 9 + 10 + 11)'),
        ];
    }

    /** @return array<int|string, string> Table 4 line => label (numeric lines are int keys) */
    public static function table4Special(): array
    {
        return [
            '14' => xl('Migratory Agricultural Workers and Their Family Members (330g recipients only)'),
            '15' => xl('Seasonal Agricultural Workers and Their Family Members (330g recipients only)'),
            '16' => xl('Total Migratory and Seasonal Agricultural Workers and Their Family Members'),
            '17' => xl('Homeless Shelter (330h recipients only)'), '18' => xl('Transitional (330h recipients only)'),
            '19' => xl('Doubling Up (330h recipients only)'), '20' => xl('Street (330h recipients only)'),
            '21a' => xl('Permanent Supportive Housing (330h recipients only)'), '21' => xl('Other (330h recipients only)'),
            '22' => xl('Unknown (330h recipients only)'), '23' => xl('Total Homeless Population'),
            '24' => xl('Total School-Based Service Site Patients'), '25' => xl('Total Veterans'),
            '26' => xl('Total Residents of Public Housing (patients seen at sites in or next to public housing)'),
        ];
    }
}
