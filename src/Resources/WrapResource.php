<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\Wrap\GuzzleMiddleware;
use KnoxCall\Wrap\InterceptPipeline;
use KnoxCall\Wrap\KnoxCallHttpClient;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Wrap a raw provider credential into KnoxCall custody in one call.
 *
 * The plaintext value is sent ONCE and is never returned — the response
 * carries only metadata (the stored secret's id, the name it is referenced
 * under, the provider label, the allowed upstream hosts, and the sandbox
 * flag). After escrow the credential lives inside the platform and is only
 * ever replayed to the pinned hosts by the proxy; callers reference it by
 * `name` from then on.
 */
class WrapResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Escrow a provider credential (POST /v1/wrap/credentials).
     *
     * Required input keys:
     *   - `provider` (string): free-form provider label, e.g. "stripe".
     *   - `name`     (string): the secret name the escrowed key is stored and
     *                          later referenced under.
     *   - `value`    (string): the raw provider credential. Sent once; never
     *                          returned. Treat it as write-only.
     *   - `hosts`    (list<string>): the allowed upstream hostnames — the
     *                          load-bearing pin the proxy enforces on replay.
     *
     * Returns the stored secret's metadata (the value is never echoed back):
     * `{secret_id, name, provider, allowed_hosts, sandbox}`.
     *
     * @param array{provider: string, name: string, value: string, hosts: list<string>} $input
     * @return array{secret_id: string, name: string, provider: string, allowed_hosts: list<string>, sandbox: bool}
     */
    public function escrow(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/wrap/credentials', null, $input));
    }

    /**
     * Mint a base-URL gateway token bound to an escrowed credential
     * (POST /v1/wrap/tokens).
     *
     * For SDKs that expose ONLY a base-URL override and no `fetch`/transport
     * hook (Resend, Mailgun, Airtable, …): set the returned `base_url` as the
     * wrapped SDK's base URL and the SDK's own key becomes a placeholder —
     * KnoxCall injects the escrowed secret server-side.
     *
     * ESCROW-ONLY: `secret` must already be escrowed (see {@see self::escrow()}).
     * The response's `token` is a bearer credential embedded in `base_url` —
     * treat it as a secret, and never store or log it.
     *
     * Input keys (only those present are sent):
     *   - `secret`      (string, required): the escrowed credential (name or id).
     *   - `host`        (string): upstream host to pin. Optional when the
     *                             credential allows exactly one.
     *   - `ttl_seconds` (int): token TTL in seconds. Omit for a non-expiring token.
     *   - `label`       (string): human label for the token list.
     *   - `style`       ('path'|'subdomain'): which base_url form to return.
     *                             `'path'` (`…/wg/<token>/<host>`) is always
     *                             available; `'subdomain'` (`<label>.wrap.<domain>`)
     *                             only when the operator has enabled the
     *                             wildcard-subdomain gateway (else the call 400s).
     *                             Omit to let the server choose (subdomain when
     *                             enabled, else path). NOTE: the subdomain form
     *                             carries the token in the TLS SNI (plaintext on
     *                             the wire) — weaker token confidentiality than
     *                             the path form; prefer a short `ttl_seconds`.
     *
     * The response echoes the chosen form back as `base_url_style`.
     *
     * @param array{secret: string, host?: string, ttl_seconds?: int, label?: string, style?: string} $input
     * @return array{id: string, token: string, base_url: string, base_url_style?: string, host: string, secret_id: string, sandbox: bool, expires_at: ?string}
     */
    public function gatewayUrl(array $input): array
    {
        $body = ['secret' => $input['secret']];
        foreach (['host', 'ttl_seconds', 'label', 'style'] as $key) {
            if (array_key_exists($key, $input)) {
                $body[$key] = $input[$key];
            }
        }
        return self::data($this->client->request('POST', '/v1/wrap/tokens', null, $body));
    }

    /**
     * The intercept manifest (GET /v1/wrap/intercept-manifest): which upstream
     * hosts an intercept-enabled Route covers in this space, for one
     * environment (default: the tenant's default), and the slug to send them
     * under. This is what a route-aware interceptor polls; `version` doubles as
     * the ETag. Scope: routes:read.
     *
     * Conditional form: pass `'if_none_match' => $version` (the `version` you
     * hold — not an ETag) and the SDK sends `If-None-Match: W/"<version>"`; a
     * `304` returns `null` — keep what you hold. Everything else (auth, the one
     * re-auth on 401, retries, a 200 with a newer manifest) is exactly the
     * unconditional call, which never returns null.
     *
     * @param array{environment?: string, if_none_match?: string} $opts
     * @return array{version: string, ttl_seconds: int, environment: string, sandbox: bool, routes: list<array{host: string, base_path: string, slug: string, route_id: string, requires_clients: bool, allowed_methods: ?list<string>, ambiguous?: bool, updated_at: ?string}>}|null
     */
    public function interceptManifest(array $opts = []): ?array
    {
        $query = isset($opts['environment']) ? ['environment' => (string) $opts['environment']] : null;
        $ifNoneMatch = isset($opts['if_none_match']) ? (string) $opts['if_none_match'] : '';
        if ($ifNoneMatch === '') {
            return self::data($this->client->request('GET', '/v1/wrap/intercept-manifest', $query));
        }
        // Conditional form (PARITY §21.1): the held version as the server's weak
        // ETag; its 304 comes back as the NotModified marker, mapped to null.
        $res = $this->client->request(
            'GET',
            '/v1/wrap/intercept-manifest',
            $query,
            null,
            ['If-None-Match' => self::manifestEtag($ifNoneMatch)],
            true,
        );
        return $res instanceof \KnoxCall\NotModified ? null : self::data($res);
    }

    /** The weak ETag the manifest endpoint sets for a `version` (src/client-api/wrap.ts). */
    public static function manifestEtag(string $version): string
    {
        return 'W/"' . $version . '"';
    }

    /**
     * Report uncovered-egress observations (POST /v1/wrap/egress-observations;
     * PARITY §21.3) — the thin typed wrapper the interceptor's reporter uses,
     * exported so an integrator can report by hand. At most 200 observations
     * per call. The body carries names, never values: a credential header's
     * NAME, the host, the first path segment, the method and counts. Scope:
     * routes:read.
     *
     * @param list<array{host: string, first_segment: string, method: string, header_name: string, count: int, first_seen: string, last_seen: string}> $observations
     * @param array{sdk?: string} $opts `sdk` is "<language>/<version>"; defaults to this SDK's
     * `redacted` (when present) counts accepted entries whose content the
     * server reduced, by reason (e.g. `first_segment_looks_like_credential`).
     *
     * @return array{accepted: int, dropped: int, reasons: array<string, int>, redacted?: array<string, int>}
     */
    public function reportEgressObservations(array $observations, array $opts = []): array
    {
        $body = [
            'sdk' => (string) ($opts['sdk'] ?? ('php/' . KnoxCall::SDK_VERSION)),
            'observations' => array_values($observations),
        ];
        return self::data($this->client->request('POST', '/v1/wrap/egress-observations', null, $body));
    }

    /**
     * List this space's gateway tokens (GET /v1/wrap/tokens). Metadata only —
     * the token itself is never returned.
     *
     * @return list<array{id: string, secret_id: string, host: string, label: ?string, created_at: string, expires_at: ?string, revoked_at: ?string, last_used_at: ?string}>
     */
    public function listGatewayTokens(): array
    {
        return self::data($this->client->request('GET', '/v1/wrap/tokens'))['tokens'];
    }

    /**
     * Revoke a single gateway token by id (DELETE /v1/wrap/tokens/{id}).
     * Immediately invalidates it.
     *
     * @return array{id: string, revoked: bool}
     */
    public function revokeGatewayToken(string $id): array
    {
        return self::data($this->client->request('DELETE', '/v1/wrap/tokens/' . rawurlencode($id)));
    }

    /**
     * Build a PSR-18 HTTP client that routes a wrapped third-party SDK's
     * requests through KnoxCall — the PHP equivalent of the Node SDK's
     * `wrap.fetch()`.
     *
     * Hand the returned client to any modern SDK that accepts a PSR-18 client — a
     * Guzzle 7 instance (Guzzle 7 implements PSR-18) or an SDK built on
     * php-http/httplug:
     *
     *   $http = $knox->wrap->httpClient(['routes' => 'auto']);       // route-aware
     *   $sdk  = new SomeVendor\Client([                              // SDK formats its own auth
     *       'apiKey'     => 'sk_live_…',
     *       'httpClient' => $http,                                   // any PSR-18 client slot
     *   ]);
     *
     * With `'routes' => 'auto'` the client is ROUTE-AWARE (PARITY §21.1): the
     * intercept manifest (GET /v1/wrap/intercept-manifest, this client's
     * environment) is refreshed lazily at its TTL and a request whose host +
     * path an intercept-enabled Route covers goes through that Route (the path
     * rebased under the Route's base path, query kept; the Route injects the
     * stored secret — no provider credential travels). Every other request goes
     * through the ephemeral proxy exactly as before — an explicit transport
     * treats every host as listed. The default stays `'off'`, so an existing
     * client keeps its behaviour. The returned {@see KnoxCallHttpClient} carries
     * `ready()`, `refresh()`, `manifest()`, `stop()`. PHP processes are usually
     * per-request: pass a PSR-16 `cache` to share the manifest across them.
     *
     * Transit mode (default): the wrapped SDK's own `Authorization` header is
     * lifted out-of-band and delivered to the upstream by the server — it never
     * transits as a raw header and is never logged. A wrapped Stripe key's
     * Test/Live prefix must match this client's `sandbox` flag (both-must-agree)
     * or a {@see \KnoxCall\Wrap\WrapSandboxMismatchError} is thrown. Escrow mode
     * (`['credential' => ['secret' => 'name']]`, or per host via `hosts`) sends
     * the escrowed credential NAME instead; the raw key stays in KnoxCall
     * custody. Requests matching a route-around rule (raw-card endpoints by
     * default), the client's own hosts and — with KNOXCALL_INTERCEPT=off —
     * everything are sent to the provider DIRECTLY, untouched.
     *
     * Unavailability (decision D4): route mode and escrow fail CLOSED (a
     * {@see \KnoxCall\Wrap\WrapNetworkException}); `'unavailable' => 'direct'`
     * opts TRANSIT traffic into going direct instead, firing `on_fallback`.
     *
     * Options (all optional):
     *   - `routes`      'auto'|'off' — consult the intercept manifest (default 'off').
     *   - `hosts`       list<string>|array<string, array{credential?: array, unavailable?: 'direct'}>
     *                   — per-host options for the ephemeral path (escrow / direct opt-in).
     *   - `unavailable` 'error'|'direct' — the D4 policy for transit (default 'error').
     *   - `cache`       a PSR-16 CacheInterface — shares the manifest across processes.
     *   - `credential`  ['secret' => string, 'scheme'? => string] — escrow mode.
     *                   Omit for transit mode.
     *   - `route`       string — legacy: send EVERY non-direct request via this durable
     *                   route (x-knoxcall-route), full path, no manifest lookup.
     *   - `auto_switch` bool — legacy (routes 'off' only): switch a host onto its
     *                   promoted route after the server advertises one.
     *   - `route_around`               list<array{host: string, path_prefix?: string, reason: string}>
     *                   — extra route-around rules, merged with the built-in defaults.
     *   - `disable_default_route_around` bool — drop the built-in raw-card defaults.
     *   - `on_route_around` callable(array{url, host, reason}): void
     *   - `on_promoted`     callable(array{host, slug}): void
     *   - `on_reroute`      callable(array{host, url, mode, slug?, reason}): void — before a KnoxCall send
     *   - `on_refresh`      callable(array{reason, version, added, removed}): void — after a manifest change
     *   - `on_manifest_error` callable(\Throwable): void
     *   - `on_unmatched_path` callable(array{host, url}): void — once per host + first path segment
     *   - `on_refused`      callable(array{host, url, slug, status, redecided}): void — after a refusal refresh
     *   - `on_fallback`     callable(array{host, url, error}): void — a transit request went direct
     *   - `observe_uncovered` bool — report uncovered egress (PARITY §21.3): calls sent DIRECT
     *                   because their host was `unlisted` while carrying a credential-bearing
     *                   header — host, first path segment, method and the header NAME (never its
     *                   value; never the query; never the body) — are counted and posted to
     *                   POST /v1/wrap/egress-observations (at 200 distinct keys, after ~60 s, on
     *                   `stop()` and at process shutdown). ON by default for {@see guzzleMiddleware()}
     *                   and for `'routes' => 'auto'`; `false` or KNOXCALL_OBSERVE_UNCOVERED=off
     *                   turns it off; nothing is reported while KNOXCALL_INTERCEPT=off. A 403 stops
     *                   reporting for the life of the client (warned once).
     *   - `on_observation_flush` callable(array{accepted: int, dropped: int}): void — after each accepted report
     *   - `direct_client`   PSR-18 ClientInterface — the transport for direct calls.
     *                   Defaults to php-http discovery / Guzzle 7.
     *   - `response_factory` PSR-17 ResponseFactoryInterface — builds the returned
     *                   response. Defaults to php-http discovery / nyholm/psr7.
     *   - `stream_factory`   PSR-17 StreamFactoryInterface — builds the response body.
     *
     * @param array<string, mixed> $opts
     *
     * @throws KnoxCallException when no PSR-17 factories are supplied and none
     *         can be auto-discovered
     */
    public function httpClient(array $opts = []): KnoxCallHttpClient
    {
        [$responseFactory, $streamFactory] = self::resolvePsr17($opts);
        return new KnoxCallHttpClient($this->client, $responseFactory, $streamFactory, $opts);
    }

    /**
     * A Guzzle middleware for a `HandlerStack` an SDK builds itself and lets you
     * push middleware onto (HubSpot's `Factory::createWithAccessToken($token, $client)`
     * takes a Guzzle client; many SDKs expose the stack). A request a Route
     * covers, or whose host is listed, is answered from KnoxCall without
     * reaching the stack's handler; everything else continues down the stack
     * untouched:
     *
     *   $stack = \GuzzleHttp\HandlerStack::create();
     *   $stack->push($knox->wrap->guzzleMiddleware(['hosts' => ['api.resend.com']]), 'knoxcall');
     *   $guzzle = new \GuzzleHttp\Client(['handler' => $stack]);
     *
     * Route discovery is ON by default here (`'routes' => 'auto'`) — only listed
     * or route-covered hosts are touched (decision D2). PHP has NO process-wide
     * HTTP seam (curl has no hook; `stream_wrapper` misses curl), so this and
     * {@see httpClient()} are the two seams; an SDK that builds its own curl
     * handle uses {@see gatewayUrl()}. Guzzle is not a dependency of this
     * package — the middleware only touches Guzzle's promise classes when it
     * runs. Options as {@see httpClient()} (minus `direct_client`); the
     * returned {@see \KnoxCall\Wrap\GuzzleMiddleware} exposes `pipeline()`
     * for `ready()` / `refresh()` / `manifest()` / `stop()`.
     *
     * @param array<string, mixed> $opts
     */
    public function guzzleMiddleware(array $opts = []): GuzzleMiddleware
    {
        [$responseFactory, $streamFactory] = self::resolvePsr17($opts);
        $pipeline = new InterceptPipeline($this->client, false, $opts + ['routes' => 'auto']);
        return new GuzzleMiddleware($pipeline, $responseFactory, $streamFactory);
    }

    /**
     * Resolve the PSR-17 ResponseFactory + StreamFactory the httpClient uses to
     * build responses. Explicit `response_factory` / `stream_factory` options
     * win; otherwise auto-discover (php-http/discovery, then nyholm/psr7). This
     * keeps the SDK PSR-17-implementation-agnostic — no concrete PSR-7 impl is a
     * hard dependency.
     *
     * @param array<string, mixed> $opts
     * @return array{0: ResponseFactoryInterface, 1: StreamFactoryInterface}
     */
    private static function resolvePsr17(array $opts): array
    {
        $responseFactory = $opts['response_factory'] ?? null;
        $streamFactory = $opts['stream_factory'] ?? null;
        $responseFactory = $responseFactory instanceof ResponseFactoryInterface ? $responseFactory : null;
        $streamFactory = $streamFactory instanceof StreamFactoryInterface ? $streamFactory : null;
        if ($responseFactory !== null && $streamFactory !== null) {
            return [$responseFactory, $streamFactory];
        }

        if (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
            $responseFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory();
            $streamFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
            return [$responseFactory, $streamFactory];
        }
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            return [$responseFactory ?? $factory, $streamFactory ?? $factory];
        }

        throw new KnoxCallException(
            'wrap httpClient needs a PSR-17 ResponseFactory and StreamFactory to build responses, but none '
            . 'were supplied and none could be auto-discovered. Pass ["response_factory" => $rf, '
            . '"stream_factory" => $sf] to httpClient(), or install php-http/discovery (it auto-detects your '
            . 'PSR-17 implementation) or nyholm/psr7.'
        );
    }
}
