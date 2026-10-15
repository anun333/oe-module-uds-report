<?php

/**
 * A record problem that keeps a patient off a UDS table, or pushes them into an
 * "unknown" line. Each one is something staff can fix in the chart.
 *
 * Backed because the value travels in the record list's URL.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum DataGap: string
{
    case NoBirthSex = 'no_birth_sex';
    case NoDob = 'no_dob';
    case InsurerUnmapped = 'insurer_unmapped';
    case RaceUnreported = 'race_unreported';
    case IncomeUnverified = 'income_unverified';
    case IncomeNotRecorded = 'income_not_recorded';
    case IncomeNotUnderstood = 'income_not_understood';
    case FamilySizeNotRecorded = 'family_size_not_recorded';
    case FamilySizeNotUnderstood = 'family_size_not_understood';
    case ReviewDateMissing = 'review_date_missing';
    case ReviewDateNotSaved = 'review_date_not_saved';
    case ReviewDateOutsideWindow = 'review_date_outside_window';
    case NoInsuranceOnFile = 'no_insurance_on_file';
    case NoZip = 'no_zip';
    case HousingNotAsked = 'housing_not_asked';
    case AgricultureNotAsked = 'agriculture_not_asked';
    case VeteranNotAsked = 'veteran_not_asked';
    case MedicationNotCounted = 'medication_not_counted';

    /**
     * Blocking problems keep the patient off a table entirely, so the table
     * totals can't agree. The others count the patient under an "unknown" line
     * or an assumption.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::NoBirthSex, self::NoDob, self::InsurerUnmapped => true,
            self::RaceUnreported, self::IncomeUnverified, self::IncomeNotRecorded, self::IncomeNotUnderstood,
            self::FamilySizeNotRecorded, self::FamilySizeNotUnderstood, self::ReviewDateMissing,
            self::ReviewDateNotSaved, self::ReviewDateOutsideWindow, self::NoInsuranceOnFile, self::NoZip,
            self::HousingNotAsked, self::AgricultureNotAsked, self::VeteranNotAsked, self::MedicationNotCounted => false,
        };
    }
}
