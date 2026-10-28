<?php

/**
 * UDS Report settings: what counts as a visit, which sites are which, the
 * poverty-guideline region, and the UDS category of each insurance company.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../floor-guard.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Guide;
use OpenEMR\Modules\UdsReport\Http\Page;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5Line;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\PublishedUds;
use OpenEMR\Modules\UdsReport\Report\ServiceArea;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use OpenEMR\Modules\UdsReport\Repository\Row;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('admin', 'super')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for admin/super: UDS settings', xl('UDS Settings'));
}
$reference = (new ReferenceDataGuard(new ReferenceDataLoader(), ServiceContainer::getLogger()))->loadOrStop(true);

$request = Request::get();
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionUserId = $session->get('authUserID');
$userId = is_int($sessionUserId) ? $sessionUserId : (is_string($sessionUserId) && ctype_digit($sessionUserId) ? (int)$sessionUserId : 0);
$repo = new SettingsRepository();
$patientRepository = new UdsPatientRepository($reference->insuranceCategories);
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

$categories = QueryUtils::fetchRecords("SELECT pc_catid, pc_catname FROM openemr_postcalendar_categories WHERE pc_cattype = 0 ORDER BY pc_catname");
$facilities = QueryUtils::fetchRecords("SELECT id, name, inactive FROM facility ORDER BY name");
$companies = QueryUtils::fetchRecords("SELECT id, name, cqm_sop, ins_type_code FROM insurance_companies ORDER BY name");
$allCategoryIds = array_map(static fn(array $c): int => Row::int($c, 'pc_catid'), $categories);
$allFacilityIds = array_map(static fn(array $f): int => Row::int($f, 'id'), $facilities);
$allCompanyIds = array_map(static fn(array $c): int => Row::int($c, 'id'), $companies);
// Table 5: the lines whose personnel are providers, and the people who see patients.
$visitLines = array_values(array_filter($reference->table5->lines, static fn(Table5Line $l): bool => $l->visits));
$visitLineIds = array_map(static fn(Table5Line $l): string => $l->line, $visitLines);
$savedProviderIds = array_keys($repo->load()->providerLines);
$people = QueryUtils::fetchRecords(
    'SELECT `id`, `fname`, `lname`, `taxonomy`, `active` FROM `users`
      WHERE (`authorized` = 1 AND `active` = 1)
         OR `id` IN (SELECT DISTINCT `provider_id` FROM `form_encounter` WHERE `provider_id` > 0)'
    . ($savedProviderIds === [] ? '' : ' OR `id` IN (' . implode(',', array_fill(0, count($savedProviderIds), '?')) . ')')
    . ' ORDER BY `lname`, `fname`',
    $savedProviderIds,
);
$allPeopleIds = array_map(static fn(array $u): int => Row::int($u, 'id'), $people);
$lineOption = static fn(Table5Line $l): string => sprintf(xl('Line %1$s: %2$s'), $l->line, $l->label);

/**
 * Ids ticked in a posted checkbox list, limited to the ids that exist.
 *
 * @param list<int> $known
 * @return list<int>
 */
$postedIds = static function (string $field, array $known) use ($request): array {
    $ids = [];
    foreach ($request->request->all($field) as $value) {
        if (is_string($value) && ctype_digit($value) && in_array((int)$value, $known, true)) {
            $ids[] = (int)$value;
        }
    }
    return array_values(array_unique($ids));
};

