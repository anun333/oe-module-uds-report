<?php

/**
 * Generates FOOTPRINT.md: what this module adds to an OpenEMR install, what it
 * reads and writes, and what it hooks — extracted from the source, so the sheet
 * cannot drift from the code.
 *
 *     php tools/footprint.php            write FOOTPRINT.md
 *     php tools/footprint.php --check    exit 1 if FOOTPRINT.md is stale
 *
 * SQL is found with PHP's own tokenizer, in string literals only, and only in
 * strings that begin with a SQL verb, so `$from`, `<select>` and prose such as
 * "from %d to %d" are never read as queries. Each extraction runs a self-test
 * before it is trusted: a probe that finds nothing looks the same as a module
 * that does nothing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$out = $root . '/FOOTPRINT.md';

/** @return list<string> every string literal in a PHP source, interpolations replaced by `?` */
function stringLiterals(string $php): array
{
    $strings = [];
    $current = null;
    foreach (token_get_all($php) as $tok) {
        if ($current !== null) {
            if ($tok === '"' || (is_array($tok) && $tok[0] === T_END_HEREDOC)) {
                $strings[] = $current;
                $current = null;
            } elseif (is_array($tok) && $tok[0] === T_ENCAPSED_AND_WHITESPACE) {
                $current .= $tok[1];
            } else {
                $current .= '?';
            }
            continue;
        }
        if ($tok === '"' || (is_array($tok) && $tok[0] === T_START_HEREDOC)) {
            $current = '';
        } elseif (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = substr($tok[1], 1, -1);
        } elseif (is_array($tok) && $tok[0] === T_ENCAPSED_AND_WHITESPACE) {
            $strings[] = $tok[1];
        }
    }
    return $strings;
}

