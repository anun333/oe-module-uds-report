<?php

/**
 * One race group of UDS Table 3B (Asian, Pacific Islander, Black, American
 * Indian/Alaska Native, White): the CDC codes in it and its own line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class RaceGroup
{
    /**
     * @param non-empty-list<CodeRange> $ranges
     * @param string                    $line   the group's line, used for an unlisted subgroup or several subgroups
     */
    public function __construct(
        public string $name,
        public array $ranges,
        public string $line,
    ) {
    }

    public function contains(?int $stem): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->contains($stem)) {
                return true;
            }
        }
        return false;
    }
}