$saved = false;
$rejectedZips = [];
$rejectedGrant = null;
if ($request->getMethod() === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $countable = $postedIds('countable', $allCategoryIds);
    $inScope = $postedIds('in_scope', $allFacilityIds);
    // Every box ticked is the default; store null so new categories and sites are included automatically.
    $defaultCountable = $patientRepository->defaultCountableCategories();
    sort($countable);
    sort($defaultCountable);
    $repo->saveList(SettingsRepository::COUNTABLE_CATEGORIES, $countable === $defaultCountable ? null : $countable, $userId);
    // The default (Comlink's telehealth categories) is stored as null, so it follows those categories if they're added later.
    $virtual = $postedIds('virtual', $allCategoryIds);
    $defaultVirtual = $patientRepository->categoriesByConstant($reference->table5->virtualCategories);
    sort($virtual);
    sort($defaultVirtual);
    $repo->saveList(SettingsRepository::VIRTUAL_CATEGORIES, $virtual === $defaultVirtual ? null : $virtual, $userId);
    $repo->saveList(SettingsRepository::IN_SCOPE_FACILITIES, count($inScope) === count($allFacilityIds) ? null : $inScope, $userId);
    $repo->saveList(SettingsRepository::SCHOOL_BASED_FACILITIES, $postedIds('school_based', $allFacilityIds), $userId);
    $repo->saveList(SettingsRepository::PUBLIC_HOUSING_FACILITIES, $postedIds('public_housing', $allFacilityIds), $userId);
    $repo->savePovertyRegion(PovertyRegion::tryFrom($request->request->getString('poverty_region')) ?? PovertyRegion::Contiguous, $userId);
    $zips = ServiceArea::parse($request->request->getString('service_area_zips'));
    $repo->saveServiceAreaZips($zips['zips'], $userId);
    $rejectedZips = $zips['rejected'];
    // A number that can't be a grant or look-alike number is reported and the saved one kept.
    $typedGrant = trim($request->request->getString('hrsa_grant_number'));
    $grantNumber = $typedGrant === '' ? null : PublishedUds::normalizeGrant($typedGrant);
    if ($typedGrant !== '' && $grantNumber === null) {
        $rejectedGrant = $typedGrant;
    } else {
        $repo->saveGrantNumber($grantNumber, $userId);
    }
    $providerLines = [];
    $postedProviderLines = $request->request->all('provider_line');
    foreach ($allPeopleIds as $personId) {
        $line = $postedProviderLines[$personId] ?? '';
        if (is_string($line) && ($line === UdsSettings::NOT_A_PROVIDER || in_array($line, $visitLineIds, true))) {
            $providerLines[$personId] = $line;
        }
    }
    $repo->saveLineMap(SettingsRepository::PROVIDER_LINES, $providerLines, $userId);
    $categoryLines = [];
    $postedCategoryLines = $request->request->all('category_line');
    foreach ($allCategoryIds as $categoryId) {
        $line = $postedCategoryLines[$categoryId] ?? '';
        if (is_string($line) && in_array($line, $visitLineIds, true)) {
            $categoryLines[$categoryId] = $line;
        }
    }
    $repo->saveLineMap(SettingsRepository::CATEGORY_LINES, $categoryLines, $userId);
    $choices = $request->request->all('insurance');
    foreach ($allCompanyIds as $companyId) {
        $choice = $choices[$companyId] ?? '';
        $repo->saveInsuranceOverride($companyId, is_string($choice) ? InsuranceCategory::tryFrom($choice) : null, $userId);
    }
    $saved = true;
}

$settings = $repo->load();
$publishedFiles = PublishedUds::files();
$publishedYear = array_key_first($publishedFiles);
$published = ($settings->hrsaGrantNumber !== null && $publishedYear !== null)
    ? PublishedUds::find($publishedFiles[$publishedYear], $settings->hrsaGrantNumber)
    : null;