/** @return array{reads: list<string>, writes: list<string>}|null */
function sqlTables(string $s): ?array
{
    $s = trim($s);
    if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $s, $m)) {
        return null;
    }
    $id = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
    $verb = strtoupper($m[1]);
    $writes = [];
    if ($verb === 'INSERT' || $verb === 'REPLACE') {
        preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    } elseif ($verb === 'UPDATE') {
        preg_match('/^UPDATE\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    } elseif ($verb === 'DELETE') {
        preg_match('/^DELETE\s+FROM\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    }
    preg_match_all('/\b(?:FROM|JOIN)\s+' . $id . '/i', $s, $r);
    $reads = array_values(array_diff(array_unique($r[1]), $verb === 'DELETE' ? $writes : []));
    return ['reads' => $reads, 'writes' => $writes];
}

function selfTest(): void
{
    $sample = <<<'PHP'
    <?php
    // SELECT x FROM not_a_query_in_a_comment
    $from = 1; $a = "<select>"; $b = sprintf('from %d to %d', 1, 2);
    $q = "SELECT a FROM t1 JOIN t2 ON t1.id = t2.id WHERE x IN ($in)";
    $w = 'INSERT INTO `t3` (a) VALUES (?) ON DUPLICATE KEY UPDATE a = VALUES(a)';
    $d = 'DELETE FROM `t4` WHERE id = ?';
    PHP;
    $found = array_values(array_filter(array_map('sqlTables', stringLiterals($sample))));
    $reads = array_merge(...array_column($found, 'reads'));
    $writes = array_merge(...array_column($found, 'writes'));
    sort($reads);
    sort($writes);
    if ($reads !== ['t1', 't2'] || $writes !== ['t3', 't4']) {
        fwrite(STDERR, "SQL extractor self-test failed: reads=" . json_encode($reads) . " writes=" . json_encode($writes) . "\n");
        exit(2);
    }
}

/** @return list<string> */
function phpFiles(string $root): array
{
    $files = [$root . '/openemr.bootstrap.php'];
    foreach (['src', 'public'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

function fail(string $why): never
{
    fwrite(STDERR, "footprint: $why\n");
    exit(2);
}

selfTest();

// --- package identity -------------------------------------------------------
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
if (!is_array($composer) || !is_string($composer['name'] ?? null)) {
    fail('composer.json has no name');
}
$name = $composer['name'];
$parts = explode('/', $name);
$installDir = 'interface/modules/custom_modules/' . end($parts);
include $root . '/version.php';
$version = "$v_major.$v_minor.$v_patch" . ($v_tag !== '' ? "-$v_tag" : '');

// --- install-time SQL -------------------------------------------------------
// Statement by statement, and every statement must be classified: the sheet
// claims to list everything install.sql does, so an unhandled statement kind is
// a failure, not something to leave out.
$install = (string) file_get_contents($root . '/sql/install.sql');
// Every statement must start inside an #If...#EndIf guard, or the "re-running
// changes nothing" line below would be a claim rather than a fact.
$depth = 0;
$unguarded = [];
$inStatement = false;
foreach (explode("\n", $install) as $n => $line) {
    $t = trim($line);
    if (str_starts_with($t, '#If')) {
        $depth++;
    } elseif (str_starts_with($t, '#EndIf')) {
        $depth--;
    } elseif ($t !== '' && !str_starts_with($t, '--') && !str_starts_with($t, '#')) {
        if (!$inStatement && $depth === 0) {
            $unguarded[] = $n + 1;
        }
        $inStatement = !str_ends_with($t, ';');
    }
}
$body = preg_replace('/^\s*(--|#)[^\n]*$/m', '', $install) ?? '';
$statements = array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $body) ?: []), fn($x) => $x !== ''));
if ($statements === []) {
    fail('no statements parsed from sql/install.sql');
}
$ownTables = [];
$alters = [];
$inserts = [];
$id = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
foreach ($statements as $st) {
    if (preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?' . $id . '/i', $st, $m)) {
        $ownTables[] = $m[1];
    } elseif (preg_match('/^ALTER TABLE\s+' . $id . '\s+ADD\s+(?:COLUMN\s+)?' . $id . '\s+([^\n,]+)/i', $st, $m)) {
        $alters[$m[1]][] = ['column' => $m[2], 'type' => trim($m[3])];
    } elseif (preg_match('/^INSERT INTO\s+' . $id . '/i', $st, $m)) {
        $inserts[$m[1]][] = $st;
    } else {
        fail('unclassified statement in sql/install.sql: ' . substr(preg_replace('/\s+/', ' ', $st) ?? '', 0, 80));
    }
}
if ($ownTables === []) {
    fail('no CREATE TABLE found in sql/install.sql');
}
$coreAlters = array_diff_key($alters, array_flip($ownTables));
$installWrites = array_values(array_diff(array_keys($inserts), $ownTables));
$newLists = [];
$listRows = 0;
foreach ($inserts['list_options'] ?? [] as $st) {
    preg_match_all("/\(\s*'([^']*)'\s*,\s*'([^']*)'/", $st, $t, PREG_SET_ORDER);
    foreach ($t as $row) {
        $listRows++;
        if ($row[1] === 'lists') {
            $newLists[] = $row[2];
        }
    }
}
if (isset($inserts['list_options']) && $listRows === 0) {
    fail('list_options is written but no value tuples were parsed');
}
$layoutFields = [];
foreach ($inserts['layout_options'] ?? [] as $st) {
    preg_match_all("/VALUES\s*\(\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'/i", $st, $t, PREG_SET_ORDER);
    foreach ($t as $row) {
        $layoutFields[] = ['form' => $row[1], 'field' => $row[2], 'group' => $row[3], 'title' => $row[4]];
    }
}
if (isset($inserts['layout_options']) && $layoutFields === []) {
    fail('layout_options is written but no fields were parsed');
}
$sqlFiles = array_map('basename', glob($root . '/sql/*.sql') ?: []);
$uninstall = array_values(array_filter($sqlFiles, fn($f) => preg_match('/uninstall|cleanup|remove/i', $f)));

// --- runtime SQL ------------------------------------------------------------
$reads = [];
$writes = [];
$queries = 0;
foreach (phpFiles($root) as $file) {
    foreach (stringLiterals((string) file_get_contents($file)) as $s) {
        $t = sqlTables($s);
        if ($t === null) {
            continue;
        }
        $queries++;
        $rel = substr($file, strlen($root) + 1);
        foreach ($t['reads'] as $x) {
            $reads[$x][$rel] = true;
        }
        foreach ($t['writes'] as $x) {
            $writes[$x][$rel] = true;
        }
    }
}
if ($queries === 0) {
    fail('no SQL found in src/ or public/; the extractor or the layout changed');
}
ksort($reads);
ksort($writes);
$coreWrites = array_diff(array_keys($writes), $ownTables);

// --- hooks ------------------------------------------------------------------
$bootstrap = (string) file_get_contents($root . '/src/Bootstrap.php');
preg_match_all('/addListener\(\s*([A-Za-z_\\\\]+::[A-Z_]+)/', $bootstrap, $m);
$hooks = $m[1];
if ($hooks === [] && str_contains($bootstrap, 'addListener')) {
    fail('addListener present but no event parsed');
}

// --- outbound network -------------------------------------------------------
$netPattern = '/\b(curl_init|fsockopen|stream_socket_client|GuzzleHttp|HttpClient|socket_connect)\b|file_get_contents\(\s*[\'"]https?:/';
if (!preg_match($netPattern, 'curl_init(') || !preg_match($netPattern, "file_get_contents('https://x')")) {
    fail('network detector self-test failed');
}
$net = [];
foreach (phpFiles($root) as $file) {
    if (preg_match_all($netPattern, (string) file_get_contents($file), $m)) {
        $net[substr($file, strlen($root) + 1)] = array_unique($m[0]);
    }
}

// --- composition ------------------------------------------------------------
$kinds = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    // Documentation and licence text change nothing a reviewer must check, and
    // counting them would make every doc edit fail --check.
    if (preg_match('#^(\.git|\.github|vendor|\.phpunit\.cache)/#', $rel) || str_ends_with($rel, '.md')
        || in_array($rel, ['composer.lock', 'LICENSE', '.gitignore'], true)) {
        continue;
    }
    $kind = match (true) {
        str_starts_with($rel, 'src/') => 'PHP logic (`src/`)',
        str_starts_with($rel, 'public/') => 'pages (`public/`)',
        str_starts_with($rel, 'data/cases/') => 'expected results (`data/cases/`)',
        str_starts_with($rel, 'data/') => 'reference data (`data/`)',
        str_starts_with($rel, 'tests/') => 'tests (`tests/`)',
        str_starts_with($rel, 'sql/') => 'install SQL (`sql/`)',
        str_starts_with($rel, 'tools/') => 'tools (`tools/`)',
        default => 'other',
    };
    $kinds[$kind]['files'] = ($kinds[$kind]['files'] ?? 0) + 1;
    $kinds[$kind]['lines'] = ($kinds[$kind]['lines'] ?? 0) + count(file($f->getPathname()) ?: []);
}
uasort($kinds, fn($a, $b) => $b['lines'] <=> $a['lines']);

