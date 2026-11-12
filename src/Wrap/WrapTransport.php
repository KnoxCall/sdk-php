<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

/**
 * Pure, transport-agnostic helpers behind {@see \KnoxCall\Resources\WrapResource::httpClient()}
 * — the PHP mirror of the Node SDK's `src/wrap-transport.ts`.
 *
 * A wrapped third-party SDK (Stripe first) keeps its own serialization,
 * retries, idempotency keys and error types; only its HTTP transport is
 * swapped for one that re-targets each request through KnoxCall's ephemeral
 * proxy in transparent mode. The provider credential the SDK sets on its
 * Authorization header is LIFTED out-of-band (never forwarded as a raw header,
 * never logged), or — in escrow mode — replaced by the NAME of an escrowed
 * credential the server resolves and host-pins.
 *
 * These helpers hold no client state so they are unit-testable in isolation;
 * {@see KnoxCallHttpClient} wires them to {@see \KnoxCall\KnoxCall::ephemeral()}.
 */
final class WrapTransport
{
    /**
     * Headers the shim must NOT forward to the upstream: the provider
     * Authorization is lifted out-of-band; host/content-length are recomputed.
     */
    private const DROP_FORWARDED = ['authorization', 'host', 'content-length'];

    /**
     * Built-in route-around defaults. Mirrors the SERVER's raw-PAN refusal
     * (src/client-api/ephemeral-proxy.ts PAN_ENDPOINT_DENYLIST) so a wrapped
     * SDK's raw-card call is sent straight to Stripe instead of hard-failing on
     * the server-side 403. Deliberately conservative and identical in spirit to
     * the server list; the durable answer is tokenize-at-the-edge (plan §3).
     *
     * @return list<array{host: string, path_prefix: string, reason: string}>
     */
    public static function defaultRouteAround(): array
    {
        return [
            ['host' => 'api.stripe.com', 'path_prefix' => '/v1/tokens', 'reason' => 'raw-card endpoint (PCI): sent direct to the provider'],
            ['host' => 'api.stripe.com', 'path_prefix' => '/v1/sources', 'reason' => 'raw-card endpoint (PCI): sent direct to the provider'],
        ];
    }

    /**
     * PARITY §21's host contract: lower-case, surrounding whitespace and IPv6
     * brackets stripped, a single trailing dot stripped — so a trailing-dot
     * FQDN ("api.stripe.com.") can't dodge an exact-match rule. The server's
     * PAN denylist and the intercept manifest normalize the same way.
     */
    public static function normalizeHost(string $host): string
    {
        $h = strtolower(trim($host));
        if (str_starts_with($h, '[')) {
            $h = substr($h, 1);
        }
        if (str_ends_with($h, ']')) {
            $h = substr($h, 0, -1);
        }
        return (string) preg_replace('/\.$/', '', $h);
    }

