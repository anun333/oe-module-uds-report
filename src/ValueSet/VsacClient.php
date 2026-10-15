<?php

/**
 * Fetches value set expansions from NLM's Value Set Authority Center (VSAC)
 * through its FHIR API, with the site's own UMLS API key. The key is sent as
 * the password of HTTP Basic authentication, as VSAC asks, and goes nowhere
 * else: not into the database, a log or an error message.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ValueSet;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final readonly class VsacClient
{
    public const BASE_URL = 'https://cts.nlm.nih.gov/fhir/ValueSet/';
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 50;

    /** @param Closure(string, string): array{int, string} $get given a URL and the API key, the response's status and body */
    public function __construct(private Closure $get)
    {
    }

    public static function overHttps(): self
    {
        return new self(static function (string $url, string $apiKey): array {
            try {
                $response = (new Client(['timeout' => 60, 'http_errors' => false]))->get($url, [
                    'auth' => ['apikey', $apiKey],
                    'headers' => ['Accept' => 'application/fhir+json'],
                ]);
            } catch (GuzzleException) {
                throw new ValueSetException('Could not reach VSAC (cts.nlm.nih.gov). Check that this server can make HTTPS requests to it.');
            }
            return [$response->getStatusCode(), (string)$response->getBody()];
        });
    }

    /** @throws ValueSetException */
    public function expand(string $oid, string $apiKey): ValueSetExpansion
    {
        if (preg_match('/^\d+(\.\d+)+$/', $oid) !== 1) {
            throw new ValueSetException("$oid is not a value set OID");
        }
        if (trim($apiKey) === '') {
            throw new ValueSetException('No UMLS API key was given');
        }
        $pages = [];
        $offset = 0;
        do {
            [$status, $body] = ($this->get)(self::BASE_URL . $oid . '/$expand?count=' . self::PAGE_SIZE . '&offset=' . $offset, trim($apiKey));
            if ($status === 401 || $status === 403) {
                throw new ValueSetException("VSAC refused the API key (HTTP $status). Copy it again from your UTS profile at uts.nlm.nih.gov.");
            }
            if ($status === 404) {
                throw new ValueSetException("VSAC has no value set $oid");
            }
            if ($status !== 200) {
                throw new ValueSetException("VSAC answered HTTP $status for $oid");
            }
            try {
                $page = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ValueSetException("VSAC's answer for $oid isn't JSON");
            }
            $pages[] = $page;
            $expansion = is_array($page) && is_array($page['expansion'] ?? null) ? $page['expansion'] : [];
            $got = is_array($expansion['contains'] ?? null) ? count($expansion['contains']) : 0;
            $total = $expansion['total'] ?? null;
            $offset += $got;
        } while (is_int($total) && $got > 0 && $offset < $total && count($pages) < self::MAX_PAGES);
        return ValueSetExpansion::fromFhir($oid, $pages);
    }
}
