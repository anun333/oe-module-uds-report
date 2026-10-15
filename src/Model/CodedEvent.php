<?php

/**
 * A code recorded somewhere other than the fee sheet: a prescription, a
 * medication given from inventory, a screening, an Observation or a lab
 * result. Encounter 0 means it isn't tied to one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Model;

use DateTimeImmutable;

final readonly class CodedEvent
{
    /** @param string $code normalized (Table6aCode::normalize) */
    public function __construct(
        public int $pid,
        public int $encounter,
        public DateTimeImmutable $date,
        public string $system,
        public string $code,
    ) {
    }
}
