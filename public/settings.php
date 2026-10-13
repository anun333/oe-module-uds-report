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

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\UdsReport\Bootstrap;
use OpenEMR\Modules\UdsReport\Http\Request;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataGuard;
use OpenEMR\Modules\UdsReport\ReferenceData\ReferenceDataLoader;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5Line;
use OpenEMR\Modules\UdsReport\Report\Labels;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;
use OpenEMR\Modules\UdsReport\Repository\Row;
use OpenEMR\Modules\UdsReport\Repository\SettingsRepository;
use OpenEMR\Modules\UdsReport\Repository\UdsPatientRepository;

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
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH;

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
    $repo->saveList(SettingsRepository::IN_SCOPE_FACILITIES, count($inScope) === count($allFacilityIds) ? null : $inScope, $userId);
    $repo->saveList(SettingsRepository::SCHOOL_BASED_FACILITIES, $postedIds('school_based', $allFacilityIds), $userId);
    $repo->saveList(SettingsRepository::PUBLIC_HOUSING_FACILITIES, $postedIds('public_housing', $allFacilityIds), $userId);
    $repo->savePovertyRegion(PovertyRegion::tryFrom($request->request->getString('poverty_region')) ?? PovertyRegion::Contiguous, $userId);
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
$countable = $settings->countableCategoryIds ?? $patientRepository->defaultCountableCategories();
$csrf = CsrfUtils::collectCsrfToken(session: $session);
$checked = static fn(bool $on): string => $on ? 'checked' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('UDS Settings'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3" style="max-width: 70rem">
    <a href="<?php echo attr($moduleUrl . '/public/index.php'); ?>">&larr; <?php echo xlt('UDS Report'); ?></a>
    <h1 class="h4 mt-2"><?php echo xlt('UDS Settings'); ?></h1>
    <?php if ($saved) {
        ?><div class="alert alert-success py-2"><?php echo xlt('Saved.'); ?></div><?php } ?>

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

        <h2 class="h6 mt-4"><?php echo xlt('Sites'); ?></h2>
        <table class="table table-sm" style="max-width: 50rem">
            <thead class="thead-light"><tr><th><?php echo xlt('Facility'); ?></th><th><?php echo xlt('In scope of project'); ?></th>
                <th><?php echo xlt('School-based site'); ?></th><th><?php echo xlt('In or next to public housing'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($facilities as $f) {
                $id = Row::int($f, 'id'); ?>
                <tr>
                    <td><?php echo text(Row::text($f, 'name')); ?><?php echo Row::nullableInt($f, 'inactive') === 1 ? ' <span class="text-muted">(' . xlt('inactive') . ')</span>' : ''; ?></td>
                    <td><input type="checkbox" name="in_scope[]" value="<?php echo attr((string)$id); ?>" <?php echo $checked($settings->inScopeFacilityIds === null || in_array($id, $settings->inScopeFacilityIds, true)); ?>></td>
                    <td><input type="checkbox" name="school_based[]" value="<?php echo attr((string)$id); ?>" <?php echo $checked(in_array($id, $settings->schoolBasedFacilityIds, true)); ?>></td>
                    <td><input type="checkbox" name="public_housing[]" value="<?php echo attr((string)$id); ?>" <?php echo $checked(in_array($id, $settings->publicHousingFacilityIds, true)); ?>></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <h2 class="h6 mt-4"><?php echo xlt('Poverty guidelines'); ?></h2>
        <select class="form-control form-control-sm" name="poverty_region" style="max-width: 24rem">
            <?php foreach (PovertyRegion::cases() as $region) { ?>
                <option value="<?php echo attr($region->value); ?>" <?php echo $settings->povertyRegion === $region ? 'selected' : ''; ?>><?php echo text(Labels::povertyRegion($region)); ?></option>
            <?php } ?>
        </select>
        <p class="small text-muted"><?php echo xlt('Use the guidelines for where the health center is located.'); ?></p>

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
                        <select class="form-control form-control-sm" name="insurance[<?php echo attr((string)$id); ?>]">
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
                        <select class="form-control form-control-sm" name="provider_line[<?php echo attr((string)$id); ?>]">
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
                        <select class="form-control form-control-sm" name="category_line[<?php echo attr((string)$id); ?>]">
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
