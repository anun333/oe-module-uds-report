<?php

/**
 * What a site's layouts and lists mean for the UDS Report module, from a layouts-and-lists file
 * alone (no patient data): which Demographics fields the module reads are there and switched on,
 * whether the race and ethnicity entries carry the CDC codes the module rolls up to Table 3B, which
 * languages count toward limited English proficiency, which other forms record UDS information
 * the module doesn't read, and what the file can't show (insurers, sites, visit categories).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tools\SetupCheck;

use OpenEMR\Modules\UdsReport\ReferenceData\RaceEthnicity;

final class SetupCheck
{
    public const OK = 'ok';
    public const LOOK = 'look';
    public const ABSENT = 'not in file';
    public const INFO = 'info';

    /** The patient_data columns the module reads (src/Repository/UdsPatientRepository.php), and what for. */
    private const DEMOGRAPHICS = [
        'sex' => 'Table 3A: sex at birth. Only Male and Female are counted; anyone else is a blocking problem',
        'DOB' => 'Table 3A: age on December 31',
        'race' => 'Table 3B, Lines 1a-7: rolled up from each entry\'s CDC code',
        'ethnicity' => 'Table 3B, Columns A1-C: rolled up from each entry\'s CDC code',
        'language' => 'Table 3B, Line 12: any language but English (or blank or declined) counts as limited English proficiency',
        'interpreter_needed' => 'Table 3B, Line 12: Yes counts as limited English proficiency (OpenEMR 7.0.4 and later)',
        'postal_code' => 'Patients by ZIP Code table: the first five digits',
        'monthly_income' => 'Table 4, Lines 1-6: one amount a month',
        'family_size' => 'Table 4, Lines 1-6: a whole number',
        'financial_review' => 'Table 4, Lines 1-6: income counts only when reviewed at or within 12 months before the last visit',
        'homeless' => 'not counted (free text); Records to fix names patients with something here and no Housing Status (UDS)',
        'migrantseasonal' => 'not counted (free text); Records to fix names patients with something here and no Agricultural Worker (UDS)',
    ];
    /** Fields the module adds to Demographics when installed (sql/install.sql). */
    private const MODULE_FIELDS = [
        'uds_housing_status' => 'Housing Status (UDS), Table 4 Lines 17-23',
        'uds_agricultural_worker' => 'Agricultural Worker (UDS), Table 4 Lines 14-16',
        'uds_veteran_status' => 'Military Service (UDS), Table 4 Line 25',
    ];
    /** Language entries that don't count as limited English proficiency (UdsPatientRepository::NOT_LIMITED_ENGLISH). */
    private const ENGLISH = ['english', 'decline_to_specify', 'declne_to_specfy'];
    /** Words that suggest a field elsewhere records what the UDS asks, by topic. */
    private const TOPICS = [
        'income' => ['/income|salary|wage|earning/i', 'Table 4 reads income only from Demographics: Monthly Income, Family Size and Financial Review Date'],
        'household size' => ['/family.?size|household.?size|in household|household members|dependents/i', 'Table 4 reads Family Size from Demographics only'],
        'housing' => ['/homeless|housing|shelter|living situation/i', 'Table 4 reads Housing Status (UDS), which the module adds to Demographics'],
        'agricultural work' => ['/agricultur|farm|migrant|seasonal/i', 'Table 4 reads Agricultural Worker (UDS), which the module adds to Demographics'],
        'veteran status' => ['/veteran|military|armed forces|uniformed/i', 'Table 4 reads Military Service (UDS), which the module adds to Demographics'],
        'race or ethnicity' => ['/\brace\b|ethnic/i', 'Table 3B reads race and ethnicity from Demographics only'],
        'language' => ['/language|interpret/i', 'Table 3B reads Language (and Interpreter from 7.0.4) from Demographics only'],
        'insurance' => ['/insur|payer|micare|medicaid|medicare/i', 'Table 4 reads insurance from the patient\'s insurance records (Insurance tab), not form fields'],
        'sexual orientation or gender identity' => ['/sexual.?orientation|gender.?identity/i', 'the 2026 UDS Manual has no sexual orientation or gender identity lines'],
    ];

    /** @var list<array{section: string, status: string, text: string}> */
    private array $findings = [];

    public function __construct(private readonly ConfigFile $file, private readonly RaceEthnicity $races)
    {
    }

    /** @return list<array{section: string, status: string, text: string}> */
    public function findings(): array
    {
        $this->findings = [];
        $this->demographics();
        $this->raceList();
        $this->ethnicityList();
        $this->languageList();
        $this->sexList();
        $this->otherForms();
        $this->notInFile();
        return $this->findings;
    }

    public function markdown(string $name): string
    {
        $findings = $this->findings();
        $count = static fn(string $status): int => count(array_filter($findings, static fn(array $f): bool => $f['status'] === $status));
        $lines = ['# UDS setup check: ' . $name, ''];
        $lines[] = sprintf(
            'Read %s: %d layouts (%s) and %d lists%s. It holds layout and list rows only; a file with anything else in it is refused, so no patient data is read.',
            $this->file->kind,
            count($this->file->layouts),
            implode(', ', array_keys($this->file->layouts)) ?: 'none',
            count($this->file->lists),
            $this->file->openemr !== '' ? ', from OpenEMR ' . $this->file->openemr : '',
        );
        $lines[] = '';
        $lines[] = sprintf('**%d to look at**, %d fine, %d for information, %d not in this file.', $count(self::LOOK), $count(self::OK), $count(self::INFO), $count(self::ABSENT));
        $section = '';
        foreach ($findings as $f) {
            if ($f['section'] !== $section) {
                $section = $f['section'];
                $lines[] = '';
                $lines[] = '## ' . $section;
                $lines[] = '';
            }
            $lines[] = sprintf('- **%s**: %s', $f['status'], $f['text']);
        }
        return implode("\n", $lines) . "\n";
    }

    private function add(string $section, string $status, string $text): void
    {
        $this->findings[] = ['section' => $section, 'status' => $status, 'text' => $text];
    }

    private function demographics(): void
    {
        $s = 'Demographics fields the UDS module reads';
        if (!isset($this->file->layouts['DEM'])) {
            $this->add($s, self::ABSENT, 'The Demographics layout (DEM) is not in the file. Export it, with every layout, from the Backup page.');
            return;
        }
        $fields = [];
        foreach ($this->file->layouts['DEM']['fields'] as $f) {
            $fields[(string)$f['field_id']] = $f;
        }
        foreach (self::DEMOGRAPHICS as $id => $use) {
            $f = $fields[$id] ?? null;
            if ($id === 'interpreter_needed' && $f === null) {
                if (isset($fields['interpretter'])) {
                    $this->add($s, self::INFO, 'Interpreter: this OpenEMR has only the free-text Interpreter field (`interpretter`, before 7.0.4). Upgrading copies "yes" and "no" into Interpreter Needed and anything else as unknown (sql/7_0_3-to-7_0_4_upgrade.sql); only yes counts toward Line 12.');
                } else {
                    $this->add($s, self::LOOK, 'Interpreter Needed (`interpreter_needed`) is not on Demographics: ' . $use . '.');
                }
                continue;
            }
            if ($f === null) {
                $this->add($s, self::LOOK, sprintf('`%s` is not on Demographics, so staff can\'t record it there: %s.', $id, $use));
                continue;
            }
            $label = sprintf('%s (`%s`)', trim((string)$f['title']) !== '' ? trim((string)$f['title']) : $id, $id);
            if ((int)$f['uor'] === 0) {
                $this->add($s, self::LOOK, sprintf('%s is turned off on Demographics (Unused in the layout editor), so staff can\'t see or record it: %s.', $label, $use));
                continue;
            }
            $notes = [];
            if ($id === 'financial_review' && (int)$f['data_type'] === 2) {
                $notes[] = 'it is a plain text box, and a date typed any way but YYYY-MM-DD is silently stored as no date (Records to fix names these patients)';
            }
            $list = trim((string)($f['list_id'] ?? ''));
            $stock = ['sex' => 'sex', 'race' => 'race', 'ethnicity' => 'ethnicity', 'language' => 'language', 'interpreter_needed' => 'yes_no_unknown'];
            if (isset($stock[$id]) && $list !== $stock[$id]) {
                $this->add($s, self::LOOK, sprintf('%s uses the list `%s`, not `%s`; the module reads the codes of `%s`.', $label, $list, $stock[$id], $stock[$id]));
                continue;
            }
            $this->add($s, in_array($id, ['homeless', 'migrantseasonal'], true) ? self::INFO : self::OK, $label . ': ' . $use . ($notes ? '; ' . implode('; ', $notes) : '') . '.');
        }
        foreach (self::MODULE_FIELDS as $id => $what) {
            $this->add($s, isset($fields[$id]) ? self::OK : self::INFO, isset($fields[$id])
                ? $what . ': on Demographics.'
                : $what . ': not there yet; installing the module adds it to the Stats group.');
        }
        foreach (['sexual_orientation', 'gender_identity'] as $id) {
            if (isset($fields[$id]) && (int)$fields[$id]['uor'] > 0) {
                $this->add($s, self::INFO, sprintf('`%s` is on Demographics; the 2026 UDS Manual has no sexual orientation or gender identity lines, so the module doesn\'t read it.', $id));
            }
        }
    }

    /** @return list<array<string, ?string>> the list's active options, or null when the list isn't in the file */
    private function options(string $listId): ?array
    {
        if (!isset($this->file->lists[$listId])) {
            return null;
        }
        return array_values(array_filter($this->file->lists[$listId]['options'], static fn(array $o): bool => (string)($o['activity'] ?? '1') !== '0'));
    }

    private static function name(array $o): string
    {
        return sprintf('%s (`%s`)', trim((string)$o['title']), (string)$o['option_id']);
    }

    private static function code(array $o): ?string
    {
        $notes = trim((string)($o['notes'] ?? ''));
        return preg_match('/^\d{4}-\d$/D', $notes) === 1 ? $notes : null;
    }

    private function raceList(): void
    {
        $s = 'Race (Table 3B, Lines 1a-7)';
        $options = $this->options('race');
        if ($options === null) {
            $this->add($s, self::ABSENT, 'The race list is not in the file. On the Backup page, select every list, or at least Race.');
            return;
        }
        $byLine = [];
        $uncoded = [];
        $unrolled = [];
        foreach ($options as $o) {
            $code = self::code($o);
            if ($code === null) {
                if (!in_array($o['option_id'], ['declne_to_specfy', 'decline_to_specify'], true)) {
                    $uncoded[] = self::name($o);
                }
                continue;
            }
            $line = $this->races->raceLine([$code]);
            if ($line === '7') {
                $unrolled[] = self::name($o) . ' ' . $code;
                continue;
            }
            $byLine[$line][] = trim((string)$o['title']);
        }
        ksort($byLine, SORT_NATURAL);
        $parts = [];
        foreach ($byLine as $line => $titles) {
            $parts[] = sprintf('Line %s: %s', $line, implode(', ', $titles));
        }
        $this->add($s, self::OK, sprintf('%d active entries carry a CDC race code: %s.', count($options) - count($uncoded), implode('; ', $parts) ?: 'none'));
        if ($uncoded !== []) {
            $this->add($s, self::LOOK, sprintf(
                'No CDC race code in the entry\'s Notes: %s. A patient with only this race is counted as race unreported (Line 7). Put the code in Notes in Administration > Lists (for example 2500-7, Other Pacific Islander, Line 2b; or the people\'s own code if CDC lists one).',
                implode(', ', $uncoded),
            ));
        }
        if ($unrolled !== []) {
            $this->add($s, self::INFO, sprintf('Codes that roll up to no race line (counted as unreported, Line 7): %s.', implode(', ', $unrolled)));
        }
    }

    private function ethnicityList(): void
    {
        $s = 'Ethnicity (Table 3B, Columns A1-C)';
        $options = $this->options('ethnicity');
        if ($options === null) {
            $this->add($s, self::ABSENT, 'The ethnicity list is not in the file.');
            return;
        }
        $parts = [];
        $uncoded = [];
        foreach ($options as $o) {
            $code = self::code($o);
            $column = $code === null ? null : $this->races->ethnicityColumn([$code]);
            if ($column === null) {
                if (!in_array($o['option_id'], ['declne_to_specfy', 'decline_to_specify'], true)) {
                    $uncoded[] = self::name($o);
                }
                continue;
            }
            $parts[] = sprintf('%s: Column %s', trim((string)$o['title']), $column);
        }
        $this->add($s, self::OK, implode('; ', $parts) . '. A patient with a race and no ethnicity is counted as not Hispanic (Column B).');
        if ($uncoded !== []) {
            $this->add($s, self::LOOK, 'No CDC ethnicity code in Notes: ' . implode(', ', $uncoded) . '. Patients with only this answer are counted as if ethnicity were not answered.');
        }
    }

    private function languageList(): void
    {
        $s = 'Language (Table 3B, Line 12)';
        $options = $this->options('language');
        if ($options === null) {
            $this->add($s, self::ABSENT, 'The language list is not in the file.');
            return;
        }
        $english = [];
        $lep = [];
        $suspect = [];
        foreach ($options as $o) {
            $id = strtolower((string)$o['option_id']);
            if (in_array($id, self::ENGLISH, true)) {
                $english[] = self::name($o);
            } else {
                $lep[] = trim((string)$o['title']);
                if (stripos((string)$o['title'], 'english') !== false) {
                    $suspect[] = self::name($o);
                }
            }
        }
        $this->add($s, self::OK, sprintf('Counted as English (not limited English proficiency): %s, and a blank language. The other %d active languages in the list count toward Line 12, as the manual asks even where English isn\'t the main language, "such as Puerto Rico or the Pacific Islands" (2026 UDS Manual, page 32).', implode(', ', $english) ?: 'none', count($lep)));
        $fsm = array_values(array_filter($lep, static fn(string $t): bool => preg_match('/pohnpei|chuuk|kosrae|yap|marshall|palau|mortlock|ponape|truk/i', $t) === 1));
        if ($fsm !== []) {
            $this->add($s, self::INFO, 'Micronesian languages in the list: ' . implode(', ', $fsm) . '.');
        }
        if ($english === []) {
            $this->add($s, self::LOOK, 'No entry with the id English, so every patient with a language recorded counts toward Line 12.');
        }
        if ($suspect !== []) {
            $this->add($s, self::LOOK, 'Named English but with another id, so counted as limited English proficiency: ' . implode(', ', $suspect) . '.');
        }
    }

    private function sexList(): void
    {
        $s = 'Sex (Table 3A)';
        $options = $this->options('sex');
        if ($options === null) {
            $this->add($s, self::ABSENT, 'The sex list is not in the file (the Backup page leaves it out of "lists referenced in chosen layouts"; select it by name).');
            return;
        }
        $ids = array_map(static fn(array $o): string => (string)$o['option_id'], $options);
        $missing = array_diff(['Male', 'Female'], $ids);
        $this->add($s, $missing === [] ? self::OK : self::LOOK, $missing === []
            ? 'Male and Female are there. Patients recorded with anything else (' . (implode(', ', array_diff($ids, ['Male', 'Female'])) ?: 'nothing else is in the list') . ') are listed as a blocking problem, because Table 3A has no other column.'
            : 'The list has no entry with the id ' . implode(' or ', $missing) . '; the module counts only the ids Male and Female.');
    }

    private function otherForms(): void
    {
        $s = 'Other forms that record UDS information';
        $found = 0;
        foreach ($this->file->layouts as $formId => $layout) {
            if ($formId === 'DEM') {
                continue;
            }
            $title = '';
            foreach ($layout['properties'] as $p) {
                if ((string)($p['grp_group_id'] ?? '') === '') {
                    $title = trim((string)$p['grp_title']);
                }
            }
            foreach ($layout['fields'] as $f) {
                if ((int)($f['uor'] ?? 1) === 0) {
                    continue;
                }
                $id = (string)$f['field_id'];
                $hay = implode(' ', [$id, (string)$f['title'], (string)($f['description'] ?? ''), (string)($f['list_id'] ?? '')]);
                foreach (self::TOPICS as $topic => [$pattern, $why]) {
                    if (preg_match($pattern, $hay) !== 1) {
                        continue;
                    }
                    $source = (string)($f['source'] ?? '');
                    $saved = $source === 'D' ? (isset(self::DEMOGRAPHICS[$id]) || isset(self::MODULE_FIELDS[$id]) ? ' It is saved to Demographics (source D) under that name, so the module reads it.' : ' It is saved to Demographics (source D), but under a name the module doesn\'t read.') : '';
                    $this->add($s, $saved !== '' && str_contains($saved, 'reads it') ? self::OK : ($topic === 'sexual orientation or gender identity' ? self::INFO : self::LOOK), sprintf(
                        '%s%s, field %s "%s" looks like %s: %s.%s',
                        $formId,
                        $title !== '' ? ' "' . $title . '"' : '',
                        $id,
                        trim((string)$f['title']),
                        $topic,
                        $why,
                        $saved,
                    ));
                    $found++;
                    break;
                }
            }
        }
        if ($found === 0) {
            $this->add($s, self::OK, 'No field in the other layouts looks like income, household size, housing, agricultural work, veteran status, race, language or insurance.');
        }
    }

    private function notInFile(): void
    {
        $s = 'Not in this file (checked on the rehearsal copy)';
        $this->add($s, self::ABSENT, 'Insurance companies and their UDS insurance category. The Backup page doesn\'t export insurers (Administration > Practice > Insurance Companies). On the copy, the module\'s Settings page lists each insurer with the category it would use; one it can\'t decide is a blocking problem until set. A national or government plan such as FSM MiCare is set to Other Public there (Table 4, Line 10a).');
        $this->add($s, self::ABSENT, 'Sites (facilities) and their ZIP codes, used for homeless patients without a ZIP and for school-based and public housing sites; visit categories that count; each provider\'s Table 5 line; the poverty-guideline region; the HRSA grant number.');
        $this->add($s, self::ABSENT, 'Whether patients\' records are filled in: the copy\'s Records to fix list counts that.');
    }
}
