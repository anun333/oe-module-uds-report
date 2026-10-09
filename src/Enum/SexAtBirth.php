<?php

/**
 * Sex as listed on the birth certificate, for UDS Table 3A (which has no
 * unknown column: anyone else can't be placed and is listed as a record to fix).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum SexAtBirth: string
{
    case Male = 'male';
    case Female = 'female';

    /** OpenEMR's patient_data.sex value, or null when it is anything else. */
    public static function fromPatientData(string $sex): ?self
    {
        return match ($sex) {
            'Male' => self::Male,
            'Female' => self::Female,
            default => null,
        };
    }
}
