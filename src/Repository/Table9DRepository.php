<?php

/**
 * What the Table 9D worksheet reads from OpenEMR's accounts receivable, for
 * encounters at the clinic's in-scope sites.
 *
 * Charges are the fee sheet's active lines (billing.fee), less the old COPAY
 * lines (payments recorded as negative charges by early versions) and sales
 * tax. Payments and adjustments are the active ar_activity rows; the payments
 * table is not read, because the payment screens write each payment to both.
 * A posting's date is the one entered with it, else its batch's Post To Date,
 * else the day it was saved.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\UdsReport\Enum\InsuranceCategory;
use OpenEMR\Modules\UdsReport\Enum\Table9DLine;
use OpenEMR\Modules\UdsReport\Model\ChargedEncounter;
use OpenEMR\Modules\UdsReport\Model\Posting;
use OpenEMR\Modules\UdsReport\Report\Money;
use OpenEMR\Modules\UdsReport\Report\UdsSettings;

final readonly class Table9DRepository
{
    private const POSTING_DATE = "CASE WHEN a.post_date > '1900-01-01' THEN a.post_date"
        . " WHEN s.post_to_date > '1900-01-01' THEN s.post_to_date ELSE DATE(a.post_time) END";
    private const SESSION_DATE = "CASE WHEN s.post_to_date > '1900-01-01' THEN s.post_to_date"
        . " WHEN s.deposit_date > '1900-01-01' THEN s.deposit_date ELSE DATE(s.created_time) END";
    private const POSTING_COLUMNS = 'a.pid, a.encounter, a.payer_type, a.pay_amount, a.adj_amount, a.memo, a.code_type, s.payer_id';
    private const LEVELS = ['primary' => 1, 'secondary' => 2, 'tertiary' => 3];

    public function __construct(private UdsPatientRepository $patients)
    {
    }

    /**
     * Encounters with a date of service in the year and charges other than zero.
     *
     * @return list<ChargedEncounter>
     */
    public function charged(int $year, UdsSettings $settings): array
    {
        [$scope, $scopeBinds] = self::scope($settings);
        $binds = [...self::serviceYear($year), ...$scopeBinds];
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.pid, fe.encounter, fe.date, fe.last_level_closed, SUM(b.fee) AS charges
               FROM form_encounter AS fe
               JOIN billing AS b ON b.pid = fe.pid AND b.encounter = fe.encounter AND b.activity = 1
                AND b.fee IS NOT NULL AND COALESCE(b.code_type, '') NOT IN ('COPAY', 'TAX')
              WHERE fe.date >= ? AND fe.date < ?$scope
              GROUP BY fe.pid, fe.encounter, fe.date, fe.last_level_closed",
            $binds,
        );
        $postingRows = QueryUtils::fetchRecords(
            'SELECT ' . self::POSTING_COLUMNS . ', ' . self::POSTING_DATE . " AS posted
               FROM form_encounter AS fe
               JOIN ar_activity AS a ON a.pid = fe.pid AND a.encounter = fe.encounter AND a.deleted IS NULL
          LEFT JOIN ar_session AS s ON s.session_id = a.session_id
              WHERE fe.date >= ? AND fe.date < ?$scope",
            $binds,
        );
        $companies = $this->patients->insuranceCompanies($settings);
        $insurance = $this->insuranceRows(array_map(static fn(array $r): int => Row::int($r, 'pid'), $rows));

        $insurers = [];
        foreach ($rows as $row) {
            $insurers[self::key($row)] = self::insurersAt($insurance[Row::int($row, 'pid')] ?? [], substr(Row::text($row, 'date'), 0, 10), $companies);
        }
        $postings = [];
        foreach ($postingRows as $row) {
            $key = self::key($row);
            if (isset($insurers[$key])) {
                $postings[$key][] = self::posting($row, $insurers[$key], $companies);
            }
        }

        $encounters = [];
        foreach ($rows as $row) {
            $charge = Money::cents(Row::nullableText($row, 'charges'));
            if ($charge === 0) {
                continue;
            }
            $key = self::key($row);
            $encounters[] = new ChargedEncounter($charge, Row::nullableInt($row, 'last_level_closed') ?? 0, $insurers[$key], $postings[$key] ?? []);
        }
        return $encounters;
    }

    /**
     * Payments and adjustments posted in the year, whatever the date of service.
     *
     * @return list<Posting>
     */
    public function posted(int $year, UdsSettings $settings): array
    {
        [$scope, $scopeBinds] = self::scope($settings);
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . self::POSTING_COLUMNS . ', fe.date, ' . self::POSTING_DATE . ' AS posted
               FROM ar_activity AS a
               JOIN form_encounter AS fe ON fe.pid = a.pid AND fe.encounter = a.encounter
          LEFT JOIN ar_session AS s ON s.session_id = a.session_id
              WHERE a.deleted IS NULL AND ' . self::POSTING_DATE . ' >= ? AND ' . self::POSTING_DATE . " < ?$scope",
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1), ...$scopeBinds],
        );
        $companies = $this->patients->insuranceCompanies($settings);
        // The insurers on file are only needed for an insurer's posting that doesn't name the payer.
        $unnamed = array_filter($rows, static fn(array $r): bool => Row::int($r, 'payer_type') !== 0 && (Row::nullableInt($r, 'payer_id') ?? 0) <= 0);
        $insurance = $this->insuranceRows(array_map(static fn(array $r): int => Row::int($r, 'pid'), $unnamed));

        $postings = [];
        foreach ($rows as $row) {
            $insurers = self::insurersAt($insurance[Row::int($row, 'pid')] ?? [], substr(Row::text($row, 'date'), 0, 10), $companies);
            $postings[] = self::posting($row, $insurers, $companies);
        }
        return $postings;
    }

    /**
     * What the worksheet reports beside the table: old COPAY lines on the year's
     * encounters, money received in the year and not yet applied to a visit
     * (prepayments, or a payer's check not fully posted), and product sales.
     *
     * @return array{copayLines: array{count: int, cents: int}, unapplied: array{count: int, cents: int}, productSales: array{count: int, cents: int}}
     */
    public function notes(int $year, UdsSettings $settings): array
    {
        [$scope, $scopeBinds] = self::scope($settings);
        $copay = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS n, SUM(b.fee) AS cents
               FROM form_encounter AS fe
               JOIN billing AS b ON b.pid = fe.pid AND b.encounter = fe.encounter AND b.activity = 1 AND b.code_type = 'COPAY'
              WHERE fe.date >= ? AND fe.date < ?$scope",
            [...self::serviceYear($year), ...$scopeBinds],
        );
        $sessions = QueryUtils::fetchRecords(
            'SELECT s.session_id, s.pay_total, SUM(a.pay_amount) AS applied
               FROM ar_session AS s
          LEFT JOIN ar_activity AS a ON a.session_id = s.session_id AND a.deleted IS NULL
              WHERE ' . self::SESSION_DATE . ' >= ? AND ' . self::SESSION_DATE . ' < ?
              GROUP BY s.session_id, s.pay_total',
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );
        $unapplied = ['count' => 0, 'cents' => 0];
        foreach ($sessions as $row) {
            $left = Money::cents(Row::nullableText($row, 'pay_total')) - Money::cents(Row::nullableText($row, 'applied'));
            if ($left > 0) {
                $unapplied['count']++;
                $unapplied['cents'] += $left;
            }
        }
        $sales = QueryUtils::fetchRecords(
            'SELECT COUNT(*) AS n, SUM(fee) AS cents FROM drug_sales WHERE trans_type = 1 AND pid > 0 AND sale_date >= ? AND sale_date < ?',
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );
        return [
            'copayLines' => ['count' => Row::int($copay[0] ?? [], 'n'), 'cents' => Money::cents(Row::nullableText($copay[0] ?? [], 'cents'))],
            'unapplied' => $unapplied,
            'productSales' => ['count' => Row::int($sales[0] ?? [], 'n'), 'cents' => Money::cents(Row::nullableText($sales[0] ?? [], 'cents'))],
        ];
    }

    /**
     * The insurer at each level on a day: of the insurance_data rows of that
     * type in effect, the one that started last.
     *
     * @param list<array<mixed>>             $rows      insurance_data rows: type, provider, date, date_end
     * @param array<int, InsuranceCategory> $companies insurance company id => category
     * @return array<int, InsuranceCategory> level (1-3) => category
     */
    public static function insurersAt(array $rows, string $day, array $companies): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $level = self::LEVELS[Row::text($row, 'type')] ?? null;
            $start = self::day(Row::text($row, 'date'));
            $end = self::day(Row::text($row, 'date_end'));
            if ($level === null || ($start !== null && $start > $day) || ($end !== null && $end < $day)) {
                continue;
            }
            if (!isset($latest[$level]) || strcmp($start ?? '', $latest[$level]['start'] ?? '') > 0) {
                $latest[$level] = ['start' => $start, 'provider' => Row::nullableInt($row, 'provider') ?? 0];
            }
        }
        ksort($latest);
        return array_map(static fn(array $l): InsuranceCategory => $companies[$l['provider']] ?? InsuranceCategory::Unmapped, $latest);
    }

    /**
     * @param array<mixed>                   $row
     * @param array<int, InsuranceCategory> $insurers
     * @param array<int, InsuranceCategory> $companies
     */
    private static function posting(array $row, array $insurers, array $companies): Posting
    {
        $payerType = Row::int($row, 'payer_type');
        $payerId = Row::nullableInt($row, 'payer_id') ?? 0;
        $payer = $payerId > 0 ? ($companies[$payerId] ?? InsuranceCategory::Unmapped) : null;
        return new Posting(
            $payerType,
            Table9DLine::forPosting($payerType, $payer, $insurers),
            Money::cents(Row::nullableText($row, 'pay_amount')),
            Money::cents(Row::nullableText($row, 'adj_amount')),
            Row::text($row, 'memo'),
            substr(Row::text($row, 'posted'), 0, 10),
            strtoupper(trim(Row::text($row, 'code_type'))) === 'PROD',
        );
    }

    /**
     * insurance_data rows naming an insurer, by pid.
     *
     * @param list<int> $pids
     * @return array<int, list<array<mixed>>>
     */
    private function insuranceRows(array $pids): array
    {
        $byPid = [];
        foreach (array_chunk(array_values(array_unique($pids)), 1000) as $chunk) {
            $rows = QueryUtils::fetchRecords(
                'SELECT pid, type, provider, date, date_end FROM insurance_data WHERE pid IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ") AND provider IS NOT NULL AND provider <> '' AND provider <> '0'",
                $chunk,
            );
            foreach ($rows as $row) {
                $byPid[Row::int($row, 'pid')][] = $row;
            }
        }
        return $byPid;
    }

    /**
     * A condition limiting form_encounter AS fe to the in-scope sites, and its bound values.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function scope(UdsSettings $settings): array
    {
        $sites = $settings->inScopeFacilityIds;
        if ($sites === null) {
            return ['', []];
        }
        if ($sites === []) {
            return [' AND 1 = 0', []];
        }
        return [' AND fe.facility_id IN (' . implode(',', array_fill(0, count($sites), '?')) . ')', array_values($sites)];
    }

    /** @return list<string> */
    private static function serviceYear(int $year): array
    {
        return [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)];
    }

    /** @param array<mixed> $row */
    private static function key(array $row): string
    {
        return Row::int($row, 'pid') . ':' . Row::int($row, 'encounter');
    }

    private static function day(string $value): ?string
    {
        $day = substr($value, 0, 10);
        return $day === '' || str_starts_with($day, '0000') ? null : $day;
    }
}
