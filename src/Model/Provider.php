<?php

/**
 * An OpenEMR user who is the provider on countable visits.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

final readonly class Provider
{
    /** @param string $taxonomy the user's NUCC taxonomy code, as entered */
    public function __construct(
        public int $id,
        public string $name,
        public string $taxonomy,
    ) {
    }
}
