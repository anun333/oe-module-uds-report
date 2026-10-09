<?php

/**
 * HHS poverty guideline region. The UDS uses the guidelines for where the
 * health center is located; Alaska and Hawaii have their own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Enum;

enum PovertyRegion: string
{
    /** The 48 contiguous states and DC. */
    case Contiguous = 'contiguous';
    case Alaska = 'alaska';
    case Hawaii = 'hawaii';
}
