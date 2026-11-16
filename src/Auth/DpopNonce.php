<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

/**
 * The DPoP server nonce (RFC 9449 §8) — PARITY §7.1, client Phase 4.3.
 *
 * KnoxCall answers EVERY request that carried a `DPoP` proof header with
 * `DPoP-Nonce: <value>`: mints, refusals and successful calls alike, on
 * `POST /oauth/token`, `/v1`, the tenant data plane, the self-hosted proxy and
 * the AI gateway. The value is stateless, changes every minute, and the server
 * accepts the current and the previous minute's — so a nonce is good for
 * 60–120 s.
 *
 * The server's rule is VERIFY-IF-PRESENT: a proof carrying a `nonce` claim must
 * carry one the server would issue now, and a proof carrying none is still
 * accepted. So sending one is an optimisation and a readiness step, never a
 * requirement, and a client that never learned a nonce keeps working.
 *
 * WHAT THIS IS. A per-origin memory of the last nonce seen, plus the predicate
 * that recognises the server's "use a nonce" refusal. Both are deliberately
 * tiny and have no I/O.
 *
 * - Keyed by ORIGIN (scheme + host + port), because that is what the nonce is
 *   scoped to: the management API and a tenant's data plane are different
 *   origins and issue different values. Keyed coarser and a cross-origin nonce
 *   would be sent and refused; keyed finer (a path) and almost every request
 *   would start without one.
 * - Last-write-wins, no expiry. A nonce is not a secret and needs no storage
 *   beyond the process. It need not be fresh either: the worst a stale one
 *   costs is the single documented retry, and the server deliberately refuses a
 *   stale nonce BEFORE claiming the proof's `jti`, so that retry is not then
 *   refused for an unrelated reason.
 *
 * No lock: PHP's request model is one script, one thread. The other four SDKs
 * guard their map because a client there is shared across threads or goroutines.
 */
final class DpopNonce
{
    /** The response header the server sends on every DPoP-carrying request. */
    public const HEADER = 'dpop-nonce';

    /**
     * The RFC 9449 §8 error code: in the OAuth body on the authorization
     * server, in `WWW-Authenticate` on a resource server.
     */
    public const USE_DPOP_NONCE = 'use_dpop_nonce';

    /** @var array<string, string> origin => last nonce seen */
    private array $byOrigin = [];

    /**
     * `scheme://host[:port]` — the scope a nonce belongs to — or null when the
     * URL has no host, which simply means "do not remember this one".
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $default = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? null;
        if ($port !== null && (int) $port !== $default) {
            $host .= ':' . (int) $port;
        }
        return $scheme . '://' . $host;
    }

    /**
     * Record the nonce on a response, if it carried one. Returns it, or null.
     *
     * `$headers` keys must already be lower-cased — every call site in this SDK
     * normalises them, and looking for one casing only is what makes this an
     * array lookup rather than a scan.
     *
     * @param array<string, string>|null $headers
     */
    public function remember(string $url, ?array $headers): ?string
    {
        $raw = $headers[self::HEADER] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        $origin = self::originOf($url);
        if ($origin === null) {
            return null;
        }
        $this->byOrigin[$origin] = $value;
        return $value;
    }

    /**
     * The nonce to put in a proof for `$url`, or null when this origin has not
     * answered yet (which is fine — the server accepts a proof with no nonce
     * claim, and `sign` omits the claim for null).
     */
    public function get(string $url): ?string
    {
        $origin = self::originOf($url);
        return $origin === null ? null : ($this->byOrigin[$origin] ?? null);
    }

    public function forget(string $url): void
    {
        $origin = self::originOf($url);
        if ($origin !== null) {
            unset($this->byOrigin[$origin]);
        }
    }

    /** @return array<string, string> a copy, for tests and debugging */
    public function snapshot(): array
    {
        return $this->byOrigin;
    }

    /**
     * Whether a refusal is the server asking for a (fresh) nonce.
     *
     * RFC 9449 splits the two surfaces and so does KnoxCall, which is why this
     * takes all three inputs rather than just a status:
     *
     * - `POST /oauth/token` (authorization server, §8.1) answers **400** with
     *   the OAuth body `{"error": "use_dpop_nonce", …}`;
     * - `/v1`, the tenant data plane, the self-hosted proxy and the AI gateway
     *   (resource server, §8.2) answer **401** with
     *   `WWW-Authenticate: DPoP error="use_dpop_nonce"`. On `/v1` the body's
     *   error type says it too, but the tenant data plane keeps its deliberately
     *   opaque `Unauthorized` body, SO THE HEADER IS THE ONLY SIGNAL THERE.
     *   Reading the body alone would make this work on /v1 and silently not on
     *   the data plane.
     *
     * @param array<string, string>|null $headers
     */
    public static function isRefusal(int $status, ?string $code = null, ?array $headers = null): bool
    {
        if ($status !== 400 && $status !== 401) {
            return false;
        }
        if ($code === self::USE_DPOP_NONCE) {
            return true;
        }
        $challenge = $headers['www-authenticate'] ?? '';
        return is_string($challenge)
            && str_contains(strtolower($challenge), self::USE_DPOP_NONCE);
    }
}
