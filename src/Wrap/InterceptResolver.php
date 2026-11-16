<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

/**
 * The route-aware interception decision table — pure, no I/O
 * (docs/internal/sdk-wrapping/route-aware-interception-plan.md §2.2, PARITY §21.1).
 *
 * One request in, one decision out: send it DIRECT (untouched), through a
 * ROUTE (the manifest says an intercept-enabled Route covers this host + path;
 * the Route injects the stored secret), or through the EPHEMERAL proxy (the
 * caller listed the host, no Route covers it; the SDK's own credential is
 * lifted out-of-band). The order of the rules is the feature.
 *
 * Every SDK's resolver passes the SAME fixtures — sdk/fixtures/intercept-resolver.json
 * (tests/InterceptResolverTest.php runs them here) — so this is the PHP copy of
 * a contract whose reference is the Node SDK's src/intercept-resolver.ts.
 */
final class InterceptResolver
{
    public const MODE_DIRECT = 'direct';
    public const MODE_ROUTE = 'route';
    public const MODE_EPHEMERAL = 'ephemeral';

    /** KNOXCALL_INTERCEPT=off (or 0 / false): every route-aware transport becomes pass-through, per request. */
    public static function killSwitch(): bool
    {
        $v = getenv('KNOXCALL_INTERCEPT');
        return is_string($v) && in_array(strtolower(trim($v)), ['off', '0', 'false'], true);
    }

    /** KnoxCall's own domains are never intercepted, whatever a manifest or a host list says. */
    public static function isPlatformHost(string $host): bool
    {
        return $host === 'knoxcall.com' || str_ends_with($host, '.knoxcall.com');
    }

    /**
     * The request path with the route's base prefix removed (leading slash
     * kept), or null when the request is not under the base. "/crm/v3" covers
     * "/crm/v3" and "/crm/v3/x", never "/crm/v30" — the boundary is a path
     * segment. Mirrors the server's rebasePath (src/lib/route-target-host.ts).
     */
    public static function rebasePath(string $requestPath, string $basePath): ?string
    {
        $reqPath = $requestPath === '' ? '/' : $requestPath;
        if ($basePath === '/' || $basePath === '') {
            return str_starts_with($reqPath, '/') ? $reqPath : '/' . $reqPath;
        }
        if ($reqPath === $basePath) {
            return '/';
        }
        if (!str_starts_with($reqPath, $basePath . '/')) {
            return null;
        }
        $rest = substr($reqPath, strlen($basePath));
        return $rest === '' ? '/' : $rest;
    }

    /**
     * Manifest entries for a host in the order the server sorts them — longest
     * base_path first, then base_path, then slug — so the first entry whose
     * base covers the path is the longest-prefix, lowest-slug match.
     *
     * @param array<string, mixed>|null $manifest
     * @return list<array<string, mixed>>
     */
    public static function entriesForHost(?array $manifest, string $host): array
    {
        if ($manifest === null) {
            return [];
        }
        $routes = $manifest['routes'] ?? [];
        $out = [];
        foreach (is_array($routes) ? $routes : [] as $entry) {
            if (is_array($entry) && WrapTransport::normalizeHost((string) ($entry['host'] ?? '')) === $host) {
                $out[] = $entry;
            }
        }
        usort($out, static function (array $a, array $b): int {
            $ba = (string) ($a['base_path'] ?? '');
            $bb = (string) ($b['base_path'] ?? '');
            return [-strlen($ba), $ba, (string) ($a['slug'] ?? '')] <=> [-strlen($bb), $bb, (string) ($b['slug'] ?? '')];
        });
        return $out;
    }

    /**
     * Apply the decision table. First match wins.
     *
     * @param array{
     *   url: string, method: string,
     *   hosts: list<string>|'all',
     *   manifest: array<string, mixed>|null,
     *   own_hosts: list<string>,
     *   route_around: list<array{host: string, path_prefix?: string, reason: string}>,
     *   kill_switch?: bool, require_context?: bool, in_context?: bool
     * } $in — `hosts` / `own_hosts` are NORMALIZED hostnames
     * @return array{mode: string, reason: string, host: string, slug?: string, path?: string, entry?: array<string, mixed>, route_around_reason?: string}
     */
    public static function resolve(array $in): array
    {
        if (($in['kill_switch'] ?? false) === true) {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'kill_switch', 'host' => ''];
        }
        $url = (string) $in['url'];
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true)) {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'unparseable', 'host' => ''];
        }
        $host = WrapTransport::normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'unparseable', 'host' => ''];
        }

        if (self::isPlatformHost($host) || in_array($host, $in['own_hosts'], true)) {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'own_host', 'host' => $host];
        }

        $around = WrapTransport::matchRouteAround($url, $in['route_around']);
        if ($around !== null) {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'route_around', 'host' => $host, 'route_around_reason' => (string) ($around['reason'] ?? '')];
        }

        if (($in['require_context'] ?? false) === true && ($in['in_context'] ?? false) !== true) {
            return ['mode' => self::MODE_DIRECT, 'reason' => 'outside_context', 'host' => $host];
        }

        $entries = self::entriesForHost($in['manifest'], $host);
        $path = (string) ($parts['path'] ?? '');
        foreach ($entries as $entry) {
            $rebased = self::rebasePath($path, (string) ($entry['base_path'] ?? '/'));
            if ($rebased === null) {
                continue;
            }
            if (isset($parts['query']) && $parts['query'] !== '') {
                $rebased .= '?' . $parts['query'];
            }
            return ['mode' => self::MODE_ROUTE, 'reason' => 'manifest', 'host' => $host, 'slug' => (string) $entry['slug'], 'path' => $rebased, 'entry' => $entry];
        }

        $listed = $in['hosts'] === 'all' || in_array($host, $in['hosts'], true);
        if ($listed) {
            return ['mode' => self::MODE_EPHEMERAL, 'reason' => $entries === [] ? 'no_route' : 'no_base_path_match', 'host' => $host];
        }
        return ['mode' => self::MODE_DIRECT, 'reason' => 'unlisted', 'host' => $host];
    }
}
