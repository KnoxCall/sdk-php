<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\ConnectionException;
use KnoxCall\KnoxCall;
use KnoxCall\Warn;

/**
 * The ONE route-aware pipeline behind every PHP seam — the PSR-18 client
 * ({@see \KnoxCall\Resources\WrapResource::httpClient()}) and the Guzzle
 * middleware ({@see \KnoxCall\Resources\WrapResource::guzzleMiddleware()}) —
 * so the decision table, the own-host refusal, the kill switch and the D4
 * failure policy apply identically (route-aware-interception-plan.md §2–§3,
 * PARITY §21.1).
 *
 * Per request, first match wins ({@see InterceptResolver}): KNOXCALL_INTERCEPT=off
 * → direct · unparseable → direct · the client's own hosts and any knoxcall.com
 * host → direct · route-around rule → direct · a manifest entry covering host +
 * path → ROUTE (the Route injects the stored secret; no provider credential
 * travels) · a listed host → EPHEMERAL · otherwise direct.
 *
 * Route mode sends through the existing {@see KnoxCall::call()} pipeline
 * (x-knoxcall-route, the client's environment, retry + the one re-mint
 * inherited) — never a second proxy implementation. A KnoxCall-origin 401 in
 * route mode, after that re-mint, means a stale manifest or a refused
 * credential, and a KnoxCall-origin 404 route_not_found means a stale manifest
 * naming a Route that no longer resolves (PARITY §21; RouteRefusal): ONE
 * forced refresh, ONE re-decision, a resend only when the decision changed (a
 * routing refusal is answered before any upstream contact). Never a loop.
 *
 * Unavailability (D4): route mode and escrow fail CLOSED (the SDK's
 * {@see ConnectionException}); transit may opt into going direct
 * (`'unavailable' => 'direct'`, transport-wide or per host) — the seam then
 * performs the ORIGINAL request.
 */
final class InterceptPipeline
{
    /** Returned by {@see send()} when the seam must perform the original request itself. */
    public const DIRECT = null;

    private const HOOKS = ['on_reroute', 'on_refresh', 'on_manifest_error', 'on_unmatched_path', 'on_refused',
        'on_fallback', 'on_promoted', 'on_route_around', 'on_observation_flush'];

    /** @var list<array{host: string, path_prefix?: string, reason: string}> */
    private array $rules;

    /** @var array{secret: string, scheme?: string}|null transport-wide escrow */
    private ?array $credential;

    /** @var list<string> normalized listed hosts */
    private array $hosts = [];

    /** @var array<string, array{credential: array{secret: string, scheme?: string}|null, unavailable: string|null}> */
    private array $hostOptions = [];

    private bool $allHosts;
    private ?string $route;
    private bool $autoSwitch;
    private bool $requireContext;
    private string $unavailable;
    private ?InterceptManifestStore $store;
    /** Uncovered-egress reporter (PARITY §21.3); null when reporting is off. */
    private ?EgressObservationReporter $observer = null;

    /** @var array<string, callable> */
    private array $hooks = [];

    /** @var array<string, string> legacy per-instance auto-switch memory (host → slug) */
    private array $autoSwitched = [];

    /** @var array<string, true> */
    private array $unmatchedWarned = [];