$countable = $settings->countableCategoryIds ?? $patientRepository->defaultCountableCategories();
$virtualIds = $settings->virtualCategoryIds ?? $patientRepository->categoriesByConstant($reference->table5->virtualCategories);
$csrf = CsrfUtils::collectCsrfToken(session: $session);
$checked = static fn(bool $on): string => $on ? 'checked' : '';
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('UDS Settings'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenEMR\Modules\UdsReport\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3" style="max-width: 70rem">
    <a href="<?php echo attr($moduleUrl . '/public/index.php'); ?>">&larr; <?php echo xlt('UDS Report'); ?></a>
    <h1 class="h4 mt-2"><?php echo xlt('UDS Settings'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p class="small"><a href="<?php echo attr($moduleUrl . '/public/valuesets.php'); ?>"><?php echo xlt('Table 6A value sets (fetched with your UMLS API key)'); ?></a></p>
    <?php if ($saved) {
        ?><div class="alert alert-success py-2"><?php echo xlt('Saved.'); ?></div><?php } ?>
    <?php if ($rejectedZips !== []) {
        ?><div class="alert alert-warning py-2" data-rejected-zips><?php echo text(xl('Not five-digit ZIP codes, so left out of the service area') . ': ' . implode(', ', $rejectedZips)); ?></div><?php } ?>
    <?php if ($rejectedGrant !== null) {
        ?><div class="alert alert-warning py-2" data-rejected-grant><?php echo text(xl('Not a grant or look-alike number, so not saved') . ': ' . $rejectedGrant); ?></div><?php } ?>

    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">

        <h2 class="h6 mt-4"><?php echo xlt('Visit categories that are countable visits'); ?></h2>
        <p class="small text-muted"><?php echo xlt('Untick categories used only for labs, injections, screenings, outreach or other services the UDS does not count as visits.'); ?></p>
        <?php foreach ($categories as $c) {
            $id = Row::int($c, 'pc_catid'); ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="countable[]" id="cat<?php echo attr((string)$id); ?>" value="<?php echo attr((string)$id); ?>" <?php echo $checked(in_array($id, $countable, true)); ?>>
                <label class="form-check-label" for="cat<?php echo attr((string)$id); ?>"><?php echo text(xl_appt_category(Row::text($c, 'pc_catname'))); ?></label>
            </div>
        <?php } ?>

        <h2 class="h6 mt-4"><?php echo xlt('Visit categories that are virtual visits (Table 5, Column B2)'); ?></h2>
        <p class="small text-muted"><?php echo xlt('A visit is also virtual when it has a telehealth code, modifier 95 or 93, place of service 02 or 10, or the Virtual encounter class. Tick the categories you use for telehealth visits that carry none of those. By default, the two telehealth categories of the Comlink telehealth module are ticked, since its visits carry the site place of service.'); ?></p>
        <?php foreach ($categories as $c) {
            $id = Row::int($c, 'pc_catid'); ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="virtual[]" id="virtual<?php echo attr((string)$id); ?>" value="<?php echo attr((string)$id); ?>" <?php echo $checked(in_array($id, $virtualIds, true)); ?>>
                <label class="form-check-label" for="virtual<?php echo attr((string)$id); ?>"><?php echo text(xl_appt_category(Row::text($c, 'pc_catname'))); ?></label>
            </div>
        <?php } ?>

        <h2 class="h6 mt-4"><?php echo xlt('Sites'); ?></h2>
        <table class="table table-sm" style="max-width: 50rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Facility'); ?></th><th><?php echo xlt('In scope of project'); ?></th>
                <th><?php echo xlt('School-based site'); ?></th><th><?php echo xlt('In or next to public housing'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($facilities as $f) {
                $id = Row::int($f, 'id');
                $facilityName = Row::text($f, 'name'); ?>
                <tr>
                    <td><?php echo text($facilityName); ?><?php echo Row::nullableInt($f, 'inactive') === 1 ? ' <span class="text-muted">(' . xlt('inactive') . ')</span>' : ''; ?></td>
                    <td><input type="checkbox" name="in_scope[]" value="<?php echo attr((string)$id); ?>" aria-label="<?php echo xla('In scope of project'); ?>: <?php echo attr($facilityName); ?>" <?php echo $checked($settings->inScopeFacilityIds === null || in_array($id, $settings->inScopeFacilityIds, true)); ?>></td>
                    <td><input type="checkbox" name="school_based[]" value="<?php echo attr((string)$id); ?>" aria-label="<?php echo xla('School-based site'); ?>: <?php echo attr($facilityName); ?>" <?php echo $checked(in_array($id, $settings->schoolBasedFacilityIds, true)); ?>></td>
                    <td><input type="checkbox" name="public_housing[]" value="<?php echo attr((string)$id); ?>" aria-label="<?php echo xla('In or next to public housing'); ?>: <?php echo attr($facilityName); ?>" <?php echo $checked(in_array($id, $settings->publicHousingFacilityIds, true)); ?>></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <h2 class="h6 mt-4"><label for="poverty_region"><?php echo xlt('Poverty guidelines'); ?></label></h2>
        <select class="form-control form-control-sm" id="poverty_region" name="poverty_region" style="max-width: 24rem">
            <?php foreach (PovertyRegion::cases() as $region) { ?>
                <option value="<?php echo attr($region->value); ?>" <?php echo $settings->povertyRegion === $region ? 'selected' : ''; ?>><?php echo text(Labels::povertyRegion($region)); ?></option>
            <?php } ?>
        </select>
        <p class="small text-muted"><?php echo xlt('Use the guidelines for where the health center is located.'); ?></p>

        <h2 class="h6 mt-4"><label for="service_area_zips"><?php echo xlt('Service area ZIP codes (Form 5B)'); ?></label></h2>
        <textarea class="form-control form-control-sm" id="service_area_zips" name="service_area_zips" rows="3" style="max-width: 40rem" aria-describedby="service-area-help"><?php echo text(implode(' ', $settings->serviceAreaZips)); ?></textarea>
        <p class="small text-muted" id="service-area-help"><?php echo xlt('The service area ZIP codes on your Form 5B, separated by spaces, commas or lines. The report compares them with the ZIP codes where 75% of the patients seen in the year live, which the service area has to include (HRSA Scope of Project Policy Manual, Chapter 2).'); ?></p>

        <h2 class="h6 mt-4"><label for="hrsa_grant_number"><?php echo xlt('HRSA grant or look-alike number'); ?></label></h2>
        <input type="text" class="form-control form-control-sm" id="hrsa_grant_number" name="hrsa_grant_number" style="max-width: 16rem" autocomplete="off" aria-describedby="grant-help" value="<?php echo attr($settings->hrsaGrantNumber ?? ''); ?>">
        <p class="small text-muted" id="grant-help"><?php echo xlt('For example H80CS00000, or LALCS00000 for a look-alike. HRSA Checks uses it to find your report as HRSA published it last year, to compare with this year and to run the checks that look at changes from last year.'); ?>
            <?php if ($settings->hrsaGrantNumber !== null && $publishedYear !== null) {
                echo $published === null
                    ? text(sprintf(xl('Not in the %1$s data HRSA published. A health center that started reporting after %1$s has no published report yet.'), (string)$publishedYear))
                    : text(sprintf(xl('Found in the %1$s data HRSA published: %2$s, %3$s.'), (string)$publishedYear, $published->displayName(), $published->state));
            } ?></p>

        <h2 class="h6 mt-4"><?php echo xlt('Insurance companies'); ?></h2>
        <p class="small text-muted"><?php echo xlt('The automatic category comes from the payer typology or claim type set on the insurance company. Override it where it is wrong, for example a Medicaid managed care plan run by a private insurer, or a CHIP plan.'); ?></p>
        <table class="table table-sm" style="max-width: 60rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Company'); ?></th><th><?php echo xlt('Automatic'); ?></th><th><?php echo xlt('UDS category used'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($companies as $company) {
                $id = Row::int($company, 'id');
                $automatic = $reference->insuranceCategories->forCompany(null, Row::nullableText($company, 'cqm_sop'), Row::nullableInt($company, 'ins_type_code'));
                $override = $settings->insuranceOverrides[$id] ?? null; ?>
                <tr class="<?php echo ($automatic === InsuranceCategory::Unmapped && $override === null) ? 'table-warning' : ''; ?>">
                    <td><?php echo text(Row::text($company, 'name')); ?></td>
                    <td><?php echo text(Labels::insuranceCategory($automatic)); ?></td>
                    <td>
                        <select class="form-control form-control-sm" name="insurance[<?php echo attr((string)$id); ?>]" aria-label="<?php echo xla('UDS category used'); ?>: <?php echo attr(Row::text($company, 'name')); ?>">
                            <option value=""><?php echo xlt('Use automatic'); ?></option>
                            <?php foreach (InsuranceCategory::assignable() as $category) { ?>
                                <option value="<?php echo attr($category->value); ?>" <?php echo $override === $category ? 'selected' : ''; ?>><?php echo text(Labels::insuranceCategory($category)); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <h2 class="h6 mt-4" id="table5"><?php echo xlt('Table 5 lines'); ?></h2>
        <p class="small text-muted"><?php echo xlt('Each countable visit goes on the Table 5 line of the person who saw the patient. The suggestion comes from the NUCC taxonomy code on the user (Administration › Users). OpenEMR gives every new user 207Q00000X (Family Medicine), so check anyone who still has it. Choose Not a provider for people whose encounters are not UDS visits, such as medical assistants or LPNs.'); ?></p>
        <table class="table table-sm" style="max-width: 70rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Person'); ?></th><th><?php echo xlt('Taxonomy'); ?></th><th><?php echo xlt('Suggested'); ?></th><th><?php echo xlt('Line used'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($people as $person) {
                $id = Row::int($person, 'id');
                $taxonomy = trim(Row::nullableText($person, 'taxonomy') ?? '');
                $suggestion = $reference->providerTaxonomy->suggest($taxonomy);
                $suggestedLine = $suggestion === null ? null : $reference->table5->line($suggestion['line']);
                $isDefault = strtoupper($taxonomy) === $reference->providerTaxonomy->openemrDefault;
                $choice = $settings->providerLines[$id] ?? ''; ?>
                <tr class="<?php echo $choice === '' && ($isDefault || $suggestion === null) ? 'table-warning' : ''; ?>">
                    <td><?php echo text(trim(Row::text($person, 'lname') . ', ' . Row::text($person, 'fname'), ', ')); ?><?php echo Row::nullableInt($person, 'active') === 0 ? ' <span class="text-muted">(' . xlt('inactive') . ')</span>' : ''; ?></td>
                    <td><?php echo text($taxonomy); ?><?php echo $isDefault ? ' <span class="text-muted">(' . xlt('OpenEMR default') . ')</span>' : ''; ?></td>
                    <td><?php
                    if ($suggestedLine === null) {
                        echo xlt('None');
                    } else {
                        echo text($lineOption($suggestedLine));
                        echo $suggestion['provider'] ? '' : ' <span class="text-muted">(' . xlt('not a provider') . ')</span>';
                    } ?></td>
                    <td>
                        <select class="form-control form-control-sm" name="provider_line[<?php echo attr((string)$id); ?>]" aria-label="<?php echo xla('Line used'); ?>: <?php echo attr(trim(Row::text($person, 'lname') . ', ' . Row::text($person, 'fname'), ', ')); ?>">
                            <option value=""><?php echo xlt('Use the suggestion'); ?></option>
                            <?php foreach ($visitLines as $l) { ?>
                                <option value="<?php echo attr($l->line); ?>" <?php echo $choice === $l->line ? 'selected' : ''; ?>><?php echo text($lineOption($l)); ?></option>
                            <?php } ?>
                            <option value="<?php echo attr(UdsSettings::NOT_A_PROVIDER); ?>" <?php echo $choice === UdsSettings::NOT_A_PROVIDER ? 'selected' : ''; ?>><?php echo xlt('Not a provider (encounters not counted)'); ?></option>
                        </select>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <p class="small text-muted"><?php echo xlt('A visit category can send every visit in it to one line, whoever saw the patient: for example case management or health education visits by a nurse.'); ?></p>
        <table class="table table-sm" style="max-width: 50rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Visit category'); ?></th><th><?php echo xlt('Line'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c) {
                $id = Row::int($c, 'pc_catid');
                $choice = $settings->categoryLines[$id] ?? ''; ?>
                <tr>
                    <td><?php echo text(xl_appt_category(Row::text($c, 'pc_catname'))); ?></td>
                    <td>
                        <select class="form-control form-control-sm" name="category_line[<?php echo attr((string)$id); ?>]" aria-label="<?php echo xla('Line'); ?>: <?php echo attr(xl_appt_category(Row::text($c, 'pc_catname'))); ?>">
                            <option value=""><?php echo xlt('The line of whoever saw the patient'); ?></option>
                            <?php foreach ($visitLines as $l) { ?>
                                <option value="<?php echo attr($l->line); ?>" <?php echo $choice === $l->line ? 'selected' : ''; ?>><?php echo text($lineOption($l)); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
    </form>
</div>
</body>
</html>
