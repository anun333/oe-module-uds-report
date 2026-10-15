<?php

/**
 * One value set as VSAC expanded it: its title, version and codes by code
 * system. Codes in a system no Table 6A line reads are counted, not kept.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ValueSet;

final readonly class ValueSetExpansion
{
    /** The code system URIs VSAC uses, and the module's name for each. */
    public const SYSTEMS = [
        'http://hl7.org/fhir/sid/icd-10-cm' => 'icd10',
        'http://www.ama-assn.org/go/cpt' => 'cpt',
        'http://www.cms.gov/Medicare/Coding/HCPCSReleaseCodeSets' => 'hcpcs',
        'http://snomed.info/sct' => 'snomed',
        'http://www.nlm.nih.gov/research/umls/rxnorm' => 'rxnorm',
        'http://loinc.org' => 'loinc',
    ];

    /**
     * @param array<string, list<string>> $codes        by the module's code system name, as VSAC wrote them
     * @param int                         $otherSystems codes in systems not in SYSTEMS, left out
     */
    public function __construct(
        public string $oid,
        public string $title,
        public string $version,
        public array $codes,
        public int $otherSystems,
    ) {
    }

    public function count(): int
    {
        return array_sum(array_map(count(...), $this->codes));
    }

    /**
     * Reads the pages of a FHIR ValueSet $expand.
     *
     * @param list<mixed> $pages each page's decoded JSON, in order
     * @throws ValueSetException when a page isn't an expansion, or the pages hold fewer codes than its total
     */
    public static function fromFhir(string $oid, array $pages): self
    {
        $first = $pages[0] ?? null;
        if (!is_array($first) || ($first['resourceType'] ?? null) !== 'ValueSet') {
            throw new ValueSetException("VSAC's answer for $oid isn't a value set");
        }
        $title = $first['title'] ?? $first['name'] ?? '';
        $version = $first['version'] ?? '';
        $total = is_array($first['expansion'] ?? null) ? ($first['expansion']['total'] ?? null) : null;

        $codes = [];
        $seen = 0;
        $other = 0;
        foreach ($pages as $page) {
            $expansion = is_array($page) ? ($page['expansion'] ?? null) : null;
            if (!is_array($expansion)) {
                throw new ValueSetException("VSAC's answer for $oid has no expansion");
            }
            $contains = $expansion['contains'] ?? [];
            if (!is_array($contains)) {
                throw new ValueSetException("VSAC's expansion of $oid isn't a list of codes");
            }
            foreach (self::flatten($contains, $oid) as [$systemUri, $code]) {
                $seen++;
                $system = self::SYSTEMS[$systemUri] ?? null;
                if ($system === null) {
                    $other++;
                    continue;
                }
                $codes[$system][] = $code;
            }
        }
        if (is_int($total) && $seen < $total) {
            throw new ValueSetException("VSAC sent $seen of the $total codes in $oid");
        }
        return new self(
            $oid,
            is_string($title) ? $title : '',
            is_string($version) ? $version : '',
            array_map(static fn(array $list): array => array_values(array_unique($list)), $codes),
            $other,
        );
    }

    /**
     * @param array<mixed> $contains
     * @return list<array{string, string}> system URI and code of each entry, nested entries included
     */
    private static function flatten(array $contains, string $oid): array
    {
        $out = [];
        foreach ($contains as $entry) {
            if (!is_array($entry)) {
                throw new ValueSetException("VSAC's expansion of $oid has an entry that isn't a code");
            }
            $system = $entry['system'] ?? null;
            $code = $entry['code'] ?? null;
            if (is_string($system) && is_string($code) && trim($code) !== '') {
                $out[] = [$system, trim($code)];
            } elseif (!isset($entry['contains'])) {
                throw new ValueSetException("VSAC's expansion of $oid has an entry with no system or code");
            }
            if (is_array($entry['contains'] ?? null)) {
                array_push($out, ...self::flatten($entry['contains'], $oid));
            }
        }
        return $out;
    }
}