// --- render -----------------------------------------------------------------
$md = [];
$md[] = '# Footprint';
$md[] = '';
$md[] = "Generated by `tools/footprint.php` from the source of `$name` $version. Do not edit; run `php tools/footprint.php` after changing the module, and `--check` in CI.";
$md[] = '';
$md[] = '## Where it installs';
$md[] = '';
$md[] = "`composer require $name` places it in `$installDir/` (the `openemr/oe-module-installer-plugin` rule: `custom_modules/` plus the part of the package name after the slash). It changes no OpenEMR core file. Enable it under Modules > Manage Modules.";
$md[] = '';
$md[] = '## What installing it changes in the database';
$md[] = '';
$md[] = count($statements) . ' statements in `sql/install.sql`, every one classified below. ' . ($unguarded === []
    ? 'Each sits inside an `#If…` guard (`#IfNotTable`, `#IfMissingColumn`, `#IfNotRow2D`), so re-running the install changes nothing.'
    : '**Not guarded, so a re-run would repeat them:** statements starting on line ' . implode(', ', $unguarded) . '.');
$md[] = '';
if ($coreAlters === []) {
    $md[] = '- **Core schema changes:** none.';
} else {
    foreach ($coreAlters as $t => $cols) {
        $md[] = "- **Core schema change — adds " . count($cols) . " columns to OpenEMR's `$t` table:** " . implode(', ', array_map(fn($c) => "`{$c['column']}` ({$c['type']})", $cols)) . '.';
    }
}
$md[] = '- **Tables it creates and owns:** ' . implode(', ', array_map(fn($t) => "`$t`", $ownTables)) . '.';
if ($installWrites === []) {
    $md[] = '- **Rows added to core tables:** none.';
} else {
    $md[] = '- **Rows added to core tables:** ' . implode(', ', array_map(fn($t) => "`$t`", $installWrites)) . '.';
    if ($newLists !== []) {
        $md[] = "  - `list_options`: $listRows rows defining " . count($newLists) . ' new lists, all `uds_`-prefixed: ' . implode(', ', array_map(fn($l) => "`$l`", $newLists)) . '.';
    }
    if ($layoutFields !== []) {
        $forms = array_unique(array_column($layoutFields, 'form'));
        $md[] = '  - `layout_options`: ' . count($layoutFields) . ' fields added to the ' . implode(', ', array_map(fn($f) => "`$f`", $forms)) . ' layout' . ($forms === ['DEM'] ? ' (patient Demographics), so every user of that form sees them' : '') . ': ' . implode(', ', array_map(fn($f) => "{$f['title']} (`{$f['field']}`, group {$f['group']})", $layoutFields)) . '.';
    }
}
$md[] = '';
$md[] = $uninstall === []
    ? '- **No uninstall script** (`sql/` holds only ' . implode(', ', array_map(fn($f) => "`$f`", $sqlFiles)) . '): disabling or removing the module leaves these columns, fields, lists and tables in place, along with any data entered in them.'
    : '- **Uninstall script:** ' . implode(', ', array_map(fn($f) => "`$f`", $uninstall)) . '.';
