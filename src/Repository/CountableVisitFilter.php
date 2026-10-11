<?php

/**
 * Which encounters are countable visits (2026 UDS Manual, pages 17-21): in the
 * report year, with a provider, in a visit category the clinic marked
 * countable, at an in-scope site. One definition, so the patients on Tables 3A
 * to 4 and the visits on Table 5 come from the same encounters.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final readonly class CountableVisitFilter
{
    /**
     * @param list<int>  $categories visit categories that count
     * @param ?list<int> $facilities in-scope sites; null: all
     */
    public function __construct(public array $categories, public ?array $facilities)
    {
    }

    public static function fromSettings(UdsSettings $settings, UdsPatientRepository $patients): self
    {
        return new self($settings->countableCategoryIds ?? $patients->defaultCountableCategories(), $settings->inScopeFacilityIds);
    }

    /** True when the clinic's choices leave nothing countable (no category, or no site). */
    public function matchesNothing(): bool
    {
        return $this->categories === [] || $this->facilities === [];
    }

    /**
     * A WHERE condition on form_encounter AS fe for the year, and its bound values.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function where(int $year): array
    {
        $binds = [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1), ...$this->categories];
        $sql = 'fe.date >= ? AND fe.date < ? AND fe.provider_id > 0 AND fe.pc_catid IN ('
            . implode(',', array_fill(0, count($this->categories), '?')) . ')';
        if ($this->facilities !== null) {
            $sql .= ' AND fe.facility_id IN (' . implode(',', array_fill(0, count($this->facilities), '?')) . ')';
            array_push($binds, ...$this->facilities);
        }
        return [$sql, $binds];
    }
}
