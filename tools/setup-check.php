<?php

/**
 * Checks a site's layouts and lists against what the UDS Report module reads, from a file alone:
 * the SQL file OpenEMR's Administration > Backup page exports (Export Configuration, OpenEMR 8.2
 * and older), or a Layout Export JSON or zip file. The file holds no patient data, and nothing in
 * it is run. Prints a Markdown report: what maps, what doesn't, and what the file can't show.
 * See docs/rehearsal-copy.md.
 *
 *     composer install
 *     php tools/setup-check.php openemr_config.sql > setup-check.md
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Tools\SetupCheck\ConfigFile;
use OpenEMR\Modules\UdsReport\Tools\SetupCheck\SetupCheck;

if (PHP_SAPI !== 'cli') {
    // Composer installs the package under the web root; the tools are for the command line only.
    http_response_code(404);
    exit;
}
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "setup-check: run composer install in this repository first\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/SetupCheck/ConfigFile.php';
require_once __DIR__ . '/SetupCheck/SetupCheck.php';

$path = $argv[1] ?? '';
if ($path === '' || $path === '--help' || $path === '-h') {
    fwrite(STDERR, "usage: php tools/setup-check.php FILE   (a Backup page SQL file, or a Layout Export .json or .zip)\n");
    exit(2);
}
try {
    $file = ConfigFile::read($path);
    $races = RaceEthnicity::fromData((new ReferenceDataLoader())->read(RaceEthnicity::FILE));
    echo (new SetupCheck($file, $races))->markdown(basename($path));
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'setup-check: ' . $e->getMessage() . "\n");
    exit(1);
}
