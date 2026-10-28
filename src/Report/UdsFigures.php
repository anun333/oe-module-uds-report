<?php

/**
 * The report's tables under HRSA's field codes, the names HRSA's public UDS data
 * download uses (T5_L20_Cc is Table 5, Line 20, Column C), so they can be set beside
 * last year's published report and run through HRSA's checks.
 *
 * Every cell the module computes is given, zeros included; a code that isn't here is
 * a cell the module doesn't compute (Table 5 FTEs, Table 6A lines with no codes, the
 * Table 6B lines with no eCQM, Table 7's births). Table 6A lines the module counts only
 * in part are listed in `partial`, so a check that reads one can say so.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

use OpenEMR\Modules\UdsReport\ReferenceData\Table6aLine;

final readonly class UdsFigures
{
    /** Table 3B's columns as HRSA codes them: the Hispanic subgroups A1-A5 are a1-a5. */
    private const T3B_COLUMN = ['A1' => 'a1', 'A2' => 'a2', 'A3' => 'a3', 'A4' => 'a4', 'A5' => 'a5', 'A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'];

    /** Table 9D's payer lines the worksheet fills. */
    private const T9D_LINES = ['3' => 'Medicaid', '6' => 'Medicare', '9' => 'Other public', '12' => 'Private', '13' => 'Self-pay'];

    /** Table 7's eCQMs and the column group each fills on the total line (Line i). */
    private const T7_COLUMNS = ['CMS165' => '2', 'CMS122' => '3'];

    /**
     * @param array<string, int>    $values  code => value
     * @param array<string, string> $labels  "table|line" => the line's label, for the page
     * @param list<string>          $partial codes of Table 6A lines counted only in part
     */
    private function __construct(public array $values, public array $labels, public array $partial)
    {
    }

    /**
     * @param array{lines: array<int|string, array{label: string, male: int, female: int}>, totalMale: int, totalFemale: int} $t3a
     * @param array{cells: array<int|string, array<string, int>>, line12: int} $t3b
     * @param array{income: array<int|string, int>, insurance: array<int|string, array{child: int, adult: int}>, special: array<int|string, int>} $t4
     * @param array<int|string, string> $raceLabels   Table 3B line => label
     * @param array<int|string, string> $table4Labels Table 4 line => label
     * @param list<array{line: object, visits: ?int, virtual?: ?int, patients: ?int}> $t5Rows  Table 5 rows; line has ->line and ->label
     * @param list<array{line: object, visits: ?int, patients: ?int}> $t6aRows Table 6A rows; line has ->line, ->label and ->computed
     * @param array<string, array{A: int, B: int, C: int}> $t6b Table 6B line => cells, for the lines calculated
     * @param array<string, array{A: int, B: int, C: int}> $t7  measure (CMS165v14, CMS122v14) => its total line's cells
     * @param ?array{lines: array<string, array{charges: int, collections: int, adjustments: int}>, total: array{charges: int}, badDebt: int} $t9d
     *        the Table 9D worksheet's result (Table9DWorksheet::result(), cents), or null for staff who can't see revenue
     */
    public static function from(
        array $t3a,
        array $t3b,
        array $t4,
        array $raceLabels = [],
        array $table4Labels = [],
        array $t5Rows = [],
        array $t6aRows = [],
        array $t6b = [],
        array $t7 = [],
        ?array $t9d = null,
    ): self {
        $v = [];
        $labels = [];
        $partial = [];
        foreach ($t3a['lines'] as $line => $r) {
            $v["T3a_L{$line}_Ca"] = $r['male'];
            $v["T3a_L{$line}_Cb"] = $r['female'];
            $labels["3A|$line"] = $r['label'];
        }
        $v['T3a_L39_Ca'] = $t3a['totalMale'];
        $v['T3a_L39_Cb'] = $t3a['totalFemale'];
        $labels['3A|39'] = 'Total patients';
        foreach ($t3b['cells'] as $line => $row) {
            foreach ($row as $col => $n) {
                if (isset(self::T3B_COLUMN[$col])) {
                    $v["T3b_L{$line}_C" . self::T3B_COLUMN[$col]] = $n;
                }
            }
            if (isset($raceLabels[$line])) {
                $labels["3B|$line"] = $raceLabels[$line];
            }
        }
        $v['T3b_L12_Ca'] = $t3b['line12'];
        foreach ($t4['income'] as $line => $n) {
            $v["T4_L{$line}_Ca"] = $n;
        }
        foreach ($t4['insurance'] as $line => $r) {
            $v["T4_L{$line}_Ca"] = $r['child'];
            $v["T4_L{$line}_Cb"] = $r['adult'];
        }
        foreach ($t4['special'] as $line => $n) {
            $v["T4_L{$line}_Ca"] = $n;
        }
        foreach ($table4Labels as $line => $label) {
            $labels["4|$line"] = $label;
        }
        foreach ($t5Rows as $row) {
            $line = (string)$row['line']->line;
            foreach (['Cb' => $row['visits'], 'Cb2' => $row['virtual'] ?? null, 'Cc' => $row['patients']] as $col => $n) {
                if ($n !== null) {
                    $v["T5_L{$line}_$col"] = $n;
                }
            }
            $labels["5|$line"] = (string)$row['line']->label;
        }
        foreach ($t6aRows as $row) {
            $line = (string)$row['line']->line;
            // HRSA writes the combined Lines 1-2 as "1and2".
            $code = $line === '1-2' ? '1and2' : $line;
            foreach (['Ca' => $row['visits'], 'Cb' => $row['patients']] as $col => $n) {
                if ($n === null) {
                    continue;
                }
                $v["T6a_L{$code}_$col"] = $n;
                if ($row['line']->computed === Table6aLine::COMPUTED_PARTIAL) {
                    $partial[] = "T6a_L{$code}_$col";
                }
            }
            $labels["6A|$code"] = (string)$row['line']->label;
        }
        foreach ($t6b as $line => $c) {
            $v["T6b_L{$line}_Ca"] = $c['A'];
            $v["T6b_L{$line}_Cb"] = $c['B'];
            $v["T6b_L{$line}_Cc"] = $c['C'];
        }
        foreach ($t7 as $measure => $c) {
            $group = self::T7_COLUMNS[preg_replace('/v\d+$/', '', $measure) ?? ''] ?? null;
            if ($group === null) {
                continue;
            }
            $v["T7_Li_C{$group}a"] = $c['A'];
            $v["T7_Li_C{$group}b"] = $c['B'];
            // Hypertension's Column 2c is the patients in control; diabetes splits its numerator over 3c-3f.
            if ($group === '2') {
                $v['T7_Li_C2c'] = $c['C'];
            }
        }
        if ($t9d !== null) {
            // The worksheet in HRSA's columns, in whole dollars. Self-pay's discounts stand for Column E and
            // the worksheet's bad debt write-offs (Line 15 in the 2026 manual) for Column F, as HRSA's checks read them.
            $dollars = static fn(int $cents): int => (int)round($cents / 100);
            foreach (self::T9D_LINES as $line => $label) {
                $line = (string)$line;
                $amounts = $t9d['lines'][$line] ?? null;
                if ($amounts === null) {
                    continue;
                }
                $v["T9d_L{$line}_Ca"] = $dollars($amounts['charges']);
                $v["T9d_L{$line}_Cb"] = $dollars($amounts['collections']);
                $v[$line === '13' ? 'T9d_L13_Ce' : "T9d_L{$line}_Cd"] = $dollars($amounts['adjustments']);
                $labels["9D|$line"] = $label;
            }
            $v['T9d_L13_Cf'] = $dollars($t9d['badDebt']);
            $v['T9d_L14_Ca'] = $dollars($t9d['total']['charges']);
            $labels['9D|14'] = 'Total';
        }
        return new self($v, $labels, $partial);
    }

    /**
     * "Table 5, Line 20, Column C" for T5_L20_Cc, with the line's label when known.
     */
    public function describe(string $code): string
    {
        if (preg_match('/^T(3a|3b|4|5|6a|6b|7|9d)_L([0-9a-z]+)_C([0-9a-z]+)$/D', $code, $m) !== 1) {
            return $code;
        }
        $table = strtoupper($m[1]);
        $line = $m[2] === '1and2' ? '1-2' : $m[2];
        $text = "Table $table, Line $line, Column " . strtoupper($m[3]);
        $label = $this->labels["$table|{$m[2]}"] ?? null;
        return $label === null ? $text : "$text ($label)";
    }
}
