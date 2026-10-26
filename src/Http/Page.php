<?php

/**
 * The language and writing direction each page declares on its html element: the signed-in user's
 * OpenEMR language, through openhealthcare-common 1.4.0. A site that copies modules in by hand
 * can load an older copy of the library first, one without pageLang(); the pages then say
 * English, as they did before, rather than stop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Http;

use OpenHealthcare\Common\OpenEmr;

final class Page
{
    /** A BCP 47 tag such as en, es or zh-Hant. */
    public static function lang(): string
    {
        return method_exists(OpenEmr::class, 'pageLang') ? OpenEmr::pageLang() : 'en';
    }

    /** ltr or rtl. */
    public static function dir(): string
    {
        return method_exists(OpenEmr::class, 'pageDir') ? OpenEmr::pageDir() : 'ltr';
    }
}