    /**
     * First route-around rule matching this URL, or null.
     *
     * Trailing-dot FQDNs ("api.stripe.com.") resolve to the same host but would
     * dodge an exact-match rule — both sides are normalized. The server's PAN
     * denylist normalizes the same way, so the client route-around and the
     * server guarantee stay consistent.
     *
     * @param list<array{host: string, path_prefix?: string, reason: string}> $rules
     * @return array{host: string, path_prefix?: string, reason: string}|null
     */
    public static function matchRouteAround(string $url, array $rules): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $host = self::normalizeHost($host);
        foreach ($rules as $rule) {
            if ($host !== self::normalizeHost($rule['host'])) {
                continue;
            }
            $prefix = $rule['path_prefix'] ?? null;
            if ($prefix !== null && $prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    /**
     * Both-must-agree: a Stripe key's Test/Live prefix must match the KnoxCall
     * client's `sandbox` flag, so a test key can never be wrapped by a live
     * client (or vice versa) — the class of Test/Live collision the sandbox
     * invariant exists to prevent, one level up. Publishable keys (`pk_`) are
     * rejected outright: they are not server credentials. Non-Stripe schemes we
     * cannot classify are left alone (return without throwing).
     *
     * `$authorizationValue` is the full header value, e.g. "Bearer sk_live_…".
     *
     * @throws WrapSandboxMismatchError on a pk_ key or a Test/Live mismatch
     */
    public static function assertKeyMatchesSandbox(?string $authorizationValue, bool $sandbox): void
    {
        if ($authorizationValue === null || $authorizationValue === '') {
            return;
        }
        // Trim BEFORE stripping the scheme: leading whitespace would otherwise
        // stop the anchored `^Bearer` from matching, leaving "Bearer sk_live_…"
        // in $token, which the classifier can't parse — silently skipping the
        // check (Node review-hardening #1).
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', trim($authorizationValue)));
        if (preg_match('/^pk_(test|live)_/', $token) === 1) {
            throw new WrapSandboxMismatchError(
                'A Stripe publishable key (pk_…) is not a server credential and cannot be wrapped. '
                . 'Use a secret (sk_…) or restricted (rk_…) key.'
            );
        }
        if (preg_match('/^(?:sk|rk)_(test|live)_/', $token, $m) !== 1) {
            return; // unknown / non-Stripe scheme — nothing to assert
        }
        $keyIsTest = $m[1] === 'test';
        if ($keyIsTest !== $sandbox) {
            throw new WrapSandboxMismatchError(sprintf(
                'Provider key is a %s key but the KnoxCall client was constructed with sandbox=%s. '
                . 'Test keys require sandbox:true, live keys require sandbox:false — construct a matching client.',
                $keyIsTest ? 'TEST' : 'LIVE',
                $sandbox ? 'true' : 'false',
            ));
        }
    }

    /**
     * Validate caller-supplied route-around rules: a `host` that isn't a bare
     * DNS hostname (a scheme/port/path slipped in) can never match a parsed URL
     * host and would silently disable the rule — fail loud instead. Mirrors the
     * escrow() allowed-hosts contract and Node review-hardening #10.
     *
     * @param array<int, mixed> $rules
     * @throws WrapSandboxMismatchError on a rule that is not a bare DNS hostname
     */
    public static function assertRouteAroundRules(array $rules): void
    {
        foreach ($rules as $rule) {
            if (!is_array($rule) || !isset($rule['host']) || !is_string($rule['host'])) {
                throw new WrapSandboxMismatchError(
                    'Invalid routeAround rule: each rule needs a string "host" (a bare DNS hostname) and a "reason".'
                );
            }
            $h = trim($rule['host']);
            $parsed = parse_url('https://' . $h, PHP_URL_HOST);
            if ($h === '' || !is_string($parsed) || self::normalizeHost($parsed) !== self::normalizeHost($h)) {
                throw new WrapSandboxMismatchError(
                    'Invalid routeAround host ' . json_encode($rule['host'], JSON_UNESCAPED_SLASHES)
                    . ': expected a bare DNS hostname (no scheme, port, or path).'
                );
            }
        }
    }

    /**
     * Normalize a validated caller rule to the canonical internal shape
     * (host lower-cased; path_prefix kept only when a non-empty string).
     *
     * @param array{host: string, path_prefix?: string, reason?: string} $rule
     * @return array{host: string, path_prefix?: string, reason: string}
     */
    public static function normalizeRule(array $rule): array
    {
        $out = [
            'host' => strtolower(trim($rule['host'])),
            'reason' => isset($rule['reason']) && is_string($rule['reason']) && $rule['reason'] !== ''
                ? $rule['reason']
                : 'route-around',
        ];
        if (isset($rule['path_prefix']) && is_string($rule['path_prefix']) && $rule['path_prefix'] !== '') {
            $out['path_prefix'] = $rule['path_prefix'];
        }
        return $out;
    }

    /**
     * The upstream headers to forward (everything the SDK set except the
     * dropped ones). Keys keep their original casing.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function forwardableHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::DROP_FORWARDED, true)) {
                continue;
            }
            $out[(string) $name] = (string) $value;
        }
        return $out;
    }
}