$md[] = '';
$md[] = '## What it reads and writes at runtime';
$md[] = '';
$md[] = "$queries SQL statements found in `src/` and `public/`.";
$md[] = '';
$md[] = '| table | read by | written by | owner |';
$md[] = '|---|---|---|---|';
foreach (array_unique(array_merge(array_keys($reads), array_keys($writes))) as $t) {
    $r = isset($reads[$t]) ? implode('<br>', array_map(fn($f) => "`$f`", array_keys($reads[$t]))) : '—';
    $w = isset($writes[$t]) ? implode('<br>', array_map(fn($f) => "`$f`", array_keys($writes[$t]))) : '—';
    $owner = in_array($t, $ownTables, true) ? 'this module' : 'OpenEMR core';
    $md[] = "| `$t` | $r | $w | $owner |";
}
$md[] = '';
$md[] = $coreWrites === []
    ? '**It writes only to its own tables.** Every OpenEMR core table it touches at runtime is read-only to it.'
    : '**It writes to OpenEMR core tables:** ' . implode(', ', array_map(fn($t) => "`$t`", $coreWrites)) . '.';
$md[] = '';
$md[] = '## What it hooks';
$md[] = '';
$md[] = $hooks === [] ? '- No event listeners.' : implode("\n", array_map(fn($h) => "- `$h`", $hooks));
$md[] = '';
$md[] = '## Outbound network';
$md[] = '';
$md[] = $net === []
    ? '- None: no cURL, sockets, HTTP clients or remote `file_get_contents` in `src/` or `public/`.'
    : implode("\n", array_map(fn($f, $c) => "- `$f`: " . implode(', ', $c), array_keys($net), $net));
$md[] = '';
$md[] = '## Size, by what a reviewer does with it';
$md[] = '';
$md[] = '| part | files | lines |';
$md[] = '|---|---|---|';
foreach ($kinds as $k => $v) {
    $md[] = "| $k | {$v['files']} | {$v['lines']} |";
}
$md[] = '';
$md[] = '## Not covered here';
$md[] = '';
$md[] = '- Access checks on the report pages.';
$md[] = '- Whether the counting rules match the UDS Manual. The conformance cases in `data/cases/` and `tests/` cover that, and real-world validation against a health center\'s filed report is still to come.';
$md[] = '';
$text = implode("\n", $md);

if (in_array('--check', $argv, true)) {
    $current = is_file($out) ? (string) file_get_contents($out) : '';
    if ($current !== $text) {
        fwrite(STDERR, "FOOTPRINT.md is stale; run php tools/footprint.php\n");
        exit(1);
    }
    echo "FOOTPRINT.md is current\n";
    exit(0);
}
file_put_contents($out, $text);
echo "wrote FOOTPRINT.md ($queries queries, " . count($reads) . " tables read, " . count($writes) . " written)\n";