    /**
     * @param KnoxCall $client
     * @param bool $allHosts the explicit-transport form: every request is "listed"
     * @param array<string, mixed> $opts see {@see \KnoxCall\Resources\WrapResource::httpClient()}
     */
    public function __construct(private readonly KnoxCall $client, bool $allHosts, array $opts = [])
    {
        $this->allHosts = $allHosts;

        // Escrow-vs-transit is decided by the credential shape; a malformed
        // `['credential' => []]` (or `['secret' => '']`) must NOT silently fall
        // through to transit and leak the SDK's raw key — fail loud (Node #7).
        $this->credential = self::normalizeCredential($opts['credential'] ?? null, 'wrap');

        $hosts = $opts['hosts'] ?? [];
        if (!is_array($hosts)) {
            throw new \InvalidArgumentException('hosts must be a list of bare hostnames or a map of host => options.');
        }
        foreach ($hosts as $key => $value) {
            [$host, $hostOpts] = is_string($key) ? [$key, is_array($value) ? $value : []] : [(string) $value, []];
            self::assertBareHost($host);
            $n = WrapTransport::normalizeHost($host);
            $this->hosts[] = $n;
            $this->hostOptions[$n] = [
                'credential' => self::normalizeCredential($hostOpts['credential'] ?? null, "intercept host {$host}"),
                'unavailable' => ($hostOpts['unavailable'] ?? null) === 'direct' ? 'direct' : null,
            ];
        }

        $caller = $opts['route_around'] ?? [];
        if (!is_array($caller)) {
            throw new \InvalidArgumentException('route_around must be a list of ["host" => …, "reason" => …] rules.');
        }
        WrapTransport::assertRouteAroundRules($caller);
        $rules = ($opts['disable_default_route_around'] ?? false) === true ? [] : WrapTransport::defaultRouteAround();
        foreach ($caller as $rule) {
            /** @var array{host: string, path_prefix?: string, reason?: string} $rule */
            $rules[] = WrapTransport::normalizeRule($rule);
        }
        $this->rules = $rules;

        $this->route = isset($opts['route']) && is_string($opts['route']) && $opts['route'] !== '' ? $opts['route'] : null;
        $this->autoSwitch = ($opts['auto_switch'] ?? false) === true;
        $this->requireContext = ($opts['require_context'] ?? false) === true;
        $this->unavailable = ($opts['unavailable'] ?? 'error') === 'direct' ? 'direct' : 'error';
        foreach (self::HOOKS as $hook) {
            if (isset($opts[$hook]) && is_callable($opts[$hook])) {
                $this->hooks[$hook] = $opts[$hook];
            }
        }
        foreach (array_keys($opts) as $key) {
            if (is_string($key) && str_starts_with($key, 'on_') && !in_array($key, self::HOOKS, true)) {
                // A typo'd hook would otherwise never fire — fail loud.
                throw new \InvalidArgumentException("unknown intercept hook \"{$key}\"");
            }
        }

        $routes = $opts['routes'] ?? 'off';
        if ($routes !== 'auto' && $routes !== 'off') {
            throw new \InvalidArgumentException('routes must be "auto" or "off".');
        }
        $this->store = null;
        if ($routes === 'auto') {
            $fetch = $opts['manifest_fetch'] ?? null; // test seam
            $this->store = new InterceptManifestStore(
                $fetch instanceof \Closure ? $fetch : fn (?string $ifNoneMatch = null): ?array => $this->fetchManifest($ifNoneMatch),
                fn (array $info) => $this->handleRefresh($info),
                $this->hooks['on_manifest_error'] ?? null,
                5.0,
                null,
                isset($opts['cache']) && is_object($opts['cache']) ? $opts['cache'] : null,
                $this->cacheKey(),
            );
        }

        // Uncovered-egress observations (PARITY §21.3). ON by default (founder
        // decision 2026-09-26) for the Guzzle middleware (only listed or
        // route-covered hosts are touched, so an unlisted credentialed call is
        // exactly what it should surface) and for the PSR-18 client with
        // routes 'auto'; 'observe_uncovered' => false or the environment turns
        // it off. The PSR-18 client treats every host as listed, so `unlisted`
        // never occurs there by construction — the reporter exists so the
        // contract (and the opt-out) reads the same in every form. The report
        // rides the SDK's own credential through request(); PHP has no
        // process-wide seam, so it cannot be intercepted.
        if (($opts['observe_uncovered'] ?? true) !== false && !EgressObservations::disabledByEnv()
            && (!$allHosts || $routes === 'auto')) {
            $report = $opts['observation_report'] ?? null; // test seam
            $this->observer = new EgressObservationReporter(
                $report instanceof \Closure
                    ? $report
                    : fn (array $observations): array => $this->client->wrap->reportEgressObservations($observations),
                $this->hooks['on_observation_flush'] ?? null,
            );
        }
    }

