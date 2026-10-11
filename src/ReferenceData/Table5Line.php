<?php

/**
 * One line of UDS Table 5, from data/table-5.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class Table5Line
{
    /**
     * @param ?string      $category a personnel line's service category; null on a total line
     * @param bool         $visits   a personnel line whose personnel are providers, so it reports visits
     * @param list<string> $sum      the lines a total adds up; empty on a personnel line
     * @param ?string      $patients the category whose unduplicated patients go in Column C,
     *                               Table5::PATIENTS_SUM for the grand total, or null
     */
    public function __construct(
        public string $line,
        public string $label,
        public ?string $category,
        public bool $visits,
        public array $sum,
        public ?string $patients,
    ) {
    }

    public function isPersonnel(): bool
    {
        return $this->category !== null;
    }
}
