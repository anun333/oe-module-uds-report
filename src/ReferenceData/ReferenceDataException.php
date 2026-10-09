<?php

/**
 * A reference data file is missing, unreadable or not in the expected shape.
 *
 * Carries where the problem is (file and entry) and what is wrong with it, so
 * that an administrator can be shown exactly what to correct.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final class ReferenceDataException extends \RuntimeException
{
    /**
     * @param string $location the file and entry, e.g. "data/poverty-guidelines.json guidelines[2].firstPerson"
     * @param string $reason   what is wrong, e.g. "must be a whole number from 1 to 1000000"
     */
    public function __construct(
        public readonly string $location,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($location . ': ' . $reason, 0, $previous);
    }
}