    /**
     * @param mixed $cred
     * @return array{secret: string, scheme?: string}|null
     */
    private static function normalizeCredential(mixed $cred, string $where): ?array
    {
        if ($cred === null) {
            return null;
        }
        $secret = is_array($cred) ? ($cred['secret'] ?? null) : null;
        if (!is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException(
                "{$where} credential must be [\"secret\" => <non-empty string>] for escrow mode; "
                . 'omit "credential" entirely for transit mode.'
            );
        }
        $out = ['secret' => $secret];
        if (isset($cred['scheme']) && is_string($cred['scheme'])) {
            $out['scheme'] = $cred['scheme'];
        }
        return $out;
    }

    /**
     * A listed host that is not a bare DNS hostname (a scheme/port/path slipped
     * in) could never match a parsed request host and would silently disable
     * the listing — fail loud instead.
     */
    private static function assertBareHost(string $host): void
    {
        $h = trim($host);
        $parsed = parse_url('https://' . $h, PHP_URL_HOST);
        if ($h === '' || !is_string($parsed) || WrapTransport::normalizeHost($parsed) !== WrapTransport::normalizeHost($h)) {
            throw new WrapSandboxMismatchError(
                'Invalid intercept host ' . json_encode($host, JSON_UNESCAPED_SLASHES)
                . ': expected a bare DNS hostname (no scheme, port, or path).'
            );
        }
    }

    /** The manifest this pipeline is deciding on, or null (routes off / not loaded). */
    public function manifest(): ?array
    {
        return $this->store?->manifest();
    }

    /** The store (min-refresh-gap tuning, permission state), or null with routes off. */
    public function store(): ?InterceptManifestStore
    {
        return $this->store;
    }

    /** Load the manifest now if it is stale (the first attempt included). Never throws for a manifest failure. */
    public function ready(): ?array
    {
        return $this->store?->ensure();
    }

    /** Refresh the manifest now (no-op with routes off). */
    public function refresh(): ?array
    {
        return $this->store?->refresh('manual', true);
    }

    /** Stop polling and drop the manifest (and flush the uncovered-egress reporter once more); listed hosts stay ephemeral, the rest direct. */
    public function stop(): void
    {
        $this->store?->stop();
        $this->observer?->stop();
    }

    /** The uncovered-egress reporter (PARITY §21.3), or null when reporting is off. */
    public function observer(): ?EgressObservationReporter
    {
        return $this->observer;
    }

    /**
     * Apply the decision table to one request (refreshing the manifest first
     * when it is stale).
     *
     * @return array{mode: string, reason: string, host: string, slug?: string, path?: string, entry?: array<string, mixed>, route_around_reason?: string}
     */
    public function decide(string $url, string $method, bool $inContext = true): array
    {
        return InterceptResolver::resolve([
            'url' => $url,
            'method' => $method,
            'hosts' => $this->allHosts ? 'all' : $this->hosts,
            'manifest' => $this->store?->ensure(),
            'own_hosts' => $this->ownHosts(),
            'route_around' => $this->rules,
            'kill_switch' => InterceptResolver::killSwitch(),
            'require_context' => $this->requireContext,
            'in_context' => $inContext,
        ]);
    }

