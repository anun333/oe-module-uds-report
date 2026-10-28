<?php

/**
 * Last year's UDS report as HRSA published it, for one health center or look-alike.
 *
 * HRSA publishes every awardee's and look-alike's UDS tables each year in its UDS data
 * download (data.hrsa.gov). data/hrsa-published-YEAR.jsonl.gz keeps the tables this module
 * computes: a header line naming each report's field codes, then one line per grant or
 * look-alike number with its values in that order. Only the matching line is decoded.
 *
 * HRSA hides counts from 1 to 15 in that file; those come back as null, never as zero.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Report;

final readonly class PublishedUds
{
    /**
     * @param array<string, int|float|null> $values code => value; null where HRSA hid a count of 1 to 15
     */
    private function __construct(
        public int $year,
        public string $grant,
        public string $name,
        public string $state,
        public bool $lookAlike,
        public string $report,
        public array $values,
    ) {
    }

    /** The published files the module ships, newest first: year => path. */
    public static function files(?string $dir = null): array
    {
        $files = [];
        foreach (glob(($dir ?? dirname(__DIR__, 2) . '/data') . '/hrsa-published-*.jsonl.gz') ?: [] as $path) {
            if (preg_match('/hrsa-published-(\d{4})\.jsonl\.gz$/', $path, $m) === 1) {
                $files[(int)$m[1]] = $path;
            }
        }
        krsort($files);
        return $files;
    }

    /**
     * A grant or look-alike number as HRSA writes it (H80CS00000, LALCS00000), or null when
     * what was typed can't be one. Spaces and dashes are dropped, letters made upper case.
     */
    public static function normalizeGrant(string $typed): ?string
    {
        $grant = strtoupper((string)preg_replace('/[\s\-]+/', '', $typed));
        return preg_match('/^[A-Z]{1,4}\d{0,3}[A-Z]{0,3}\d{4,6}$/D', $grant) === 1 ? $grant : null;
    }

    /**
     * The organization's figures for one report (universal, hch, msaw, phpc) in the file, or
     * null when the file has no such organization or the organization filed no such report.
     */
    public static function find(string $path, string $grant, string $report = 'universal'): ?self
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Cannot open $path");
        }
        try {
            $first = gzgets($gz);
            $header = is_string($first) ? json_decode($first, true, 8, JSON_THROW_ON_ERROR) : null;
            if (!is_array($header) || !is_int($header['year'] ?? null) || !is_array($header['codes'] ?? null)) {
                throw new \RuntimeException("$path has no header line");
            }
            $needle = '"grant":' . json_encode($grant) . ',';
            while (($line = gzgets($gz)) !== false) {
                if (!str_starts_with($line, '{' . $needle)) {
                    continue;
                }
                $org = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                $codes = $header['codes'][$report] ?? null;
                $values = is_array($org) ? ($org['reports'][$report] ?? null) : null;
                if (!is_array($codes) || !is_array($values) || count($codes) !== count($values)) {
                    return null;
                }
                // A table HRSA's file has no row for (its 2024 Table 5 lacks 645 health centers) is left out, not read as zeros.
                $missing = is_array($org['missing'][$report] ?? null) ? $org['missing'][$report] : [];
                $figures = [];
                foreach ($codes as $i => $code) {
                    if (in_array(strstr((string)$code, '_', true), $missing, true)) {
                        continue;
                    }
                    $value = $values[$i];
                    $figures[(string)$code] = is_int($value) || is_float($value) ? $value : null;
                }
                return new self(
                    $header['year'],
                    $grant,
                    is_string($org['name'] ?? null) ? $org['name'] : '',
                    is_string($org['state'] ?? null) ? $org['state'] : '',
                    ($org['lookAlike'] ?? false) === true,
                    $report,
                    $figures,
                );
            }
            return null;
        } finally {
            gzclose($gz);
        }
    }

    /** The organization's name as HRSA lists it, in title case where HRSA wrote it in capitals. */
    public function displayName(): string
    {
        return $this->name === strtoupper($this->name) ? ucwords(strtolower($this->name)) : $this->name;
    }
}
