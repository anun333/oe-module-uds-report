<?php

/**
 * UDS Report module bootstrap, loaded on every request while the module is enabled.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Bootstrap;

// OEGlobalsBag came with OpenEMR 7.0.4 and 8.0. OpenEMR loads this file on every request, the login
// page included, so on an older OpenEMR the module stays switched off rather than stop every page.
if (!class_exists(OEGlobalsBag::class)) {
    return;
}

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\UdsReport\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
