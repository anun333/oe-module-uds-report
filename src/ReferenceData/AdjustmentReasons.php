<?php

/**
 * Suggested Table 9D treatment of an adjustment, from the reason posted with it
 * (data/table-9d-adjustments.json). Clinics add their own reasons, so anything
 * no rule matches is left for whoever prepares Table 9D.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use OpenEMR\Modules\UdsReport\Enum\AdjustmentClass;

final readonly class AdjustmentReasons
{
    public const FILE = 'table-9d-adjustments';

    /** @param list<array{prefix: bool, label: string, class: AdjustmentClass, note: ?string}> $rules first match wins; labels normalized */
    public function __construct(private array $rules)
    {
    }

    /**
     * @return array{class: AdjustmentClass, note: ?string, matched: bool}
     */
    public function classify(string $reason): array
    {
        $key = self::normalize($reason);
        foreach ($this->rules as $rule) {
            if ($rule['prefix'] ? str_starts_with($key, $rule['label']) : $key === $rule['label']) {
                return ['class' => $rule['class'], 'note' => $rule['note'], 'matched' => true];
            }
        }
        return ['class' => AdjustmentClass::Review, 'note' => null, 'matched' => false];
    }

    /**
     * The reason as it is compared and grouped: lowercase, single spaces, and
     * without the Ins1, Ins2 or Ins3 OpenEMR appends to reasons containing "ins".
     */
    public static function normalize(string $reason): string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', $reason) ?? $reason));
        return preg_replace('/ ins[1-3]$/D', '', $key) ?? $key;
    }

    public static function fromData(mixed $data): self
    {
        $file = ReferenceDataLoader::label(self::FILE);
        $top = JsonShape::object($data, $file, ['about', 'basis', 'rules']);
        JsonShape::text($top['about'], "$file about");
        JsonShape::text($top['basis'], "$file basis");
        $classes = array_map(static fn(AdjustmentClass $c): string => $c->value, AdjustmentClass::cases());

        $rules = [];
        $seen = [];
        foreach (JsonShape::list($top['rules'], "$file rules") as $i => $raw) {
            $where = "$file rules[$i]";
            $f = JsonShape::object($raw, $where, ['match', 'label', 'class'], ['note']);
            $prefix = JsonShape::oneOf($f['match'], "$where match", ['exact', 'prefix']) === 'prefix';
            $label = self::normalize(JsonShape::text($f['label'], "$where label"));
            if (isset($seen[$label])) {
                throw new ReferenceDataException("$where label", "\"$label\" already has a rule");
            }
            $seen[$label] = true;
            $rules[] = [
                'prefix' => $prefix,
                'label' => $label,
                'class' => AdjustmentClass::from(JsonShape::oneOf($f['class'], "$where class", $classes)),
                'note' => isset($f['note']) ? JsonShape::text($f['note'], "$where note") : null,
            ];
        }
        if ($rules === []) {
            throw new ReferenceDataException("$file rules", 'is empty');
        }
        return new self($rules);
    }
}
