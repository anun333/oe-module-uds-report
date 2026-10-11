<?php

/**
 * UDS Table 5, Staffing and Utilization: visits (Columns B and B2) and patients
 * (Column C), following the 2026 UDS Manual (pages 17-21 and 50-70). FTEs
 * (Column A) come from HR records and are left to the clinic.
 *
 * Every countable visit goes on one personnel line:
 *  1. the line the clinic set for the visit's category, if any;
 *  2. else the line the clinic chose for the provider;
 *  3. else the line suggested by the provider's NUCC taxonomy code.
 * A visit on a line whose personnel aren't providers, or with no line, isn't a
 * Table 5 visit. A mental health or substance use disorder visit then goes by
 * its first diagnosis, not the provider's title (pages 54-55).
 *
 * One visit counts per patient, per service category, per site, per day; for
 * other professional and patient support services, per provider type as well
 * (page 19). When several encounters make one visit, it goes to the line that
 * comes first in the table (physicians before NPs, before nurses), then to the
 * earliest encounter.
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
use OpenEMR\Modules\UdsReport\Model\Table5Visit;
use OpenEMR\Modules\UdsReport\ReferenceData\ProviderTaxonomy;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5 as Table5Definition;
use OpenEMR\Modules\UdsReport\ReferenceData\Table5Line;

final readonly class Table5
{
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_CLINIC = 'clinic';
    public const SOURCE_TAXONOMY = 'taxonomy';
    public const SOURCE_DEFAULT_TAXONOMY = 'default taxonomy';
    public const SOURCE_NONE = 'none';

    /**
     * @param list<Table5Visit>    $visits    the year's countable visits
     * @param array<int, Provider> $providers the providers on them
     * @param array<int, true>     $patients  pids of UDS patients (the Tables 3A-4 population)
     */
    public function __construct(
        private Table5Definition $definition,
        private ProviderTaxonomy $taxonomy,
        private array $visits,
        private array $providers,
        private UdsSettings $settings,
        private array $patients,
    ) {
    }

    /**
     * Where one provider's visits go when no visit category says otherwise.
     *
     * @return array{line: ?string, source: string, suggested: ?string, suggestedProvider: bool}
     *         line null: not a Table 5 visit (not a provider, or no line known)
     */
    public function providerLine(int $providerId): array
    {
        $provider = $this->providers[$providerId] ?? null;
        $suggestion = $provider === null ? null : $this->taxonomy->suggest($provider->taxonomy);
        $suggested = $suggestion['line'] ?? null;
        $suggestedProvider = $suggestion['provider'] ?? false;
        $chosen = $this->settings->providerLines[$providerId] ?? null;
        if ($chosen !== null) {
            $line = $chosen === UdsSettings::NOT_A_PROVIDER ? null : $chosen;
            return ['line' => $this->visitLine($line), 'source' => self::SOURCE_CLINIC, 'suggested' => $suggested, 'suggestedProvider' => $suggestedProvider];
        }
        if ($suggestion === null || $provider === null) {
            return ['line' => null, 'source' => self::SOURCE_NONE, 'suggested' => null, 'suggestedProvider' => false];
        }
        return [
            'line' => $suggestedProvider ? $this->visitLine($suggested) : null,
            'source' => strtoupper($provider->taxonomy) === $this->taxonomy->openemrDefault ? self::SOURCE_DEFAULT_TAXONOMY : self::SOURCE_TAXONOMY,
            'suggested' => $suggested,
            'suggestedProvider' => $suggestedProvider,
        ];
    }

    /**
     * @return array{
     *     rows: list<array{line: Table5Line, visits: ?int, virtual: ?int, patients: ?int}>,
     *     providers: list<array{provider: Provider, encounters: int, line: ?string, source: string, suggested: ?string, suggestedProvider: bool}>,
     *     notCounted: int,
     *     merged: int,
     *     behavioralWithoutDiagnosis: int,
     *     patientsWithoutVisit: list<int>
     * }
     */
    public function result(): array
    {
        $groups = [];
        $notCounted = 0;
        $withoutDiagnosis = 0;
        $encountersByProvider = [];
        foreach ($this->visits as $visit) {
            $encountersByProvider[$visit->providerId] = ($encountersByProvider[$visit->providerId] ?? 0) + 1;
            $line = $this->lineFor($visit);
            if ($line === null) {
                $notCounted++;
                continue;
            }
            if (in_array($line->category, [Table5Definition::MENTAL_HEALTH, Table5Definition::SUBSTANCE_USE], true)) {
                if ($visit->firstDiagnosis === null) {
                    $withoutDiagnosis++;
                } else {
                    $line = $this->behavioralLine($line, $visit->firstDiagnosis);
                }
            }
            $key = implode('|', [$visit->pid, $visit->day, $line->category, $visit->facilityId]);
            if (in_array($line->category, Table5Definition::PER_PROVIDER_TYPE, true)) {
                $key .= '|' . $line->line;
            }
            $groups[$key][] = ['visit' => $visit, 'line' => $line];
        }

        $order = array_flip(array_map(static fn(Table5Line $l): string => $l->line, $this->definition->lines));
        $visits = [];
        $virtual = [];
        $patientsByCategory = [];
        $merged = 0;
        foreach ($groups as $group) {
            usort($group, static fn(array $a, array $b): int =>
                [$order[$a['line']->line], $a['visit']->encounter] <=> [$order[$b['line']->line], $b['visit']->encounter]);
            $merged += count($group) - 1;
            $credited = $group[0];
            $id = $credited['line']->line;
            if ($this->isVirtual($credited['visit'])) {
                $virtual[$id] = ($virtual[$id] ?? 0) + 1;
            } else {
                $visits[$id] = ($visits[$id] ?? 0) + 1;
            }
            $patientsByCategory[(string) $credited['line']->category][$credited['visit']->pid] = true;
        }

        $rows = [];
        $byLine = [];
        foreach ($this->definition->lines as $line) {
            if ($line->isPersonnel()) {
                $row = [
                    'line' => $line,
                    'visits' => $line->visits ? ($visits[$line->line] ?? 0) : null,
                    'virtual' => $line->visits ? ($virtual[$line->line] ?? 0) : null,
                    'patients' => null,
                ];
            } else {
                $parts = array_map(static fn(string $id): array => $byLine[$id], $line->sum);
                $reported = array_filter($parts, static fn(array $p): bool => $p['visits'] !== null);
                $row = [
                    'line' => $line,
                    'visits' => $reported === [] ? null : array_sum(array_column($reported, 'visits')),
                    'virtual' => $reported === [] ? null : array_sum(array_column($reported, 'virtual')),
                    'patients' => null,
                ];
            }
            if ($line->patients === Table5Definition::PATIENTS_SUM) {
                $row['patients'] = array_sum(array_filter(
                    array_map(static fn(string $id): ?int => $byLine[$id]['patients'], $line->sum),
                    static fn(?int $n): bool => $n !== null,
                ));
            } elseif ($line->patients !== null) {
                $row['patients'] = count($patientsByCategory[$line->patients] ?? []);
            }
            $byLine[$line->line] = $row;
            $rows[] = $row;
        }

        $seen = [];
        foreach ($patientsByCategory as $pids) {
            $seen += $pids;
        }
        $providers = [];
        foreach ($encountersByProvider as $providerId => $count) {
            $provider = $this->providers[$providerId] ?? new Provider($providerId, "#$providerId", '');
            $providers[] = ['provider' => $provider, 'encounters' => $count] + $this->providerLine($providerId);
        }
        usort($providers, static fn(array $a, array $b): int => strcasecmp($a['provider']->name, $b['provider']->name));

        return [
            'rows' => $rows,
            'providers' => $providers,
            'notCounted' => $notCounted,
            'merged' => $merged,
            'behavioralWithoutDiagnosis' => $withoutDiagnosis,
            'patientsWithoutVisit' => array_values(array_keys(array_diff_key($this->patients, $seen))),
        ];
    }

    /** The personnel line a visit goes on, before the behavioral health rule; null when it isn't a Table 5 visit. */
    private function lineFor(Table5Visit $visit): ?Table5Line
    {
        $byCategory = $this->settings->categoryLines[$visit->categoryId] ?? null;
        $id = $byCategory ?? $this->providerLine($visit->providerId)['line'];
        return $id === null ? null : $this->definition->line($this->visitLine($id) ?? '');
    }

    /** A line id if it is a personnel line that reports visits, else null. */
    private function visitLine(?string $id): ?string
    {
        $line = $id === null ? null : $this->definition->line($id);
        return $line !== null && $line->isPersonnel() && $line->visits ? $line->line : null;
    }

    /**
     * A mental health or SUD provider's visit goes by its first diagnosis: an SUD
     * diagnosis makes it an SUD visit (Line 21); otherwise it is a mental health
     * visit, which an SUD specialist's visit reports on Line 20c.
     */
    private function behavioralLine(Table5Line $line, string $diagnosis): Table5Line
    {
        $sud = $this->definition->isSubstanceUse($diagnosis);
        $target = match (true) {
            $sud => Table5Definition::SUBSTANCE_USE_LINE,
            $line->category === Table5Definition::SUBSTANCE_USE => Table5Definition::OTHER_MENTAL_HEALTH_LINE,
            default => $line->line,
        };
        return $this->definition->line($target) ?? $line;
    }

    private function isVirtual(Table5Visit $visit): bool
    {
        if ($visit->placeOfService !== null && in_array($visit->placeOfService, $this->definition->virtualPlaces, true)) {
            return true;
        }
        if (in_array($visit->encounterClass, $this->definition->virtualClasses, true)) {
            return true;
        }
        if (array_intersect($visit->modifiers, $this->definition->virtualModifiers) !== []) {
            return true;
        }
        foreach ($visit->codes as $system => $codes) {
            foreach ($codes as $code) {
                if ($this->definition->isVirtualCode($system, $code)) {
                    return true;
                }
            }
        }
        return false;
    }
}
