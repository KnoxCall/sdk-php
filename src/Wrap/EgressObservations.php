<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

/**
 * The uncovered-egress classifier (PARITY §21.3; founder decisions
 * 2026-09-26) — PHP mirror of sdk/knoxcall-node/src/egress-observations.ts.
 *
 * A route-aware seam sees every request the wrapped SDK makes and sends only
 * the covered ones through KnoxCall. The rest go direct — and among them are
 * calls that carry a credential the platform does not hold: "uncovered
 * egress". {@see EgressObservationReporter} records those (host, first path
 * segment, method, credential header NAME) and reports the aggregate to
 * POST /v1/wrap/egress-observations, so the dashboard can show a tenant which
 * credentials are still leaving their process un-custodied.
 *
 * What is recorded is bounded on purpose, and the bound is the feature: names,
 * never values — the credential header's NAME, never its value; the FIRST
 * path segment only — never the query string, never the body, never a deeper
 * path. Only a DIRECT decision with reason `unlisted` is observed — own_host,
 * route_around, kill_switch, outside_context and unparseable never are.
 *
 * Every SDK's classifier passes the SAME fixtures —
 * sdk/fixtures/egress-observation.json — so this class is a contract, not a
 * private heuristic.
 */
final class EgressObservations
{
    /** Exact (case-insensitive) header names that carry a credential. */
    public const CREDENTIAL_HEADER_ALLOWLIST = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'apikey',
        'x-apikey',
        'x-auth-token',
        'x-access-token',
        'x-token',
        'token',
        'x-secret',
        'x-secret-key',
        'x-client-secret',
        'ocp-apim-subscription-key',
        'x-goog-api-key',
        'x-amz-security-token',
        'x-shopify-access-token',
        'klaviyo-api-key',
        'x-hubspot-api-key',
    ];

    /** A lower-cased header name ending in one of these also counts. */
    public const CREDENTIAL_HEADER_SUFFIXES = ['-api-key', '-token', '-secret', '-auth'];

    /** The methods the server accepts (upper-case); anything else is `invalid_method`. */
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'];

    /** The server's shape checks (src/wrap/egress-observations.ts). */
    private const HEADER_NAME_RE = '/^[a-z0-9][a-z0-9_-]*$/';
    private const FIRST_SEGMENT_RE = "~^/[A-Za-z0-9._\\~!$&'()*+,;=:@%-]{0,255}$~";
    private const MAX_HEADER_NAME_LENGTH = 64;

    /** Longer than this and a single segment is not a resource name (server #1022). */
    public const MAX_PLAIN_FIRST_SEGMENT_LENGTH = 64;

    /** Known credential shapes, tested against the start of the segment (server #1022). */
    private const CREDENTIAL_SEGMENT_PREFIXES = [
        '/^bot\\d+:/i',
        '/^(sk|pk|rk)_(live|test)_/i',
        '/^sk-/',
        '/^xox[abposr]-/',
        '/^gh[pousr]_/',
        '/^github_pat_/',
        '/^glpat-/',
        '/^shp(at|ca|pa|ss)_/',
        '/^(AKIA|ASIA)[0-9A-Z]{12,}/',
        '/^AIza[0-9A-Za-z_-]{20,}/',
        '/^eyJ[A-Za-z0-9_-]{8,}/',
        '/^SG\\./',
    ];

    private function __construct()
    {
    }

    /** Whether a header NAME (any casing) is credential-bearing. */
    public static function isCredentialHeaderName(string $name): bool
    {
        $n = strtolower(trim($name));
        if ($n === '' || strlen($n) > self::MAX_HEADER_NAME_LENGTH || preg_match(self::HEADER_NAME_RE, $n) !== 1) {
            return false;
        }
        if (in_array($n, self::CREDENTIAL_HEADER_ALLOWLIST, true)) {
            return true;
        }
        foreach (self::CREDENTIAL_HEADER_SUFFIXES as $suffix) {
            if (strlen($n) > strlen($suffix) && str_ends_with($n, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The credential header NAME to report for a request, or null when none is
     * present. Allowlist entries win in allowlist order; then the
     * lexicographically smallest suffix match. A header whose value is empty
     * after trimming never counts. Only names are read — values are looked at
     * solely to discard empties and are never returned.
     *
     * @param array<string, string|list<string>> $headers any casing
     */
    public static function credentialHeaderName(array $headers): ?string
    {
        $rank = array_flip(self::CREDENTIAL_HEADER_ALLOWLIST);
        $best = null;
        $bestRank = count(self::CREDENTIAL_HEADER_ALLOWLIST) + 1;
        $bestSuffix = null;
        foreach ($headers as $rawName => $rawValue) {
            $name = strtolower(trim((string) $rawName));
            if ($name === '') {
                continue;
            }
            $nonEmpty = false;
            foreach ((array) $rawValue as $v) {
                if (trim((string) $v) !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if (!$nonEmpty) {
                continue;
            }
            if (isset($rank[$name])) {
                if ($rank[$name] < $bestRank) {
                    $bestRank = $rank[$name];
                    $best = $name;
                }
                continue;
            }
            if (self::isCredentialHeaderName($name) && ($bestSuffix === null || strcmp($name, $bestSuffix) < 0)) {
                $bestSuffix = $name;
            }
        }
        return $best ?? $bestSuffix;
    }

    /**
     * Whether a first segment (`/` + one segment) looks like it carries a
     * credential — the server's rule (#1022), on the raw and percent-decoded
     * forms. Some APIs put a credential in the path (Telegram's
     * `/bot<id>:<secret>/...`); the SDK reports such a segment as `/`, so the
     * value never leaves the process.
     */
    public static function firstSegmentLooksLikeCredential(string $firstSegment): bool
    {
        $raw = str_starts_with($firstSegment, '/') ? substr($firstSegment, 1) : $firstSegment;
        if ($raw === '') {
            return false;
        }
        foreach (array_unique([$raw, rawurldecode($raw)]) as $s) {
            if (strlen($s) > self::MAX_PLAIN_FIRST_SEGMENT_LENGTH) {
                return true;
            }
            foreach (self::CREDENTIAL_SEGMENT_PREFIXES as $re) {
                if (preg_match($re, $s) === 1) {
                    return true;
                }
            }
            if (preg_match_all('/[A-Za-z0-9_-]{24,}/', $s, $m) > 0) {
                foreach ($m[0] as $run) {
                    $classes = (int) (preg_match('/[a-z]/', $run) === 1) + (int) (preg_match('/[A-Z]/', $run) === 1)
                        + (int) (preg_match('/[0-9]/', $run) === 1);
                    if ($classes >= 2) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * `/` or `/<first path segment>` of the URL — never the query, never
     * deeper. Exactly ONE leading slash is consumed, so `//double` reports `/`
     * (ltrim would eat both and report `/double`).
     */
    public static function firstSegment(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $rest = str_starts_with($path, '/') ? substr($path, 1) : $path;
        return '/' . explode('/', $rest, 2)[0];
    }

    /**
     * The whole classifier, pure: the four identifying fields for this
     * request, or null when it carries no credential-bearing header. The
     * caller has ALREADY decided the request is direct + unlisted.
     *
     * @param array<string, string|list<string>> $headers
     * @return array{host: string, first_segment: string, method: string, header_name: string}|null
     */
    public static function observationFor(string $url, string $method, array $headers): ?array
    {
        $rawHost = parse_url($url, PHP_URL_HOST);
        if (!is_string($rawHost) || $rawHost === '') {
            return null;
        }
        $host = WrapTransport::normalizeHost($rawHost);
        // An IP literal is never a Route target; the server drops it (`ip_literal`).
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $upper = strtoupper($method === '' ? 'GET' : $method);
        if (!in_array($upper, self::METHODS, true)) {
            return null;
        }
        $segment = self::firstSegment($url);
        if (self::firstSegmentLooksLikeCredential($segment)) {
            $segment = '/'; // reported as `/`; the entry is kept
        }
        if (preg_match(self::FIRST_SEGMENT_RE, $segment) !== 1) {
            return null;
        }
        $name = self::credentialHeaderName($headers);
        if ($name === null) {
            return null;
        }
        return ['host' => $host, 'first_segment' => $segment, 'method' => $upper, 'header_name' => $name];
    }

    /** `KNOXCALL_OBSERVE_UNCOVERED=off|false|0` turns the reporter off (read when a pipeline is built). */
    public static function disabledByEnv(): bool
    {
        $v = getenv('KNOXCALL_OBSERVE_UNCOVERED');
        return is_string($v) && in_array(strtolower(trim($v)), ['off', '0', 'false'], true);
    }
}
