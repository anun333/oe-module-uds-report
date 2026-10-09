<?php

/**
 * How an OpenEMR insurance company gets its UDS insurance category.
 *
 * In order:
 * 1. the clinic's own choice for that company (Settings page),
 * 2. its Source of Payment Typology code (insurance_companies.cqm_sop),
 *    using only the top-level group, and
 * 3. its claim type (insurance_companies.ins_type_code).
 * Steps 2 and 3 are tables in data/insurance-categories.json. Anything still
 * undecided is Unmapped and reported as a problem to fix, never guessed: the
 * UDS has no "unknown insurance" line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;

final readonly class InsuranceCategoryMap
{
    public const FILE = 'insurance-categories';

    /**
     * @param array<string, ?InsuranceCategory> $typologyGroups first digit of the typology code => category (null: undecided)
     * @param array<int, ?InsuranceCategory>    $claimTypes     insurance_type_codes.id => category (null: undecided)
     */
    private function __construct(
        private array $typologyGroups,
        private array $claimTypes,
    ) {
    }

    public function forCompany(?InsuranceCategory $clinicChoice, ?string $typologyCode, ?int $claimTypeId): InsuranceCategory
    {
        if ($clinicChoice !== null && $clinicChoice !== InsuranceCategory::Unmapped) {
            return $clinicChoice;
        }
        $typology = trim($typologyCode ?? '');
        if ($typology !== '' && ctype_digit($typology)) {
            $category = $this->typologyGroups[$typology[0]] ?? null;
            if ($category !== null) {
                return $category;
            }
        }
        if ($claimTypeId !== null) {
            $category = $this->claimTypes[$claimTypeId] ?? null;
            if ($category !== null) {
                return $category;
            }
        }
        return InsuranceCategory::Unmapped;
    }

    /** Checks the decoded file. A null (undecided) category must say why in its note. */
    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $root = JsonShape::object($data, $file, ['about', 'basis', 'paymentTypologyGroups', 'claimTypes']);
        JsonShape::text($root['about'], $file . ' about');
        JsonShape::text($root['basis'], $file . ' basis');

        $typologyGroups = [];
        foreach (JsonShape::list($root['paymentTypologyGroups'], $file . ' paymentTypologyGroups') as $i => $row) {
            $at = sprintf('%s paymentTypologyGroups[%d]', $file, $i);
            $g = JsonShape::object($row, $at, ['group', 'name', 'category'], ['note']);
            $group = JsonShape::oneOf($g['group'], $at . '.group', ['1', '2', '3', '4', '5', '6', '7', '8', '9']);
            JsonShape::text($g['name'], $at . '.name');
            if (array_key_exists($group, $typologyGroups)) {
                throw new ReferenceDataException($at . '.group', sprintf('group %s is listed twice', $group));
            }
            $typologyGroups[$group] = self::category($g, $at);
        }

        $claimTypes = [];
        foreach (JsonShape::list($root['claimTypes'], $file . ' claimTypes') as $i => $row) {
            $at = sprintf('%s claimTypes[%d]', $file, $i);
            $c = JsonShape::object($row, $at, ['id', 'name', 'category'], ['note']);
            $id = JsonShape::int($c['id'], $at . '.id', 1, 999);
            JsonShape::text($c['name'], $at . '.name');
            if (array_key_exists($id, $claimTypes)) {
                throw new ReferenceDataException($at . '.id', sprintf('claim type %d is listed twice', $id));
            }
            $claimTypes[$id] = self::category($c, $at);
        }

        return new self($typologyGroups, $claimTypes);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function category(array $row, string $at): ?InsuranceCategory
    {
        $hasNote = array_key_exists('note', $row);
        if ($hasNote) {
            JsonShape::text($row['note'], $at . '.note');
        }
        if ($row['category'] === null) {
            if (!$hasNote) {
                throw new ReferenceDataException($at, 'an undecided (null) category needs a note saying why');
            }
            return null;
        }
        $allowed = array_map(static fn(InsuranceCategory $c): string => $c->value, InsuranceCategory::assignable());
        return InsuranceCategory::from(JsonShape::oneOf($row['category'], $at . '.category', $allowed));
    }
}
