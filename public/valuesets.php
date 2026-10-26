<?php

/**
 * UDS value sets: fetches the VSAC value sets that define some Table 6A lines,
 * with the site's own UMLS API key. The module doesn't ship their codes: the
 * UMLS licence doesn't let it pass on CPT or SNOMED CT codes, so each site
 * fetches them under its own licence. The key is used for the fetch and not
 * saved.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Page;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\Repository\ValueSetRepository;
use OpenEMR\Modules\UdsReport\ValueSet\LicenceNotices;
use OpenEMR\Modules\UdsReport\ValueSet\ValueSetException;
use OpenEMR\Modules\UdsReport\ValueSet\VsacClient;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('admin', 'super')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for admin/super: UDS value sets', xl('UDS Value Sets'));
}

$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop(true);
$request = Request::get();
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionUserId = $session->get('authUserID');
$userId = is_int($sessionUserId) ? $sessionUserId : (is_string($sessionUserId) && ctype_digit($sessionUserId) ? (int)$sessionUserId : 0);
$repo = new ValueSetRepository();
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

$oids = $reference->table6a->valueSetOids();
$linesByOid = [];
foreach ($reference->table6a->lines as $line) {
    foreach ($line->valueSets as $oid) {
        $linesByOid[$oid][] = $line->line;
    }
}

/** @var list<array{oid: string, ok: bool, message: string}> $results */
$results = [];
if ($request->getMethod() === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $apiKey = $request->request->getString('umls_api_key');
    $client = VsacClient::overHttps();
    foreach ($oids as $oid) {
        try {
            $set = $client->expand($oid, $apiKey);
            $repo->save($set, $userId);
            $results[] = ['oid' => $oid, 'ok' => true, 'message' => sprintf(xl('version %1$s, codes: %2$s'), $set->version, number_format($set->count()))];
        } catch (ValueSetException $e) {
            $results[] = ['oid' => $oid, 'ok' => false, 'message' => $e->getMessage()];
            // A refused key or an unreachable server fails every value set the same way.
            if (str_contains($e->getMessage(), 'API key') || str_contains($e->getMessage(), 'Could not reach')) {
                break;
            }
        }
    }
    unset($apiKey);
}

$fetched = $repo->load();
$systemsUsed = $fetched->systems();
$csrf = CsrfUtils::collectCsrfToken(session: $session);
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('UDS Value Sets'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenEMR\Modules\UdsReport\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3" style="max-width: 70rem">
    <a href="<?php echo attr($moduleUrl . '/public/index.php'); ?>">&larr; <?php echo xlt('UDS Report'); ?></a>
    <h1 class="h4 mt-2"><?php echo xlt('UDS Value Sets'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p><?php echo xlt('The UDS Manual defines some Table 6A lines by value sets from the Value Set Authority Center (VSAC) of the National Library of Medicine, and adds value sets to others. The module does not include their codes: the UMLS licence does not allow passing on CPT or SNOMED CT codes, so each site fetches them under its own licence. Until all the value sets of a line are fetched, it is left blank or counted from the codes the manual lists.'); ?></p>
    <p><?php echo xlt('You need a UMLS account (free; apply at uts.nlm.nih.gov, approval takes a few business days). Your API key is on your UTS profile page. It is used for this fetch only and not saved. Fetch again each year, when HRSA publishes a new manual.'); ?></p>

    <?php if ($results !== []) { ?>
    <div class="alert <?php echo in_array(false, array_column($results, 'ok'), true) ? 'alert-warning' : 'alert-success'; ?> py-2">
        <ul class="mb-0">
        <?php foreach ($results as $r) { ?>
            <li><?php echo text($r['oid']); ?>: <?php echo text($r['ok'] ? $r['message'] : xl('not fetched') . ' (' . $r['message'] . ')'); ?></li>
        <?php } ?>
        </ul>
    </div>
    <?php } ?>

    <form method="post" class="form-inline mb-4" autocomplete="off">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <label class="mr-2" for="umls_api_key"><?php echo xlt('UMLS API key'); ?></label>
        <input class="form-control mr-2" type="password" id="umls_api_key" name="umls_api_key" required autocomplete="off" style="min-width: 22rem">
        <button class="btn btn-primary" type="submit"><?php echo xlt('Fetch from VSAC'); ?></button>
    </form>

    <table class="table table-sm" style="max-width: 60rem">
        <thead class="thead-light"><tr>
            <th><?php echo xlt('Value set'); ?></th><th><?php echo xlt('Table 6A lines'); ?></th><th><?php echo xlt('Version'); ?></th>
            <th class="text-right"><?php echo xlt('Codes'); ?></th><th><?php echo xlt('Fetched'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($oids as $oid) {
            $set = $fetched->sets[$oid] ?? null; ?>
            <tr>
                <td><?php echo text($set === null ? $oid : $set['title']); ?><br><small class="text-muted"><?php echo text($oid); ?></small></td>
                <td><?php echo text(implode(', ', $linesByOid[$oid] ?? [])); ?></td>
                <td><?php echo text($set['version'] ?? ''); ?></td>
                <td class="text-right"><?php echo $set === null ? '' : text(number_format(array_sum(array_map(count(...), $set['codes'])))); ?></td>
                <td><?php echo $set === null ? xlt('not fetched') : text(substr($set['fetchedAt'], 0, 10)); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if ($systemsUsed !== []) { ?>
    <details class="small text-muted">
        <summary><?php echo xlt('Licence notices for the fetched codes'); ?></summary>
        <p class="mt-2"><?php echo text(LicenceNotices::UMLS); ?></p>
        <p><?php echo xlt('Code systems used, each in part'); ?>: <?php echo text(implode(', ', array_map(static fn(string $s): string => LicenceNotices::NAMES[$s] ?? $s, $systemsUsed))); ?>.</p>
        <?php foreach ($systemsUsed as $system) {
            if (isset(LicenceNotices::BY_SYSTEM[$system])) { ?>
            <p style="white-space: pre-line"><?php echo text(LicenceNotices::BY_SYSTEM[$system]); ?></p>
            <?php }
        } ?>
    </details>
    <?php } ?>
</div>
</body>
</html>
