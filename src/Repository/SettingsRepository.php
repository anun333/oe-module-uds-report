<?php

/**
 * Reads and saves the UDS settings and the clinic's insurance category choices.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\PovertyRegion;
use OpenEMR\Modules\UdsReport\Report\PublishedUds;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final class SettingsRepository
{
    public const COUNTABLE_CATEGORIES = 'countable_category_ids';
    public const IN_SCOPE_FACILITIES = 'in_scope_facility_ids';
    public const SCHOOL_BASED_FACILITIES = 'school_based_facility_ids';
    public const PUBLIC_HOUSING_FACILITIES = 'public_housing_facility_ids';
    public const VIRTUAL_CATEGORIES = 'virtual_category_ids';
    public const SERVICE_AREA_ZIPS = 'service_area_zips';
    public const HRSA_GRANT = 'hrsa_grant_number';
    private const LIST_SETTINGS = [
        self::COUNTABLE_CATEGORIES, self::IN_SCOPE_FACILITIES, self::SCHOOL_BASED_FACILITIES, self::PUBLIC_HOUSING_FACILITIES,
        self::VIRTUAL_CATEGORIES,
    ];
    private const POVERTY_REGION = 'poverty_region';
    public const PROVIDER_LINES = 'table5_provider_lines';
    public const CATEGORY_LINES = 'table5_category_lines';

    public function load(): UdsSettings
    {
        $values = [];
        foreach (QueryUtils::fetchRecords('SELECT `name`, `value` FROM `uds_settings`') as $row) {
            try {
                $values[Row::text($row, 'name')] = json_decode(Row::text($row, 'value'), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // A damaged setting falls back to its default.
            }
        }
        $overrides = [];
        foreach (QueryUtils::fetchRecords('SELECT `insurance_company_id`, `category` FROM `uds_insurance_map`') as $row) {
            $category = InsuranceCategory::tryFrom(Row::text($row, 'category'));
            if ($category !== null && $category !== InsuranceCategory::Unmapped) {
                $overrides[Row::int($row, 'insurance_company_id')] = $category;
            }
        }
        $region = $values[self::POVERTY_REGION] ?? null;
        return new UdsSettings(
            countableCategoryIds: self::intListOrNull($values[self::COUNTABLE_CATEGORIES] ?? null),
            inScopeFacilityIds: self::intListOrNull($values[self::IN_SCOPE_FACILITIES] ?? null),
            schoolBasedFacilityIds: self::intListOrNull($values[self::SCHOOL_BASED_FACILITIES] ?? null) ?? [],
            publicHousingFacilityIds: self::intListOrNull($values[self::PUBLIC_HOUSING_FACILITIES] ?? null) ?? [],
            povertyRegion: (is_string($region) ? PovertyRegion::tryFrom($region) : null) ?? PovertyRegion::Contiguous,
            insuranceOverrides: $overrides,
            providerLines: self::lineMap($values[self::PROVIDER_LINES] ?? null),
            categoryLines: self::lineMap($values[self::CATEGORY_LINES] ?? null),
            virtualCategoryIds: self::intListOrNull($values[self::VIRTUAL_CATEGORIES] ?? null),
            serviceAreaZips: self::zipList($values[self::SERVICE_AREA_ZIPS] ?? null),
            hrsaGrantNumber: is_string($values[self::HRSA_GRANT] ?? null) ? PublishedUds::normalizeGrant($values[self::HRSA_GRANT]) : null,
        );
    }

    /**
     * @param ?list<int> $ids null clears the setting, back to its default
     */
    public function saveList(string $name, ?array $ids, int $userId): void
    {
        if (!in_array($name, self::LIST_SETTINGS, true)) {
            throw new \InvalidArgumentException('Unknown UDS setting');
        }
        $this->save($name, $ids === null ? null : array_values(array_unique($ids)), $userId);
    }

    /**
     * The clinic's Table 5 line choices: per provider (PROVIDER_LINES) or per visit category (CATEGORY_LINES).
     *
     * @param array<int, string> $map id => line; an empty map clears the setting
     */
    public function saveLineMap(string $name, array $map, int $userId): void
    {
        if (!in_array($name, [self::PROVIDER_LINES, self::CATEGORY_LINES], true)) {
            throw new \InvalidArgumentException('Unknown UDS setting');
        }
        ksort($map);
        $this->save($name, $map === [] ? null : $map, $userId);
    }

    /** @param list<string> $zips five-digit ZIP codes; empty clears the setting */
    public function saveServiceAreaZips(array $zips, int $userId): void
    {
        $this->save(self::SERVICE_AREA_ZIPS, $zips === [] ? null : array_values($zips), $userId);
    }

    /** The health center's HRSA grant or look-alike number, as PublishedUds::normalizeGrant() gives it; null clears it. */
    public function saveGrantNumber(?string $grant, int $userId): void
    {
        $this->save(self::HRSA_GRANT, $grant, $userId);
    }

    public function savePovertyRegion(PovertyRegion $region, int $userId): void
    {
        $this->save(self::POVERTY_REGION, $region->value, $userId);
    }

    /** A null category removes the clinic's choice, so the automatic category applies again. */
    public function saveInsuranceOverride(int $companyId, ?InsuranceCategory $category, int $userId): void
    {
        if ($category === null || $category === InsuranceCategory::Unmapped) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `uds_insurance_map` WHERE `insurance_company_id` = ?', [$companyId]);
            return;
        }
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `uds_insurance_map` (`insurance_company_id`, `category`, `updated_by`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `category` = VALUES(`category`), `updated_by` = VALUES(`updated_by`)',
            [$companyId, $category->value, $userId],
        );
    }

    /**
     * @param string|list<int>|array<int, string>|null $value
     */
    private function save(string $name, string|array|null $value, int $userId): void
    {
        if ($value === null) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `uds_settings` WHERE `name` = ?', [$name]);
            return;
        }
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `uds_settings` (`name`, `value`, `updated_by`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`)',
            [$name, json_encode($value, JSON_THROW_ON_ERROR), $userId],
        );
    }

    /**
     * A saved id => line map. JSON object keys come back as numeric strings, which PHP
     * turns into int array keys; anything else is dropped.
     *
     * @return array<int, string>
     */
    private static function lineMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $id => $line) {
            if (is_int($id) && $id > 0 && is_string($line) && preg_match('/^[0-9a-z]{1,6}$/', $line) === 1) {
                $map[$id] = $line;
            }
        }
        return $map;
    }

    /**
     * @return ?list<int>
     */
    /** @return list<string> the saved five-digit ZIP codes; anything else is dropped */
    private static function zipList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($z): string => is_scalar($z) ? (string)$z : '', $value),
            static fn(string $z): bool => preg_match('/^\d{5}$/', $z) === 1));
    }

    private static function intListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return array_values(array_filter($value, is_int(...)));
    }
}
