<?php

/**
 * UDS Table 5 (Staffing and Utilization), from data/table-5.json: its lines, what
 * makes a visit virtual, and the diagnoses that make a behavioral health visit a
 * substance use disorder visit.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

final readonly class Table5
{
    public const FILE = 'table-5';
    public const PATIENTS_SUM = 'sum';
    public const MENTAL_HEALTH = 'mental_health';
    public const SUBSTANCE_USE = 'sud';
    /** Where a mental health provider's substance use disorder visit goes (pages 54-55). */
    public const SUBSTANCE_USE_LINE = '21';
    /** Where a substance use disorder specialist's mental health visit goes: they aren't licensed mental health providers. */
    public const OTHER_MENTAL_HEALTH_LINE = '20c';
    /** Categories that allow one visit per patient per day for each provider type, not one in all (page 19). */
    public const PER_PROVIDER_TYPE = ['other_professional', 'patient_support'];
    /** Table 5's service categories (pages 50-60); every line belongs to one. */
    public const CATEGORIES = [
        'medical', 'dental', self::MENTAL_HEALTH, self::SUBSTANCE_USE, 'other_professional', 'vision',
        'patient_support', 'pharmacy', 'other_programs', 'central_support',
    ];
    private const VIRTUAL_SYSTEMS = ['cpt', 'hcpcs'];

    /** @var array<string, Table5Line> */
    private array $byId;

    /**
     * @param list<Table5Line>                 $lines            in the table's order
     * @param array<string, string>            $categories       key => name
     * @param list<string>                     $virtualPlaces    two-digit place of service codes
     * @param list<string>                     $virtualClasses   OpenEMR encounter class codes
     * @param list<string>                     $virtualModifiers
     * @param array<string, list<Table6aCode>> $virtualCodes     by code system (cpt, hcpcs)
     * @param list<Table6aCode>                $substanceUse     ICD-10 codes
     * @param list<string>                     $virtualCategories visit category constant ids virtual by default (Comlink's telehealth ones)
     */
    public function __construct(
        public array $lines,
        public array $categories,
        public array $virtualPlaces,
        public array $virtualClasses,
        public array $virtualModifiers,
        public array $virtualCodes,
        public array $substanceUse,
        public array $virtualCategories = [],
    ) {
        $byId = [];
        foreach ($lines as $line) {
            $byId[$line->line] = $line;
        }
        $this->byId = $byId;
    }

    public function line(string $id): ?Table5Line
    {
        return $this->byId[$id] ?? null;
    }

    public function isVirtualCode(string $system, string $code): bool
    {
        foreach ($this->virtualCodes[$system] ?? [] as $spec) {
            if ($spec->matches($code)) {
                return true;
            }
        }
        return false;
    }

    public function isSubstanceUse(string $icd10): bool
    {
        foreach ($this->substanceUse as $spec) {
            if ($spec->matches($icd10)) {
                return true;
            }
        }
        return false;
    }

    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $top = JsonShape::object($data, $file, ['about', 'basis', 'categories', 'virtual', 'substanceUseDiagnoses', 'lines']);
        JsonShape::text($top['about'], "$file about");
        JsonShape::text($top['basis'], "$file basis");

        $categories = [];
        foreach (JsonShape::object($top['categories'], "$file categories", self::CATEGORIES) as $key => $name) {
            $categories[$key] = JsonShape::text($name, "$file categories.$key");
        }

        $virtual = JsonShape::object($top['virtual'], "$file virtual", ['about', 'placeOfService', 'encounterClass', 'modifiers', 'categories', 'codes']);
        JsonShape::text($virtual['about'], "$file virtual.about");
        $places = self::codes($virtual['placeOfService'], "$file virtual.placeOfService", '/^\d{2}$/');
        $classes = self::codes($virtual['encounterClass'], "$file virtual.encounterClass", '/^[A-Z]{1,10}$/');
        $modifiers = self::codes($virtual['modifiers'], "$file virtual.modifiers", '/^[A-Z0-9]{2}$/');
        $virtualCategories = self::codes($virtual['categories'], "$file virtual.categories", '/^[a-z0-9_]{1,64}$/');
        $virtualCodes = [];
        foreach (JsonShape::object($virtual['codes'], "$file virtual.codes", [], self::VIRTUAL_SYSTEMS) as $system => $list) {
            foreach (JsonShape::list($list, "$file virtual.codes.$system") as $j => $spec) {
                $where = "$file virtual.codes.{$system}[{$j}]";
                $virtualCodes[$system][] = Table6aCode::parse(JsonShape::text($spec, $where), $where);
            }
        }

        $sud = JsonShape::object($top['substanceUseDiagnoses'], "$file substanceUseDiagnoses", ['about', 'icd10']);
        JsonShape::text($sud['about'], "$file substanceUseDiagnoses.about");
        $substanceUse = [];
        foreach (JsonShape::list($sud['icd10'], "$file substanceUseDiagnoses.icd10") as $j => $spec) {
            $where = "$file substanceUseDiagnoses.icd10[$j]";
            $substanceUse[] = Table6aCode::parse(JsonShape::text($spec, $where), $where);
        }
        if ($substanceUse === []) {
            throw new ReferenceDataException("$file substanceUseDiagnoses.icd10", 'is empty');
        }

        $lines = [];
        $seen = [];
        $patientsFor = [];
        foreach (JsonShape::list($top['lines'], "$file lines") as $i => $raw) {
            $f = JsonShape::object($raw, "$file lines[$i]", ['line', 'label'], ['category', 'visits', 'sum', 'patients']);
            $id = JsonShape::text($f['line'], "$file lines[$i] line");
            $where = "$file line $id";
            if (isset($seen[$id])) {
                throw new ReferenceDataException($where, 'appears twice');
            }
            $label = JsonShape::text($f['label'], "$where label");
            if (isset($f['category']) === isset($f['sum'])) {
                throw new ReferenceDataException($where, 'needs a category (a personnel line) or a sum (a total line), not both');
            }
            $category = null;
            $visits = false;
            $sum = [];
            if (isset($f['category'])) {
                $category = JsonShape::oneOf($f['category'], "$where category", array_keys($categories));
                if (!array_key_exists('visits', $f)) {
                    throw new ReferenceDataException($where, 'a personnel line needs visits: true or false');
                }
                $visits = JsonShape::bool($f['visits'], "$where visits");
            } else {
                if (array_key_exists('visits', $f)) {
                    throw new ReferenceDataException($where, 'a total line reports visits when the lines it adds up do');
                }
                foreach (JsonShape::list($f['sum'], "$where sum") as $j => $part) {
                    $part = JsonShape::text($part, "$where sum[$j]");
                    if (!isset($seen[$part])) {
                        throw new ReferenceDataException("$where sum[$j]", "adds up line $part, which must come earlier");
                    }
                    $sum[] = $part;
                }
                if ($sum === []) {
                    throw new ReferenceDataException("$where sum", 'is empty');
                }
            }
            $patients = null;
            if (isset($f['patients'])) {
                $patients = JsonShape::oneOf($f['patients'], "$where patients", [...array_keys($categories), self::PATIENTS_SUM]);
                if ($patients === self::PATIENTS_SUM && $sum === []) {
                    throw new ReferenceDataException($where, 'only a total line can add up patients');
                }
                if (isset($patientsFor[$patients])) {
                    throw new ReferenceDataException($where, "reports $patients patients, which line {$patientsFor[$patients]} already does");
                }
                $patientsFor[$patients] = $id;
            }
            $seen[$id] = true;
            $lines[] = new Table5Line($id, $label, $category, $visits, $sum, $patients);
        }

        $definition = new self($lines, $categories, $places, $classes, $modifiers, $virtualCodes, $substanceUse, $virtualCategories);
        foreach ($lines as $line) {
            if ($line->visits && $line->category !== null && !isset($patientsFor[$line->category])) {
                throw new ReferenceDataException("$file line {$line->line}", "reports {$line->category} visits, but no line reports {$line->category} patients");
            }
        }
        foreach ([[self::SUBSTANCE_USE_LINE, self::SUBSTANCE_USE], [self::OTHER_MENTAL_HEALTH_LINE, self::MENTAL_HEALTH]] as [$id, $category]) {
            $line = $definition->line($id);
            if ($line === null || !$line->visits || $line->category !== $category) {
                throw new ReferenceDataException("$file line $id", "must be a $category line that reports visits");
            }
        }
        return $definition;
    }

    /** @return list<string> */
    private static function codes(mixed $value, string $where, string $pattern): array
    {
        $out = [];
        foreach (JsonShape::list($value, $where) as $j => $code) {
            $code = JsonShape::text($code, "{$where}[{$j}]");
            if (preg_match($pattern, $code) !== 1) {
                throw new ReferenceDataException("{$where}[{$j}]", "is not a code of the expected form");
            }
            $out[] = $code;
        }
        return $out;
    }
}
