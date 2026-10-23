<?php

/**
 * The service area HRSA's Scope of Project Policy Manual (2026) asks for, from the year's patient
 * origin: it "includes, at a minimum, those ZIP Codes where at least 75 percent of current health
 * center patients reside, based on patient origin data submitted by the health center through
 * HRSA's Health Center Program Uniform Data System" (Chapter 2, page 10, and the definitions).
 *
 * The minimum is the fewest ZIP codes that reach 75% of the patients whose residence ZIP is known:
 * most patients first, ZIP codes with the same count in ZIP order. Unknown residence is left out of
 * both the count and the total, since it can't be placed in any ZIP code. Every ZIP code counts,
 * including those the Patients by ZIP Code table folds into "Other ZIP Codes" (10 or fewer patients).
 *
 * Against the clinic's Form 5B ZIP codes, the minimum's ZIP codes missing from Form 5B are a scope
 * change to request (Chapter 10); Form 5B ZIP codes where no patient lives are listed for
 * information only, since the manual sets a minimum, not a maximum.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final readonly class ServiceArea
{
    public const SHARE = 75.0;

    /**
     * @param list<array{zip: string, patients: int, cumulative: float}> $minimum the ZIP codes reaching SHARE, with each one's running share
     * @param int          $known       patients with a known residence ZIP
     * @param int          $unknown     patients with none
     * @param list<string> $form5b      the clinic's Form 5B ZIP codes, as entered
     * @param list<string> $missing     the minimum's ZIP codes not on Form 5B
     * @param list<string> $noPatients  Form 5B ZIP codes where no patient lives
     * @param int          $inForm5b    patients living in a Form 5B ZIP code
     */
    public function __construct(
        public array $minimum,
        public int $known,
        public int $unknown,
        public array $form5b,
        public array $missing,
        public array $noPatients,
        public int $inForm5b,
    ) {
    }

    /**
     * @param array<string, int> $patientsByZip every known residence ZIP code and its patients
     * @param list<string>       $form5b        the clinic's Form 5B ZIP codes; empty when not entered
     */
    public static function from(array $patientsByZip, int $unknown, array $form5b): self
    {
        $counts = array_filter($patientsByZip, static fn(int $n): bool => $n > 0);
        uksort($counts, static fn(string|int $a, string|int $b): int => [$counts[$b], (string)$a] <=> [$counts[$a], (string)$b]);
        $known = array_sum($counts);
        $minimum = [];
        $running = 0;
        foreach ($counts as $zip => $n) {
            if ($known === 0 || ($minimum !== [] && $running * 100 >= self::SHARE * $known)) {
                break;
            }
            $running += $n;
            $minimum[] = ['zip' => (string)$zip, 'patients' => $n, 'cumulative' => round($running * 100 / $known, 1)];
        }
        $form5b = array_values(array_unique($form5b));
        sort($form5b, SORT_STRING);
        $missing = array_values(array_diff(array_column($minimum, 'zip'), $form5b));
        $noPatients = array_values(array_filter($form5b, static fn(string $z): bool => !isset($counts[$z])));
        $inForm5b = 0;
        foreach ($form5b as $zip) {
            $inForm5b += $counts[$zip] ?? 0;
        }
        return new self($minimum, $known, $unknown, $form5b, $missing, $noPatients, $inForm5b);
    }

    /**
     * Five-digit ZIP codes from what a clinic typed: separated by spaces, commas, semicolons or
     * lines; ZIP+4 keeps its first five digits. Anything else is returned separately to show.
     *
     * @return array{zips: list<string>, rejected: list<string>}
     */
    public static function parse(string $text): array
    {
        $zips = [];
        $rejected = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^(\d{5})(?:-\d{4})?$/', $token, $m) === 1) {
                $zips[$m[1]] = true;
            } else {
                $rejected[] = $token;
            }
        }
        $zips = array_map('strval', array_keys($zips));
        sort($zips, SORT_STRING);
        return ['zips' => $zips, 'rejected' => $rejected];
    }
}
