<?php

/**
 * Fetches the VSAC value sets Table 6A uses, with the site's own UMLS API key,
 * from the command line. The key is read from the environment, so it stays
 * out of the shell history and the process list:
 *
 *   UMLS_API_KEY=... HTTP_HOST=localhost php interface/modules/custom_modules/oe-module-uds-report/bin/uds-value-sets.php [--site=default]
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opts = getopt('', ['site:']);
$apiKey = getenv('UMLS_API_KEY');
if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDERR, "usage: UMLS_API_KEY=... php uds-value-sets.php [--site=default]\n");
    exit(2);
}
$ignoreAuth = true;
$sessionAllowWrite = true;
$_GET['site'] = is_string($opts['site'] ?? null) ? $opts['site'] : 'default';
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Repository\ValueSetRepository;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetException;
use OpenEMR\Modules\UdsReport\ValueSet\VsacClient;

$oids = (new ReferenceDataLoader())->load()->table6a->valueSetOids();
$client = VsacClient::overHttps();
$repo = new ValueSetRepository();
$fetched = 0;
foreach ($oids as $oid) {
    try {
        $set = $client->expand($oid, $apiKey);
        $repo->save($set, 0);
        $fetched++;
        echo "$oid  {$set->title}: version {$set->version}, codes: {$set->count()}"
            . ($set->otherSystems > 0 ? " ({$set->otherSystems} in other code systems left out)" : '') . "\n";
    } catch (ValueSetException $e) {
        fwrite(STDERR, "$oid  not fetched: {$e->getMessage()}\n");
        if (str_contains($e->getMessage(), 'API key') || str_contains($e->getMessage(), 'Could not reach')) {
            break;
        }
    }
}
echo "fetched $fetched of " . count($oids) . "\n";
exit($fetched === count($oids) ? 0 : 1);