    /**
     * A direct decision (a seam calls this before performing the original
     * request itself): fires the route-around hook, and — for `unlisted` only
     * — records an uncovered-egress observation when the request carries a
     * credential-bearing header (PARITY §21.3). Observed AFTER the decision,
     * BEFORE the direct send; never throws into the application's request.
     * `$headers` is the request's header map (any casing) or a closure
     * producing it — read only for an `unlisted` decision.
     *
     * @param array{mode: string, reason: string, host: string, route_around_reason?: string} $decision
     * @param array<string, string|list<string>>|\Closure|null $headers
     */
    public function directDecided(array $decision, string $url, string $method = 'GET', array|\Closure|null $headers = null): void
    {
        if ($decision['reason'] === 'route_around') {
            $this->fire('on_route_around', ['url' => $url, 'host' => $decision['host'], 'reason' => $decision['route_around_reason'] ?? '']);
            return;
        }
        if ($decision['reason'] !== 'unlisted' || $this->observer === null) {
            return;
        }
        try {
            $map = $headers instanceof \Closure ? $headers() : ($headers ?? []);
            $obs = EgressObservations::observationFor($url, $method, is_array($map) ? $map : []);
            if ($obs !== null) {
                $this->observer->record($obs);
            }
        } catch (\Throwable) {
            // best-effort: telemetry must never reach the application's request.
        }
    }

    /**
     * Send a non-direct decision through KnoxCall. Returns the raw
     * `['status', 'headers', 'body']` result of the route or ephemeral hop, or
     * {@see DIRECT} (null) when the seam must perform the original request
     * itself. `$headers` is the wrapped SDK's flattened header map (any
     * casing); `$body` the request bytes ('' for none — held in memory, so a
     * resend after a routing refusal is replayable by construction).
     *
     * @param array{mode: string, reason: string, host: string, slug?: string, path?: string} $decision
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     * @throws ConnectionException when KnoxCall is unreachable and the policy is fail-closed
     */
    public function send(array $decision, string $url, string $method, array $headers, string $body): ?array
    {
        $method = strtoupper($method);
        $forwardable = WrapTransport::forwardableHeaders($headers);
        $auth = self::headerValue($headers, 'Authorization');

        // Legacy explicit route: / auto-switch memory (pre-manifest callers):
        // every non-direct request goes via that slug with the full path.
        $legacy = $this->legacyRouteFor($decision);
        if ($legacy !== null) {
            $this->fire('on_reroute', ['host' => $decision['host'], 'url' => $url, 'mode' => 'route', 'slug' => $legacy, 'reason' => 'explicit_route']);
            return $this->sendRoute($legacy, self::fullPath($url), $method, $forwardable, $body);
        }

        if ($decision['mode'] === InterceptResolver::MODE_ROUTE) {
            return $this->sendRouteWithRefresh($decision, $url, $method, $forwardable, $auth, $body);
        }

        // Ephemeral.
        if ($decision['reason'] === 'no_base_path_match') {
            $key = $decision['host'] . ' ' . self::firstSegment($url);
            if (!isset($this->unmatchedWarned[$key])) {
                $this->unmatchedWarned[$key] = true;
                $this->fire('on_unmatched_path', ['host' => $decision['host'], 'url' => $url]);
            }
        }
        $this->fire('on_reroute', ['host' => $decision['host'], 'url' => $url, 'mode' => 'ephemeral', 'reason' => $decision['reason']]);
        return $this->sendEphemeral($decision, $url, $method, $forwardable, $auth, $body);
    }

    /** @return list<string> the client's management and data-plane hosts, never intercepted */
    private function ownHosts(): array
    {
        $out = [];
        foreach ([$this->client->baseUrl(), $this->client->proxyBaseUrl()] as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $h = parse_url($raw, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $out[] = WrapTransport::normalizeHost($h);
            }
        }
        return $out;
    }

