<?php

/**
 * Which kind of staff a visit was with, by Table 5's service categories
 * (medical, dental, ...), for the Table 6A lines only some staff may provide:
 * the dental services of Lines 27-32 (2026 UDS Manual, page 73).
 *
 * The staff member is the encounter's provider, placed as Table 5 places them:
 *  1. the line the clinic set for the visit's category, if any;
 *  2. else the line the clinic chose for the provider;
 *  3. else the line suggested by the provider's NUCC taxonomy code.
 * Unlike a Table 5 visit, it doesn't matter whether that line reports visits:
 * a dental assistant working under a dentist is dental staff. For the same
 * reason "Not a provider" in Settings falls through to the taxonomy code.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\Model\Provider;
use OpenEMR\Modules\UdsReport\ReferenceData\ProviderTaxonomy;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5 as Table5Definition;

final readonly class EncounterPersonnel
{
    /** @param array<int, Provider> $providers the providers on the encounters */
    public function __construct(
        private Table5Definition $definition,
        private ProviderTaxonomy $taxonomy,
        private array $providers,
        private UdsSettings $settings,
    ) {
    }

    /** The Table 5 service category, or null when no line is known (or the encounter has no provider). */
    public function category(int $providerId, int $categoryId): ?string
    {
        $line = $this->settings->categoryLines[$categoryId] ?? null;
        if ($line === null && $providerId > 0) {
            $chosen = $this->settings->providerLines[$providerId] ?? null;
            $line = $chosen === UdsSettings::NOT_A_PROVIDER ? null : $chosen;
            if ($line === null) {
                $provider = $this->providers[$providerId] ?? null;
                $line = $provider === null ? null : ($this->taxonomy->suggest($provider->taxonomy)['line'] ?? null);
            }
        }
        return $line === null ? null : $this->definition->line($line)?->category;
    }
}
