<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

/**
 * The route-mode REFUSAL predicate (PARITY §21.1, "Refusal-driven refresh";
 * the cross-language contract is sdk/fixtures/route-refusal.json).
 *
 * A KnoxCall-origin refusal on the route data plane is the one response the
 * pipeline answers by refreshing its manifest ONCE and re-deciding ONCE:
 *
 *   - a 401 with no upstream stamp — the credential was refused, or the caller
 *     is not authenticated for the route it named. call() has already spent its
 *     one re-mint by the time we see this.
 *   - a 404 whose envelope error.type is route_not_found — since the founder's
 *     2026-09-26 decision an AUTHENTICATED key gets a real 404 for a route that
 *     does not resolve, and a stale manifest naming a Route deleted since the
 *     poll is exactly this. The environment_* types are refused as-is: a
 *     refresh cannot fix an environment.
 *
 * Any response carrying X-Knox-Upstream-Status (the route data plane's response
 * block) or X-Knox-Destination-Status (the ephemeral proxy's older spelling) is
 * the UPSTREAM's answer, whatever its status or body, and never a refusal.
 */
final class RouteRefusal
{
    /**
     * @param array<string, string> $headers the raw response headers, any casing
     */
    public static function isRefusal(int $status, array $headers, string $body): bool
    {
        if (self::header($headers, 'X-Knox-Upstream-Status') !== null || self::header($headers, 'X-Knox-Destination-Status') !== null) {
            return false;
        }
        if ($status === 401) {
            return true;
        }
        if ($status !== 404) {
            return false;
        }
        return self::envelopeType($body) === 'route_not_found';
    }

    /**
     * The envelope's error.type on a 404, or null for anything that is not the
     * Shape-A envelope {"error":{"type","message","request_id"}}.
     */
    private static function envelopeType(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        try {
            $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($parsed) || array_is_list($parsed)) {
            return null;
        }
        $error = $parsed['error'] ?? null;
        if (!is_array($error) || array_is_list($error)) {
            return null;
        }
        $type = $error['type'] ?? null;
        return is_string($type) ? $type : null;
    }

    /** @param array<string, string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                $s = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
                return $s === '' ? null : $s;
            }
        }
        return null;
    }
}