    /**
     * The store's fetch: the client's environment, and the held version as
     * If-None-Match (a 304 comes back as null — PARITY §21.1).
     *
     * @return array<string, mixed>|null
     */
    private function fetchManifest(?string $ifNoneMatch = null): ?array
    {
        $env = $this->client->environment();
        $opts = $env !== null && $env !== '' ? ['environment' => $env] : [];
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $opts['if_none_match'] = $ifNoneMatch;
        }
        return $this->client->wrap->interceptManifest($opts);
    }

    /** One key per client + environment, so processes of the same app share a cached manifest. */
    private function cacheKey(): string
    {
        return 'knoxcall:intercept-manifest:' . sha1(implode('|', [
            $this->client->baseUrl(),
            (string) $this->client->proxyBaseUrl(),
            $this->client->sandbox() ? 'sandbox' : 'live',
            (string) $this->client->environment(),
        ]));
    }

    /**
     * Warn once per entry that will be refused or is ambiguous, then forward
     * to the caller's hook.
     *
     * @param array{reason: string, version: string, added: list<array<string, mixed>>, removed: list<array<string, mixed>>} $info
     */
    private function handleRefresh(array $info): void
    {
        foreach ($info['added'] as $e) {
            $slug = (string) ($e['slug'] ?? '');
            $hostBase = (string) ($e['host'] ?? '') . (string) ($e['base_path'] ?? '');
            if (($e['requires_clients'] ?? false) === true) {
                Warn::warnOnce(
                    "KNOXCALL_INTERCEPT_REQUIRES_CLIENTS:{$slug}",
                    "KnoxCall route \"{$slug}\" ({$hostBase}) requires a registered client; a bearer-only SDK call will be "
                    . 'refused (403). Register this process as a client of the route, or leave the route out of interception.'
                );
            }
            if (($e['ambiguous'] ?? false) === true) {
                Warn::warnOnce(
                    "KNOXCALL_INTERCEPT_AMBIGUOUS:{$hostBase}",
                    "KnoxCall: more than one intercept-enabled route covers {$hostBase}; the lexically lowest slug is used. "
                    . 'Disable the others.'
                );
            }
        }
        $this->fire('on_refresh', $info);
    }

    /** @param array{mode: string, host: string} $decision */
    private function legacyRouteFor(array $decision): ?string
    {
        if ($this->route !== null) {
            return $this->route;
        }
        if ($decision['mode'] === InterceptResolver::MODE_EPHEMERAL && $this->autoSwitch) {
            return $this->autoSwitched[$decision['host']] ?? null;
        }
        return null;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function sendRoute(string $slug, string $path, string $method, array $headers, string $body): array
    {
        return $this->client->call($slug, [
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body === '' ? null : $body,
            // Every route-mode reroute is marked (PARITY §21.2) — the manifest
            // decision and the legacy explicit `route` form alike: both are a
            // third-party SDK's call this pipeline redirected, which is what the
            // API Log's "SDK intercept" origin means.
            '_origin' => KnoxCall::SDK_INTERCEPT_ORIGIN,
        ]);
    }

    /**
     * @param array{mode: string, reason: string, host: string, slug?: string, path?: string} $decision
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function sendRouteWithRefresh(array $decision, string $url, string $method, array $headers, ?string $auth, string $body): ?array
    {
        $this->fire('on_reroute', ['host' => $decision['host'], 'url' => $url, 'mode' => 'route', 'slug' => $decision['slug'], 'reason' => $decision['reason']]);
        $raw = $this->sendRoute((string) $decision['slug'], (string) $decision['path'], $method, $headers, $body);
        if ($this->store === null || !self::isRouteRefusal($raw)) {
            return $raw;
        }

        $this->store->refresh('route_refused', true);
        $again = $this->decide($url, $method);
        $changed = $again['mode'] !== InterceptResolver::MODE_ROUTE
            || ($again['slug'] ?? null) !== $decision['slug']
            || ($again['path'] ?? null) !== $decision['path'];
        $this->fire('on_refused', ['host' => $decision['host'], 'url' => $url, 'slug' => $decision['slug'], 'status' => $raw['status'],
            'redecided' => $changed ? $again['mode'] : null]);
        if (!$changed) {
            return $raw;
        }
        switch ($again['mode']) {
            case InterceptResolver::MODE_ROUTE:
                $this->fire('on_reroute', ['host' => $again['host'], 'url' => $url, 'mode' => 'route', 'slug' => $again['slug'], 'reason' => $again['reason']]);
                return $this->sendRoute((string) $again['slug'], (string) $again['path'], $method, $headers, $body);
            case InterceptResolver::MODE_EPHEMERAL:
                $this->fire('on_reroute', ['host' => $again['host'], 'url' => $url, 'mode' => 'ephemeral', 'reason' => $again['reason']]);
                return $this->sendEphemeral($again, $url, $method, $headers, $auth, $body);
            default:
                return self::DIRECT;
        }
    }

    /**
     * @param array{mode: string, reason: string, host: string} $decision
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function sendEphemeral(array $decision, string $url, string $method, array $headers, ?string $auth, string $body): ?array
    {
        $hostOpts = $this->hostOptions[$decision['host']] ?? ['credential' => null, 'unavailable' => null];
        $credential = $hostOpts['credential'] ?? $this->credential;

        $opts = ['method' => $method, 'headers' => $headers, 'mode' => 'transparent'];
        if ($body !== '') {
            $opts['body'] = $body;
        }
        if ($credential !== null) {
            // Escrow mode — the raw key never travels; the SDK's own key is an
            // ignored placeholder (no Test/Live assertion).
            $opts['upstream_auth_secret'] = $credential['secret'];
            if (isset($credential['scheme'])) {
                $opts['upstream_auth_scheme'] = $credential['scheme'];
            }
        } else {
            // Transit mode — lift the SDK's own Authorization header out-of-band.
            WrapTransport::assertKeyMatchesSandbox($auth, $this->client->sandbox());
            if ($auth !== null && $auth !== '') {
                $opts['upstream_authorization'] = $auth;
            }
        }

        try {
            $raw = $this->client->ephemeral($url, $opts);
        } catch (ConnectionException $e) {
            // D4: fail closed by default. Going direct is honoured only for
            // TRANSIT traffic — the key is in the process there. Escrow has
            // nothing to go direct with.
            $policy = $hostOpts['unavailable'] ?? $this->unavailable;
            if ($policy === 'direct' && $credential === null) {
                $this->fire('on_fallback', ['host' => $decision['host'], 'url' => $url, 'error' => $e]);
                return self::DIRECT;
            }
            throw $e;
        }

        // Promoted-route hint: a Route now covers this host. With a manifest the
        // hint is a signal to refresh it — the manifest is the truth. Without one
        // (legacy auto_switch), remember the slug directly.
        $slug = self::headerValue($raw['headers'], 'x-knox-promoted-route');
        if ($slug !== null && $slug !== '' && $decision['host'] !== '') {
            $this->fire('on_promoted', ['host' => $decision['host'], 'slug' => $slug]);
            if ($this->store !== null) {
                $this->store->hint();
            } elseif ($this->autoSwitch) {
                $this->autoSwitched[$decision['host']] = $slug;
            }
        }
        return $raw;
    }

    /**
     * A KnoxCall-origin refusal on the route data plane: a 401 with neither
     * spelling of "the upstream answered", or a 404 whose envelope error.type
     * is route_not_found (PARITY §21.1; the predicate and its cross-language
     * fixtures live in RouteRefusal).
     *
     * @param array{status: int, headers: array<string, string>, body: string} $raw
     */
    private static function isRouteRefusal(array $raw): bool
    {
        return RouteRefusal::isRefusal($raw['status'], $raw['headers'], $raw['body']);
    }

    private static function fullPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    private static function firstSegment(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? ltrim($path, '/') : '';
        return '/' . explode('/', $path, 2)[0];
    }

    /**
     * Case-insensitive lookup on a header map.
     *
     * @param array<string, string> $headers
     */
    public static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return (string) $v;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $info */
    private function fire(string $hook, array $info): void
    {
        if (isset($this->hooks[$hook])) {
            ($this->hooks[$hook])($info);
        }
    }
}
