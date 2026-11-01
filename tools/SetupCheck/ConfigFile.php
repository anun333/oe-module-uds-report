<?php

/**
 * A site's layouts and lists, read from a file as data and never run: the SQL file OpenEMR's old
 * Administration > Backup page wrote (Export Configuration, up to 8.2), or a Layout Export JSON or
 * zip file (anun333/oe-module-layout-export). Neither holds patient data.
 *
 * The SQL is read by the same rules as Layout Export's BackupSqlReader: only INSERTs into
 * layout_group_properties, layout_options and list_options, the DELETEs backup.php wrote for them,
 * the comments, SET, LOCK TABLES and transaction statements mysqldump adds, and backup.php's
 * column repair for Demographics and History (skipped). Anything else refuses the whole file, so
 * a file holding any other table, patient rows included, is never read.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Tools\SetupCheck;

final class ConfigFile
{
    private const TABLES = ['layout_group_properties', 'layout_options', 'list_options'];
    private const IDENT = '`?([A-Za-z0-9_]+)`?';

    /**
     * @param array<string, array{properties: list<array<string, ?string>>, fields: list<array<string, ?string>>}> $layouts by form id
     * @param array<string, array{list: ?array<string, ?string>, options: list<array<string, ?string>>}>           $lists   by list id
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $layouts,
        public readonly array $lists,
        public readonly string $openemr,
    ) {
    }

    /** @throws \RuntimeException when the file isn't one of the three kinds, with the reason */
    public static function read(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Can't read $path.");
        }
        $content = (string)file_get_contents($path);
        if (str_starts_with($content, "PK\x03\x04")) {
            return self::fromZip($path);
        }
        if (str_starts_with($content, "\x1F\x8B")) {
            $content = (string)gzdecode($content);
        }
        if (str_starts_with(ltrim($content), '{')) {
            return self::fromJson($content);
        }
        return self::fromSql($content);
    }

    public static function fromSql(string $sql): self
    {
        $rows = array_fill_keys(self::TABLES, []);
        $layoutIds = [];
        $listIds = [];
        foreach (self::statements($sql) as $n => $statement) {
            $s = trim($statement);
            $n++;
            if (
                $s === '' || preg_match('/^SET\s/i', $s) === 1
                || preg_match('/^(LOCK TABLES\s+' . self::IDENT . '\s+WRITE|UNLOCK TABLES|COMMIT|BEGIN|START TRANSACTION)$/i', $s) === 1
                || preg_match("/^SELECT CONCAT\\(\\s*'ALTER TABLE (patient_data|history_data) '.*INTO @sql$/is", $s) === 1
                || preg_match('/^(PREPARE stmt FROM @sql|EXECUTE stmt)$/i', $s) === 1
            ) {
                continue;
            }
            if (preg_match('/^DELETE FROM ' . self::IDENT . '\s+WHERE\s+(.+)$/is', $s, $m) === 1) {
                $q = '["\']([^"\']*)["\']';
                $ok = match ($m[1]) {
                    'list_options' => preg_match('/^list_id\s*=\s*' . $q . '(\s+AND\s+option_id\s*=\s*' . $q . ')?$/i', trim($m[2]), $d) === 1,
                    'layout_options' => preg_match('/^form_id\s*=\s*' . $q . '$/i', trim($m[2]), $d) === 1,
                    'layout_group_properties' => preg_match('/^grp_form_id\s*=\s*' . $q . '$/i', trim($m[2]), $d) === 1,
                    default => false,
                };
                if (!$ok) {
                    throw new \RuntimeException("Statement $n deletes in a way the Backup page never did; the file was not read.");
                }
                if ($m[1] === 'list_options') {
                    $listIds[isset($d[3]) && $d[1] === 'lists' ? $d[3] : $d[1]] = true;
                } else {
                    $layoutIds[$d[1]] = true;
                }
                continue;
            }
            if (preg_match('/^INSERT INTO ' . self::IDENT . '\s*\((.*?)\)\s*VALUES\s*(.+)$/is', $s, $m) === 1) {
                if (!in_array($m[1], self::TABLES, true)) {
                    throw new \RuntimeException("Statement $n inserts into {$m[1]}, which isn't a layout or list table; the file was not read.");
                }
                $columns = array_map(static fn(string $c): string => trim($c, " \t\n\r`"), explode(',', $m[2]));
                foreach (self::tuples($m[3], $n) as $values) {
                    if (count($values) !== count($columns)) {
                        throw new \RuntimeException("Statement $n has " . count($columns) . ' columns but ' . count($values) . ' values; the file was not read.');
                    }
                    $rows[$m[1]][] = array_combine($columns, $values);
                }
                continue;
            }
            throw new \RuntimeException("Statement $n is not one the Backup page wrote for layouts and lists (" . substr($s, 0, 50) . '); the file was not read.');
        }
        if ($rows === array_fill_keys(self::TABLES, []) && $layoutIds === [] && $listIds === []) {
            throw new \RuntimeException('The file has no layouts or lists in it.');
        }
        $layouts = [];
        foreach ($layoutIds as $id => $_) {
            $layouts[$id] = ['properties' => [], 'fields' => []];
        }
        foreach ($rows['layout_group_properties'] as $r) {
            $layouts[(string)$r['grp_form_id']]['properties'][] = $r;
        }
        foreach ($rows['layout_options'] as $r) {
            $layouts[(string)$r['form_id']]['fields'][] = $r;
        }
        $lists = [];
        foreach ($listIds as $id => $_) {
            $lists[$id] = ['list' => null, 'options' => []];
        }
        foreach ($rows['list_options'] as $r) {
            if ($r['list_id'] === 'lists') {
                $lists[(string)$r['option_id']]['list'] = $r;
            } else {
                $lists[(string)$r['list_id']]['options'][] = $r;
            }
        }
        return new self('an SQL file from the Backup page', self::complete($layouts, 'properties', 'fields'), self::complete($lists, 'list', 'options'), '');
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== 'openemr-layout-export') {
            throw new \RuntimeException('This is not a Layout Export file or an SQL file from the Backup page.');
        }
        $layouts = [];
        foreach (is_array($data['layouts'] ?? null) ? $data['layouts'] : [] as $id => $l) {
            $layouts[(string)$id] = ['properties' => self::rows($l['properties'] ?? []), 'fields' => self::rows($l['fields'] ?? [])];
        }
        $lists = [];
        foreach (is_array($data['lists'] ?? null) ? $data['lists'] : [] as $id => $l) {
            $lists[(string)$id] = ['list' => is_array($l['list'] ?? null) ? self::rows([$l['list']])[0] : null, 'options' => self::rows($l['options'] ?? [])];
        }
        return new self('a Layout Export file', $layouts, $lists, is_string($data['openemr'] ?? null) ? $data['openemr'] : '');
    }

    private static function fromZip(string $path): self
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true || ($manifest = $zip->getFromName('manifest.json')) === false) {
            throw new \RuntimeException('This zip is not a Layout Export file.');
        }
        $data = json_decode($manifest, true);
        $data = is_array($data) ? $data : [];
        $data['layouts'] = [];
        $data['lists'] = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $item = json_decode((string)$zip->getFromIndex($i), true);
            if (str_starts_with($name, 'layouts/') && is_string($item['form_id'] ?? null)) {
                $data['layouts'][$item['form_id']] = $item;
            } elseif (str_starts_with($name, 'lists/') && is_string($item['list_id'] ?? null)) {
                $data['lists'][$item['list_id']] = $item;
            }
        }
        $zip->close();
        return self::fromJson((string)json_encode($data));
    }

    /**
     * @param mixed $rows
     * @return list<array<string, ?string>>
     */
    private static function rows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = array_map(static fn(mixed $v): ?string => $v === null ? null : (is_scalar($v) ? (string)$v : ''), $row);
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private static function complete(array $items, string $one, string $many): array
    {
        return array_map(static fn(array $i): array => [$one => $i[$one] ?? ($one === 'list' ? null : []), $many => $i[$many] ?? []], $items);
    }

    /**
     * The statements, split on semicolons outside quotes and backticks; comments skipped.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $out = [];
        $current = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $current .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $current .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
            } elseif ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException('The file ends inside a comment; it was not read.');
                }
                $current .= ' ';
                $i = $end + 1;
            } elseif ($c === '-' && substr($sql, $i, 3) === '-- ' && trim($current) === '') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
            } elseif ($c === ';') {
                $out[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if ($quote !== null) {
            throw new \RuntimeException('The file ends inside a quoted value; it was not read.');
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }
        return $out;
    }

    /** @return list<list<?string>> */
    private static function tuples(string $s, int $n): array
    {
        $tuples = [];
        $i = 0;
        $len = strlen($s);
        $skip = static function () use ($s, &$i, $len): void {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
        };
        while (true) {
            $skip();
            if ($i >= $len || $s[$i] !== '(') {
                throw new \RuntimeException("Statement $n has values that are not a list in parentheses; the file was not read.");
            }
            $i++;
            $values = [];
            while (true) {
                $skip();
                $values[] = self::value($s, $i, $n);
                $skip();
                if ($i < $len && $s[$i] === ',') {
                    $i++;
                    continue;
                }
                if ($i < $len && $s[$i] === ')') {
                    $i++;
                    break;
                }
                throw new \RuntimeException("Statement $n has a value list that does not close; the file was not read.");
            }
            $tuples[] = $values;
            $skip();
            if ($i < $len && $s[$i] === ',') {
                $i++;
                continue;
            }
            if ($i >= $len) {
                return $tuples;
            }
            throw new \RuntimeException("Statement $n has something after its values; the file was not read.");
        }
    }

    private static function value(string $s, int &$i, int $n): ?string
    {
        $rest = substr($s, $i);
        if (preg_match('/^NULL\b/i', $rest) === 1) {
            $i += 4;
            return null;
        }
        if (preg_match('/^0x([0-9A-Fa-f]*)/', $rest, $m) === 1) {
            $i += strlen($m[0]);
            return (string)hex2bin(strlen($m[1]) % 2 === 1 ? '0' . $m[1] : $m[1]);
        }
        if (preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?/', $rest, $m) === 1) {
            $i += strlen($m[0]);
            return $m[0];
        }
        if ($rest !== '' && ($rest[0] === "'" || $rest[0] === '"')) {
            $quote = $rest[0];
            $out = '';
            $i++;
            $len = strlen($s);
            $escapes = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '\\' => '\\'];
            while ($i < $len) {
                $c = $s[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $out .= $escapes[$s[$i + 1]] ?? $s[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $quote) {
                    if ($i + 1 < $len && $s[$i + 1] === $quote) {
                        $out .= $quote;
                        $i += 2;
                        continue;
                    }
                    $i++;
                    return $out;
                }
                $out .= $c;
                $i++;
            }
            throw new \RuntimeException("Statement $n has a quoted value that does not end; the file was not read.");
        }
        throw new \RuntimeException("Statement $n has a value that is not a string, number, NULL or hex; the file was not read.");
    }
}
