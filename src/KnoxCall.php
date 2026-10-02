<?php

declare(strict_types=1);

namespace KnoxCall;

use KnoxCall\Auth\AccessToken;
use KnoxCall\Auth\ClientCredentials;
use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\CredentialsFileLock;
use KnoxCall\Auth\DpopKeyPair;
use KnoxCall\Auth\OIDCTokenExchange;
use KnoxCall\Auth\StoredCredentials;
use KnoxCall\Resources\RoutesResource;
use KnoxCall\Resources\SecretsResource;
use KnoxCall\Resources\WebhooksResource;
use KnoxCall\Resources\ClientsResource;
use KnoxCall\Resources\OAuthClientsResource;
use KnoxCall\Resources\EnvironmentsResource;
use KnoxCall\Resources\ApiKeysResource;
use KnoxCall\Resources\RolesResource;
use KnoxCall\Resources\AccountResource;
use KnoxCall\Resources\AuditLogsResource;
use KnoxCall\Resources\LogsResource;
use KnoxCall\Resources\AgentsResource;
use KnoxCall\Resources\CryptoResource;
use KnoxCall\Resources\PkiResource;
use KnoxCall\Resources\VaultsResource;
use KnoxCall\Resources\DynamicDbResource;
use KnoxCall\Resources\AiGatewayResource;
use KnoxCall\Resources\WorkflowsResource;
use KnoxCall\Resources\WrapResource;
use KnoxCall\Resources\OpportunitiesResource;
use KnoxCall\Cli\Common as CliCommon;
use KnoxCall\Cli\Login as CliLogin;
use KnoxCall\Transport\CurlTransport;
use KnoxCall\Transport\TransportInterface;

/**
 * KnoxCall API client.
 *
 * $client = new KnoxCall(['api_key' => 'kc_...']);
 * $routes = $client->routes->list();
 * $res = $client->call('route-uuid', ['path' => '/v1/orders']);
 *
 * $printnode = $client->route('route-uuid', ['environment' => 'production']);
 * $computers = $printnode->get('/computers');
 *
 * Tenant: optional. Resolution: the 'tenant' option > KNOXCALL_TENANT env >
 * auto-discovery. Management requests never need it (the server resolves the
 * tenant from the credential); only the data-plane hostname does, and it is
 * resolved lazily on the first call(): the /oauth/token response carries a
 * 'tenant' extension member, and pre-acquired tokens fall back to one
 * GET /v1/account. Discovery runs at most once per client instance; an
 * explicit tenant, an explicit 'proxy_base_url', or a self-hosted 'base_url'
 * skips it entirely. If discovery fails, a KnoxCallException tells the caller
 * to pass tenant=... or set KNOXCALL_TENANT. With credentials in the
 * environment, `new KnoxCall()` just works.
 *
 * Default environment: the 'environment' option (or KNOXCALL_ENVIRONMENT)
 * applies to every call(); per-call and bound-route values win over it.
 *
 * Sandbox / Test mode: pass 'sandbox' => true to target the Stripe-style
 * isolated test environment — management calls go to
 * https://sandbox.knoxcall.com and the data plane to
 * https://sandbox-{tenant}.knoxcall.com. Requires a tk_test_… API key.
 * Ignored when an explicit 'base_url' (or a base-URL env var) is provided.
 *
 * Credentials (pick one — the flat options need no class imports):
 *   'client_id' + 'client_secret'       OAuth client-credentials grant
 *   'access_token' or 'api_key'         two spellings, one behavior; takes
 *                                       kc_… OAuth tokens and legacy tk_/AKE…
 *                                       keys (legacy keys travel as the
 *                                       x-knoxcall-key header on call())
 *   'credentials'  => new ClientCredentials($id, $secret)
 *                   | new AccessToken($token)
 *                   | new OIDCTokenExchange($subjectToken, $issuer)
 *                   | new StoredCredentials($path?, $profile?)   `knoxcall login` file
 *
 * Environment fallbacks fill credentials only when NO credential option was
 * passed: KNOXCALL_ACCESS_TOKEN (wins) / KNOXCALL_API_KEY, then the
 * `knoxcall login` credentials file (~/.knoxcall/credentials.json;
 * KNOXCALL_CREDENTIALS_FILE / KNOXCALL_PROFILE override path/profile), then
 * KNOXCALL_CLIENT_ID + KNOXCALL_CLIENT_SECRET. PHP has no cloud auto-detect,
 * so the whole chain is explicit > env, with the file slotted between the
 * two env credential forms (PARITY §2 slot 2). The file's tenant/base_url
 * seed the client only when not set explicitly (constructor, env, and
 * 'sandbox' => true always win). Conflicting explicit options
 * (e.g. api_key + client_id) throw at construction, before any env fill.
 * Base URLs: KNOXCALL_BASE_URL (canonical; the legacy KNOXCALL_API_BASE_URL
 * spelling is still accepted) and KNOXCALL_PROXY_BASE_URL.
 *
 * Token cache model: in typical PHP-FPM deployments each request is its own
 * process, so the in-memory token cache lives for at most one request — under
 * client_credentials that means one token mint per PHP request. There is no
 * built-in shared store; if that overhead matters, mint a token out of band,
 * cache it yourself (APCu/Redis), and construct the client with
 * `new AccessToken($cachedToken)`. Long-running workers (CLI, queues, Octane)
 * keep the cache for the process lifetime and get refresh-ahead
 * (min(300s, lifetime/2)) plus stale-but-valid fallback automatically.
 * StoredCredentials are the exception: the credentials file itself is the
 * cross-process cache — its fresh-token fast path is a local file read (no
 * HTTP), so per-request FPM processes stay cheap without a shared store.
 */
class KnoxCall
{
    public const SDK_VERSION = '1.1.0';
    private const USER_AGENT = 'knoxcall-php/' . self::SDK_VERSION;

    // The dated API version this SDK is built against. Sent as the
    // `KnoxCall-Version` header on every management request so the SDK stays
    // pinned to a known API shape even after the server ships a newer default
    // (src/client-api/versioning.ts). Must be a version the server's registry
    // knows, or the request is rejected 400 — override with the `api_version`
    // constructor option only to a version the server still accepts.
    private const DEFAULT_API_VERSION = '2026-08-05';

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];
    // Honor a server Retry-After up to this long; beyond it, fail fast so
    // callers can apply their own scheduling instead of blocking a worker.
    private const RETRY_AFTER_CAP_MS = 30_000;
    private const REFRESH_AHEAD_SECONDS = 300.0;
    // A cached token inside the refresh-ahead window is still usable this long
    // before real expiry; used as a fallback when the token endpoint is down.
    private const STALE_TOKEN_MIN_REMAINING_SECONDS = 10.0;
    // The locked credentials-file refresh HTTP call is bounded to this so the
    // lock is provably released well within the lock's stale window
    // (CredentialsFileLock staleAfterSeconds = 60s) — otherwise a slow token
    // endpoint could hold the lock long enough for a peer to break it and
    // double-refresh the single-use token (PARITY §2, hardened 2026-08).
    private const REFRESH_TIMEOUT_MS = 30_000;
    // Hosts whose data plane lives on per-tenant subdomains; anything else
    // (local dev / self-hosted) serves the proxy on the same host as the API.
    private const PROXY_HOSTS = ['api.knoxcall.com', 'api-staging.knoxcall.com'];
    // Sandbox management hosts — the data plane lives on
    // sandbox-{tenant}.knoxcall.com instead of {tenant}.knoxcall.com.
    private const SANDBOX_PROXY_HOSTS = ['sandbox.knoxcall.com', 'sandbox-staging.knoxcall.com'];
    // The labels under knoxcall.com that are NOT a tenant's data-plane host
    // (management + marketing); see dataPlanePathPrefix().
    private const NON_TENANT_LABELS = ['api', 'sandbox', 'api-staging', 'sandbox-staging', 'www', 'staging', 'admin'];
    // A tenant slug becomes a data-plane hostname
    // (https://<slug>.knoxcall.com), so before it is interpolated into a host
    // it MUST be a bare DNS label. A slug adopted from a token response,
    // /v1/account, or the credentials file that isn't (e.g. "evil.com#") would
    // otherwise misdirect the tenant's bearer token to another host (PARITY §2,
    // added 2026-08). Case-insensitive, mirrors node core.ts TENANT_SLUG_RE.
    private const TENANT_SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';
    // Auth-bearing headers the proxy data plane consumes to identify the
    // caller. The SDK's own credential is the SOLE authority on the data
    // plane, so any caller-supplied copy of these is stripped from
    // call()/ephemeral() headers before the SDK sets its own — otherwise an
    // integrator forwarding untrusted end-user headers could inject an
    // alternate proxy identity (x-knoxcall-agent-*) or, on the legacy-key
    // path, a Bearer Authorization the proxy would honor over the SDK's own
    // x-knoxcall-key (PARITY §5, hardened 2026-08). Case-insensitive.
    private const PROXY_AUTH_HEADERS = [
        'authorization',
        'dpop',
        'x-knoxcall-key',
        'x-knoxcall-agent-id',
        'x-knoxcall-agent-token',
    ];

    // Markers the SDK owns on the data plane (PARITY §21.2). Not auth — the
    // server treats them as informational — but a caller-supplied copy is
    // stripped the same way, so an app cannot relabel its own calls as
    // interceptor traffic through the headers array. The interceptors set the
    // marker through call()'s internal '_origin' option, never through headers.
    private const SDK_MARKER_HEADERS = [
        'x-knoxcall-origin',
    ];

    /**
     * The one value call()'s internal '_origin' option accepts: the route-aware
     * interceptors' reroute marker, sent as `x-knoxcall-origin: sdk-intercept`
     * so the API Log can show which Route calls the SDK rerouted from a
     * third-party SDK and which were direct.
     *
     * @internal
     */
    public const SDK_INTERCEPT_ORIGIN = 'sdk-intercept';

    public readonly RoutesResource $routes;
    public readonly SecretsResource $secrets;
    public readonly WebhooksResource $webhooks;
    public readonly WorkflowsResource $workflows;
    public readonly ClientsResource $clients;
    public readonly OAuthClientsResource $oauthClients;
    public readonly EnvironmentsResource $environments;
    public readonly ApiKeysResource $apiKeys;
    public readonly RolesResource $roles;
    public readonly AccountResource $account;
    public readonly AuditLogsResource $auditLogs;

    /**
     * Per-call proxy request log + Merkle inclusion proofs. Not the change
     * log — that is $auditLogs.
     */
    public readonly LogsResource $logs;
    public readonly AgentsResource $agents;
    public readonly CryptoResource $crypto;
    public readonly PkiResource $pki;
    public readonly VaultsResource $vaults;
    public readonly DynamicDbResource $dynamicDb;
    public readonly AiGatewayResource $aiGateway;
    public readonly WrapResource $wrap;
    public readonly OpportunitiesResource $opportunities;

    private string $baseUrl;
    private ?string $proxyBaseUrl;
    private ?string $tenant;
    private ?string $environment;
    private ClientCredentials|AccessToken|OIDCTokenExchange|StoredCredentials|null $credentials;
    private TransportInterface $transport;
    private int $timeoutMs;
    private int $retryMaxAttempts;
    private int $retryBaseDelayMs;
    private int $retryMaxDelayMs;
    /** Dated API version sent as the `KnoxCall-Version` header on management requests. */
    private string $apiVersion;
    /** @var (callable(object): mixed)|null */
    private $jsonDefault;
    /** @var callable(): float */
    private $clock;
    /** Subdomain shape to derive once the tenant is known ('plain'|'sandbox'|null). */
    private ?string $proxyShape = null;
    /**
     * Test/Live mode flag. The wrap httpClient's both-must-agree check reads it
     * (a Stripe sk_/rk_ key's Test/Live prefix must match this flag), so it is
     * kept as instance state and exposed via {@see self::sandbox()}.
     */
    private bool $sandbox = false;
    /** DPoP mode: 'auto' (default) | 'always' | 'never'. See PARITY.md §7. */
    private string $dpopMode;
    /** Keypair once 'always' construction or the 'auto' upgrade generated one. */
    private ?DpopKeyPair $dpopKey = null;

    /** @var array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}|null */
    private ?array $tokenCache = null;

    public function __construct(#[\SensitiveParameter] array $opts = [])
    {
        // Tenant is optional: when absent it is discovered from the first
        // token response (or /v1/account for pre-acquired tokens). Only the
        // data-plane hostname needs it client-side; management calls resolve
        // the tenant server-side from the credential.
        $this->tenant = $opts['tenant'] ?? (getenv('KNOXCALL_TENANT') ?: null);
        // Default environment for data-plane calls; per-call and bound-route
        // values win, and null means the server picks the tenant default.
        $this->environment = $opts['environment'] ?? (getenv('KNOXCALL_ENVIRONMENT') ?: null);
        $this->credentials = $this->resolveCredentials($opts);
        $this->transport = $opts['transport'] ?? new CurlTransport();
        $this->timeoutMs = (int) ($opts['timeout_ms'] ?? 30000);
        $this->retryMaxAttempts = (int) ($opts['retry_max_attempts'] ?? 3);
        $this->retryBaseDelayMs = (int) ($opts['retry_base_delay_ms'] ?? 100);
        $this->retryMaxDelayMs = (int) ($opts['retry_max_delay_ms'] ?? 5000);
        $this->apiVersion = (string) ($opts['api_version'] ?? self::DEFAULT_API_VERSION);
        $this->jsonDefault = $opts['json_default'] ?? null;
        $this->clock = $opts['clock'] ?? static fn (): float => microtime(true);

        // DPoP (RFC 9449, PARITY §7): 'auto' starts Bearer and upgrades when
        // the oauth client requires proofs; 'always' generates the keypair up
        // front; 'never' opts out (a DPoP-bound token then raises).
        $dpop = $opts['dpop'] ?? 'auto';
        if (!in_array($dpop, ['auto', 'always', 'never'], true)) {
            throw new \InvalidArgumentException(
                'invalid dpop mode "' . (is_scalar($dpop) ? (string) $dpop : gettype($dpop)) . '"'
                . ' — expected "auto", "always", or "never"'
            );
        }
        $this->dpopMode = $dpop;
        if ($dpop === 'always') {
            $this->dpopKey = DpopKeyPair::generate();
        }

        // Sandbox / Test mode (Stripe-style isolated environment): defaults
        // the management base to https://sandbox.knoxcall.com and the data
        // plane to https://sandbox-{tenant}.knoxcall.com. Requires a
        // tk_test_… API key. An explicit base_url (or the base-URL env vars)
        // wins over the sandbox default — mirrors node core.ts.
        $sandbox = ($opts['sandbox'] ?? false) === true;
        $this->sandbox = $sandbox;
        $defaultBase = $sandbox ? 'https://sandbox.knoxcall.com' : 'https://api.knoxcall.com';
        // KNOXCALL_BASE_URL is canonical; the legacy KNOXCALL_API_BASE_URL
        // spelling is still accepted, canonical wins when both are set.
        $this->baseUrl = rtrim(
            $opts['base_url'] ?? getenv('KNOXCALL_BASE_URL') ?: getenv('KNOXCALL_API_BASE_URL') ?: $defaultBase,
            '/',
        );

        if (isset($opts['proxy_base_url'])) {
            $this->proxyBaseUrl = rtrim($opts['proxy_base_url'], '/');
        } elseif ($p = getenv('KNOXCALL_PROXY_BASE_URL')) {
            $this->proxyBaseUrl = rtrim($p, '/');
        } else {
            $this->deriveProxyFromBase();
        }

        // Credentials-file seeding (PARITY §2): the file's tenant/base_url
        // apply only when the caller didn't set them explicitly — the
        // constructor options, the env vars, and 'sandbox' => true all win.
        if ($this->credentials instanceof StoredCredentials) {
            $this->seedFromStoredCredentials(
                $this->credentials,
                baseUrlExplicit: isset($opts['base_url'])
                    || (bool) getenv('KNOXCALL_BASE_URL')
                    || (bool) getenv('KNOXCALL_API_BASE_URL')
                    || $sandbox,
                proxyExplicit: isset($opts['proxy_base_url']) || (bool) getenv('KNOXCALL_PROXY_BASE_URL'),
            );
        }

        // Plaintext http:// to a non-loopback host sends the credential and
        // access tokens in the clear — warn once (never block: http://localhost
        // is the normal dev case). Emitted after the base URL, the data-plane
        // proxy URL, and any StoredCredentials re-derivation have all settled.
        if (Warn::isInsecureRemoteUrl($this->baseUrl)) {
            Warn::warnOnce(
                'KNOXCALL_INSECURE_BASE_URL',
                "KnoxCall base URL {$this->baseUrl} uses plaintext http:// to a non-loopback host — "
                . 'credentials and access tokens will be sent unencrypted. '
                . 'Use https:// (plain http:// is only safe for localhost).',
            );
        }
        if ($this->proxyBaseUrl !== null && Warn::isInsecureRemoteUrl($this->proxyBaseUrl)) {
            Warn::warnOnce(
                'KNOXCALL_INSECURE_PROXY_URL',
                "KnoxCall proxy base URL {$this->proxyBaseUrl} uses plaintext http:// to a non-loopback host — "
                . 'proxied requests and the SDK credential will be sent unencrypted. '
                . 'Use https:// (plain http:// is only safe for localhost).',
            );
        }

        $this->routes = new RoutesResource($this);
        $this->secrets = new SecretsResource($this);
        $this->webhooks = new WebhooksResource($this);
        $this->workflows = new WorkflowsResource($this);
        $this->clients = new ClientsResource($this);
        $this->oauthClients = new OAuthClientsResource($this);
        $this->environments = new EnvironmentsResource($this);
        $this->apiKeys = new ApiKeysResource($this);
        $this->roles = new RolesResource($this);
        $this->account = new AccountResource($this);
        $this->auditLogs = new AuditLogsResource($this);
        $this->logs = new LogsResource($this);
        $this->agents = new AgentsResource($this);
        $this->crypto = new CryptoResource($this);
        $this->pki = new PkiResource($this);
        $this->vaults = new VaultsResource($this);
        $this->dynamicDb = new DynamicDbResource($this);
        $this->aiGateway = new AiGatewayResource($this);
        $this->wrap = new WrapResource($this);
        $this->opportunities = new OpportunitiesResource($this);
    }

    /**
     * Derive the data-plane base from the management host + tenant: known
     * hosted API hosts map to the (sandbox-prefixed) per-tenant subdomain
     * (null when the tenant is not yet known — resolved lazily by
     * ensureProxyBaseUrl() on the first call()); local dev / self-hosted
     * serves the proxy on the same host, so no tenant is needed.
     */
    /**
     * Where the data plane lives under a proxy base (PARITY §5).
     *
     * On a KnoxCall CLOUD tenant host the proxy is served ONLY under `/api`
     * (`https://{slug}.knoxcall.com/api/<upstream path>`: server.ts strips the
     * prefix, and every other path on that host is the dashboard). `call()`
     * therefore places the upstream path under `/api` whenever the base names
     * such a host and carries no path of its own — the derived plain/sandbox
     * shapes and an explicit override alike, any port. Every other base is
     * used verbatim: self-hosted mounts the proxy at `/`, and a base that
     * already carries a path IS the entry point (the agent bundle spells the
     * same base as `…knoxcall.com/api`). Until 2026-09-25 nothing added the
     * prefix, so the documented `'path' => '/users'` answered the dashboard
     * HTML on every tenant host; the live smokes hid it by hard-coding
     * `'path' => '/api/get'`.
     */
    public static function dataPlanePathPrefix(string $proxyBaseUrl): string
    {
        $path = parse_url($proxyBaseUrl, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && $path !== '/') {
            return '';
        }
        $host = strtolower((string) parse_url($proxyBaseUrl, PHP_URL_HOST));
        if (!preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.knoxcall\.com$/', $host, $m)) {
            return '';
        }
        return in_array($m[1], self::NON_TENANT_LABELS, true) ? '' : '/api';
    }

    private function deriveProxyFromBase(): void
    {
        $baseHost = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        if (in_array($baseHost, self::SANDBOX_PROXY_HOSTS, true)) {
            $this->proxyShape = 'sandbox';
            $this->proxyBaseUrl = $this->tenant !== null
                ? 'https://sandbox-' . self::assertTenantSlug($this->tenant) . '.knoxcall.com'
                : null;
        } elseif (in_array($baseHost, self::PROXY_HOSTS, true)) {
            $this->proxyShape = 'plain';
            $this->proxyBaseUrl = $this->tenant !== null
                ? 'https://' . self::assertTenantSlug($this->tenant) . '.knoxcall.com'
                : null;
        } else {
            $this->proxyShape = null;
            $this->proxyBaseUrl = $this->baseUrl;
        }
    }

    /**
     * Seed tenant/base_url from the `knoxcall login` credentials file when
     * the caller did not set them explicitly (explicit constructor/env/
     * sandbox values always win). Missing/malformed file → no-op (the chain
     * already vetted presence; a vanished file surfaces at token fetch).
     */
    private function seedFromStoredCredentials(StoredCredentials $stored, bool $baseUrlExplicit, bool $proxyExplicit): void
    {
        $path = $stored->resolvedPath();
        $record = $path !== null ? CredentialsFile::readProfile($path, $stored->resolvedProfile()) : null;
        if ($record === null) {
            return;
        }
        if ($this->tenant === null && isset($record['tenant']) && is_string($record['tenant']) && $record['tenant'] !== '') {
            $this->tenant = $record['tenant'];
        }
        if (!$baseUrlExplicit && isset($record['base_url']) && is_string($record['base_url']) && $record['base_url'] !== '') {
            $this->baseUrl = rtrim($record['base_url'], '/');
        }
        if (!$proxyExplicit) {
            // Re-derive the data plane from the (possibly updated) tenant
            // and base_url.
            $this->deriveProxyFromBase();
        }
    }

    private function resolveCredentials(#[\SensitiveParameter] array $opts): ClientCredentials|AccessToken|OIDCTokenExchange|StoredCredentials|null
    {
        // Mutual exclusion runs on the EXPLICITLY passed options, before any
        // env fill — a conflict is a constructor-time error even when the
        // environment could have "completed" the picture.
        $flat = array_values(array_filter(
            ['client_id', 'client_secret', 'access_token', 'api_key'],
            static fn (string $name): bool => isset($opts[$name]),
        ));
        if (isset($opts['credentials']) && $flat !== []) {
            throw new \InvalidArgumentException('credentials cannot be combined with ' . implode(', ', $flat));
        }
        if (isset($opts['access_token'], $opts['api_key'])) {
            throw new \InvalidArgumentException(
                'pass either access_token or api_key, not both (they are two spellings of the same credential)'
            );
        }
        $token = $opts['access_token'] ?? $opts['api_key'] ?? null;
        if ($token !== null && (isset($opts['client_id']) || isset($opts['client_secret']))) {
            throw new \InvalidArgumentException('a token credential cannot be combined with client_id/client_secret');
        }
        if (isset($opts['client_id']) !== isset($opts['client_secret'])) {
            throw new \InvalidArgumentException('client_id and client_secret must be provided together');
        }

        if (isset($opts['credentials'])) {
            $c = $opts['credentials'];
            if (!($c instanceof ClientCredentials || $c instanceof AccessToken
                || $c instanceof OIDCTokenExchange || $c instanceof StoredCredentials)) {
                throw new \InvalidArgumentException(
                    'credentials must be a ClientCredentials, AccessToken, OIDCTokenExchange, or StoredCredentials instance'
                );
            }
            return $c;
        }
        if ($token !== null) {
            return new AccessToken((string) $token);
        }
        if (isset($opts['client_id'])) {
            return new ClientCredentials((string) $opts['client_id'], (string) $opts['client_secret']);
        }

        // Env fill runs only when no explicit credential option was passed.
        // Two token spellings, one behavior; ACCESS_TOKEN wins when both set.
        $envToken = getenv('KNOXCALL_ACCESS_TOKEN') ?: getenv('KNOXCALL_API_KEY') ?: null;
        if ($envToken) {
            return new AccessToken($envToken);
        }
        // Slot 2 (PARITY §2): the credentials file written by `knoxcall
        // login` sits between the pre-acquired env token and env client
        // credentials (PHP has no cloud auto-detect, so resolution is
        // explicit > env). Presence = file exists AND the selected profile
        // parses; anything missing or malformed skips the provider silently.
        if (CredentialsFile::profileAvailable(CredentialsFile::resolvePath(), CredentialsFile::resolveProfile())) {
            return new StoredCredentials();
        }
        $envClientId = getenv('KNOXCALL_CLIENT_ID') ?: null;
        $envClientSecret = getenv('KNOXCALL_CLIENT_SECRET') ?: null;
        if ($envClientId && $envClientSecret) {
            return new ClientCredentials($envClientId, $envClientSecret);
        }
        return null;
    }

    /**
     * Secrets must never leak through var_dump(): the token cache and
     * credentials are excluded (credential objects also self-redact).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'tenant' => $this->tenant,
            'baseUrl' => $this->baseUrl,
            'proxyBaseUrl' => $this->proxyBaseUrl,
            'credentials' => $this->credentials !== null ? $this->credentials::class : null,
            'tokenCache' => $this->tokenCache !== null ? '[REDACTED]' : null,
        ];
    }

    // -- Token management ----------------------------------------------------

    private function now(): float
    {
        return ($this->clock)();
    }

    private function refreshAhead(float $lifetime): float
    {
        // For short-lived tokens a fixed 5-minute window would mean "always
        // expired", forcing a token fetch per request; never use more than
        // half the token's lifetime as the refresh-ahead window.
        return $lifetime > 0 ? min(self::REFRESH_AHEAD_SECONDS, $lifetime / 2) : self::REFRESH_AHEAD_SECONDS;
    }

    /** @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string} */
    private function getToken(): array
    {
        if ($this->tokenCache !== null
            && $this->tokenCache['expires_at'] - $this->now() > $this->refreshAhead($this->tokenCache['lifetime'])
        ) {
            return $this->adoptTenant($this->tokenCache);
        }

        try {
            $this->tokenCache = $this->fetchToken();
        } catch (KnoxCallException $e) {
            // Token endpoint unreachable or erroring during the refresh-ahead
            // window: a cached token that hasn't actually expired is still
            // good — use it rather than failing the caller's request.
            if ($this->tokenCache !== null
                && $this->tokenCache['expires_at'] - $this->now() > self::STALE_TOKEN_MIN_REMAINING_SECONDS
            ) {
                return $this->adoptTenant($this->tokenCache);
            }
            throw $e;
        }
        return $this->adoptTenant($this->tokenCache);
    }

    /**
     * Learn the tenant from a token response when constructed without one.
     *
     * @param array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string} $token
     * @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}
     */
    private function adoptTenant(array $token): array
    {
        if ($this->tenant === null && $token['tenant'] !== null) {
            $this->tenant = $token['tenant'];
        }
        return $token;
    }

    /**
     * Resolve the data-plane base URL, discovering the tenant if needed.
     *
     * Tenant discovery: the token response carries the slug (the 'tenant'
     * extension member); pre-acquired tokens (and older servers) fall back
     * to one GET /v1/account. The result is cached on the instance, so
     * discovery runs at most once per client.
     */
    private function ensureProxyBaseUrl(): string
    {
        if ($this->proxyBaseUrl !== null) {
            return $this->proxyBaseUrl;
        }
        if ($this->tenant === null) {
            $this->getToken(); // may adopt the tenant from the token response
        }
        if ($this->tenant === null) {
            $account = $this->request('GET', '/v1/account');
            $slug = is_array($account) && is_array($account['data'] ?? null)
                ? ($account['data']['slug'] ?? null)
                : null;
            if (!is_string($slug) || $slug === '') {
                throw new KnoxCallException(
                    'could not discover the tenant from the credential — ' .
                    'pass tenant=... or set the KNOXCALL_TENANT environment variable'
                );
            }
            $this->tenant = $slug;
        }
        // Validate the (possibly just-discovered/adopted) slug before it
        // becomes a host — a hostile slug from the token response or
        // /v1/account must never redirect the bearer token elsewhere.
        $slug = self::assertTenantSlug($this->tenant);
        return $this->proxyBaseUrl = $this->proxyShape === 'sandbox'
            ? "https://sandbox-{$slug}.knoxcall.com"
            : "https://{$slug}.knoxcall.com";
    }

    /**
     * A tenant slug becomes the data-plane host https://<slug>.knoxcall.com,
     * so it must be a bare DNS label; reject anything else with the SDK's
     * typed bootstrap error before it is interpolated into a URL (PARITY §2).
     */
    private static function assertTenantSlug(string $tenant): string
    {
        if (preg_match(self::TENANT_SLUG_RE, $tenant) !== 1) {
            throw new KnoxCallException(
                'invalid tenant slug "' . $tenant . '" — expected a DNS label; '
                . 'refusing to derive a data-plane host from it'
            );
        }
        return $tenant;
    }

    /** @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string} */
    private function fetchToken(): array
    {
        if ($this->credentials === null) {
            // The credential auto-detect chain gave up: no explicit option, env
            // token, `knoxcall login` file, or env client credentials (PARITY
            // §1). Distinctly typed so callers can branch on "not logged in —
            // offer KnoxCall::login()"; still a KnoxCallException subclass, so
            // existing catches keep working.
            throw new NotAuthenticatedException(
                'No credentials: run `knoxcall login`, or set api_key or client_id + client_secret'
            );
        }

        if ($this->credentials instanceof AccessToken) {
            return [
                'access_token' => new Redacted($this->credentials->accessToken()),
                'token_type' => 'Bearer',
                'expires_at' => $this->now() + 3600,
                'lifetime' => 3600.0,
                'tenant' => null, // pre-acquired tokens discover via /v1/account
            ];
        }

        if ($this->credentials instanceof StoredCredentials) {
            // Tokens come from the `knoxcall login` credentials file: the
            // file is the cross-process cache and the sole refresh authority
            // (single-use rotated refresh tokens). Scope/DPoP posture is
            // whatever login negotiated, so the DPoP upgrade below never
            // applies here.
            return $this->fetchStoredToken($this->credentials);
        }

        if ($this->credentials instanceof ClientCredentials) {
            $form = [
                'grant_type' => 'client_credentials',
                'client_id' => $this->credentials->clientId,
                'client_secret' => $this->credentials->clientSecret(),
            ];
        } else {
            $form = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'subject_token' => $this->credentials->subjectToken(),
                'audience' => 'knoxcall:api',
            ];
        }

        try {
            return $this->tokenRequest($form, $this->dpopKey);
        } catch (ApiException $e) {
            // DPoP auto-upgrade (PARITY §7): the oauth client record requires
            // proofs. Generate a keypair and retry the token request ONCE —
            // the client operates as DPoP thereafter.
            if ($this->dpopMode === 'auto' && $this->dpopKey === null && $e->errorCode === 'invalid_dpop_proof') {
                $key = DpopKeyPair::generate();
                $token = $this->tokenRequest($form, $key);
                $this->dpopKey = $key;
                return $token;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, string> $form
     * @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}
     */
    private function tokenRequest(array $form, ?DpopKeyPair $key): array
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
        if ($key !== null) {
            $headers['DPoP'] = $key->sign('POST', $this->baseUrl . '/oauth/token');
        }
        $response = $this->transport->send('POST', $this->baseUrl . '/oauth/token', $headers, http_build_query($form), $this->timeoutMs);

        $data = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            throw ApiException::fromResponse($response['status'], $data, $response['headers']);
        }
        if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            // e.g. an HTML page from an edge proxy with a 200 status.
            throw new AuthenticationException(
                'token endpoint returned an unexpected response',
                $response['status'],
                null,
                $response['headers']['x-request-id'] ?? null,
                $response['headers'],
            );
        }
        $tokenType = ($data['token_type'] ?? 'Bearer') === 'DPoP' ? 'DPoP' : 'Bearer';
        if ($tokenType === 'DPoP' && $key === null) {
            // Sending "Authorization: DPoP" without a proof would 401-loop
            // forever — fail loudly instead (mode 'never', or a server that
            // binds tokens without challenging first).
            throw new KnoxCallException(
                'server issued a DPoP-bound token but this client holds no DPoP keypair — ' .
                'construct with dpop "auto" or "always"'
            );
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (float) $data['expires_in'] : 3600.0;
        $tenant = $data['tenant'] ?? null; // extension member, used for tenant auto-discovery
        return [
            'access_token' => new Redacted($data['access_token']),
            'token_type' => $tokenType,
            'expires_at' => $this->now() + $expiresIn,
            'lifetime' => $expiresIn,
            'tenant' => is_string($tenant) && $tenant !== '' ? $tenant : null,
        ];
    }

    // -- Stored credentials (`knoxcall login` file) ---------------------------

    /**
     * Produce a usable token from the credentials file (PARITY §2).
     *
     * Fast path: stored access token with >60s validity — a local file read,
     * no HTTP. Otherwise: acquire the sibling lock, RE-READ the file (another
     * process may have already refreshed), re-check freshness, and only then
     * run the refresh-token grant, atomically writing the rotated refresh
     * token back before the lock is released. In per-request PHP-FPM
     * processes the fast path is the effective cross-request token cache.
     *
     * @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}
     */
    private function fetchStoredToken(StoredCredentials $stored): array
    {
        $path = $stored->resolvedPath();
        $profile = $stored->resolvedProfile();
        $record = $path !== null ? CredentialsFile::readProfile($path, $profile) : null;
        if ($record === null) {
            // Detection saw the profile but it has since vanished/corrupted.
            throw new AuthenticationException(CredentialsFile::RELOGIN_MESSAGE, 401);
        }
        $cached = $this->storedTokenFromRecord($record);
        if ($cached !== null) {
            return $cached;
        }

        $lock = new CredentialsFileLock($path);
        $lock->acquire();
        try {
            $record = CredentialsFile::readProfile($path, $profile);
            if ($record === null) {
                throw new AuthenticationException(CredentialsFile::RELOGIN_MESSAGE, 401);
            }
            $cached = $this->storedTokenFromRecord($record);
            if ($cached !== null) {
                return $cached; // another process refreshed while we waited
            }
            return $this->refreshStoredToken($record, $path, $profile);
        } finally {
            $lock->release();
        }
    }

    /**
     * The fresh-token fast path: use the stored access token while it has
     * more than 60s of validity left; null means "refresh under the lock".
     *
     * @param array<string, mixed> $record
     * @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}|null
     */
    private function storedTokenFromRecord(array $record): ?array
    {
        $token = $record['access_token'] ?? null;
        $expiresAt = CredentialsFile::parseExpiry($record['access_token_expires_at'] ?? null);
        if (!is_string($token) || $token === '' || $expiresAt === null) {
            return null;
        }
        if ($expiresAt - $this->now() <= CredentialsFile::FRESH_WINDOW_SECONDS) {
            return null;
        }
        $tenant = $record['tenant'] ?? null;
        // No refresh_token is ever cached in-process on purpose: the file is
        // the sole refresh authority, so no in-process fallback can ever
        // replay a consumed (rotated) token. lifetime 0 = unknown → the fixed
        // refresh-ahead window applies; near expiry getToken() re-reads the
        // file (cheap, no HTTP) and this 60s freshness window governs the
        // actual refresh.
        return [
            'access_token' => new Redacted($token),
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'lifetime' => 0.0,
            'tenant' => is_string($tenant) && $tenant !== '' ? $tenant : null,
        ];
    }

    /**
     * refresh_token grant with the file's client_id (the tenant's real CLI
     * client — public, no secret). Caller MUST hold the file lock: the
     * server's refresh tokens are single-use with family revocation on
     * reuse, so the rotated token is written back atomically BEFORE the
     * lock is released.
     *
     * @param array<string, mixed> $record
     * @return array{access_token: Redacted, token_type: string, expires_at: float, lifetime: float, tenant: ?string}
     */
    private function refreshStoredToken(array $record, string $path, string $profile): array
    {
        $refreshToken = $record['refresh_token'] ?? null;
        $clientId = $record['client_id'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '' || !is_string($clientId) || $clientId === '') {
            throw new AuthenticationException(CredentialsFile::RELOGIN_MESSAGE, 401);
        }

        $form = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ];
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
        // Bound the locked refresh below the lock's stale window so the lock is
        // provably released before a peer could break it as stale and
        // double-refresh the single-use token (PARITY §2, hardened 2026-08).
        $refreshTimeoutMs = min($this->timeoutMs, self::REFRESH_TIMEOUT_MS);
        $response = $this->transport->send('POST', $this->baseUrl . '/oauth/token', $headers, http_build_query($form), $refreshTimeoutMs);

        $data = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            $e = ApiException::fromResponse($response['status'], $data, $response['headers']);
            if ($e->errorCode === 'invalid_grant') {
                // Revoked family or expired refresh token — unrecoverable here.
                throw new AuthenticationException(
                    CredentialsFile::RELOGIN_MESSAGE,
                    $e->statusCode,
                    $e->errorCode,
                    $e->requestId,
                    $e->responseHeaders,
                    $e->responseBody,
                );
            }
            throw $e;
        }
        if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new AuthenticationException(
                'token endpoint returned an unexpected response',
                $response['status'],
                null,
                $response['headers']['x-request-id'] ?? null,
                $response['headers'],
            );
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (float) $data['expires_in'] : 3600.0;
        $now = $this->now();

        // Write back the rotated refresh token BEFORE releasing the lock
        // (caller holds it) — the old one is already consumed server-side.
        $updated = $record;
        $updated['access_token'] = $data['access_token'];
        $updated['access_token_expires_at'] = CredentialsFile::formatExpiry($now + $expiresIn);
        // scope + the extension members (RFC 6749 §5.1): tenant, and
        // client_id — the real per-tenant CLI client id.
        foreach (['refresh_token', 'scope', 'tenant', 'client_id'] as $member) {
            if (isset($data[$member]) && is_string($data[$member]) && $data[$member] !== '') {
                $updated[$member] = $data[$member];
            }
        }
        CredentialsFile::writeProfile($path, $profile, $updated);

        $tenant = $updated['tenant'] ?? null;
        return [
            'access_token' => new Redacted($data['access_token']),
            'token_type' => 'Bearer',
            'expires_at' => $now + $expiresIn,
            'lifetime' => $expiresIn,
            'tenant' => is_string($tenant) && $tenant !== '' ? $tenant : null,
        ];
    }

    /** Fresh per-request proof for a DPoP-bound token (new jti/iat + ath). */
    private function dpopProof(string $method, string $url, string $accessToken): string
    {
        if ($this->dpopKey === null) {
            throw new KnoxCallException('DPoP-bound token held without a DPoP keypair — this is a bug, please report it');
        }
        return $this->dpopKey->sign($method, $url, $accessToken);
    }

    // -- Header / body helpers -------------------------------------------------

    /**
     * Case-insensitive header map: lowercase-key => [emitName, value].
     *
     * @param array<string, string|int|float> $headers
     * @return array<string, array{string, string}>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string) $name)] = [(string) $name, (string) $value];
        }
        return $out;
    }

    /** @param array<string, array{string, string}> $headers */
    private static function setHeader(array &$headers, string $name, string $value): void
    {
        $headers[strtolower($name)] = [$name, $value];
    }

    /** @param array<string, array{string, string}> $headers */
    private static function setDefaultHeader(array &$headers, string $name, string $value): void
    {
        $key = strtolower($name);
        if (!isset($headers[$key])) {
            $headers[$key] = [$name, $value];
        }
    }

    /**
     * Remove a header case-insensitively (the map is keyed by lowercase name).
     *
     * @param array<string, array{string, string}> $headers
     */
    private static function removeHeader(array &$headers, string $name): void
    {
        unset($headers[strtolower($name)]);
    }

    /**
     * @param array<string, array{string, string}> $headers
     * @return array<string, string>
     */
    private static function headerMap(array $headers): array
    {
        $out = [];
        foreach ($headers as [$name, $value]) {
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Serialize a request body, defaulting Content-Type to JSON.
     *
     * Strings pass through untouched (pre-serialized payloads — pair them
     * with a caller-supplied Content-Type); anything else is JSON-encoded
     * after normalization, which converts DateTimeInterface to ISO 8601 and
     * runs the client's `json_default` hook on other unsupported objects.
     *
     * @param array<string, array{string, string}> $headers
     */
    private function encodeBody(mixed $body, array &$headers): ?string
    {
        if ($body === null) {
            return null;
        }
        self::setDefaultHeader($headers, 'Content-Type', 'application/json');
        if (is_string($body)) {
            return $body;
        }
        $encoded = json_encode($this->jsonNormalize($body), JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new KnoxCallException('Could not JSON-encode request body: ' . json_last_error_msg());
        }
        return $encoded;
    }

    private function jsonNormalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->jsonNormalize($v);
            }
            return $out;
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->$k = $this->jsonNormalize($v);
            }
            return $out;
        }
        if (is_object($value) && !($value instanceof \JsonSerializable) && $this->jsonDefault !== null) {
            return $this->jsonNormalize(($this->jsonDefault)($value));
        }
        return $value;
    }

    private function buildUrl(string $base, string $path, ?array $query): string
    {
        $url = $base . (str_starts_with($path, '/') ? $path : '/' . $path);
        if ($query) {
            $filtered = array_filter($query, static fn ($v) => $v !== null);
            if ($filtered) {
                $url .= '?' . http_build_query($filtered);
            }
        }
        return $url;
    }

    // -- Retry helpers ---------------------------------------------------------

    private function backoffDelayMs(int $attempt): int
    {
        // Half-jitter: random within [exp/2, exp] so a retry never fires
        // immediately but herds still spread out.
        $exp = $this->retryBaseDelayMs * (2 ** ($attempt - 1));
        $jittered = $exp * (0.5 + mt_rand() / mt_getrandmax() / 2);
        return (int) min($this->retryMaxDelayMs, $jittered);
    }

    private function retryDelayMs(ApiException $e, int $attempt): int
    {
        // A 429's Retry-After, and a 503's (`dependency_unavailable` — the
        // server says how long the dependency needs). Both capped: beyond the
        // cap the caller's own scheduling beats a blocked worker.
        if (($e instanceof RateLimitException || $e instanceof ServerException) && $e->retryAfter !== null) {
            return (int) min($e->retryAfter * 1000, self::RETRY_AFTER_CAP_MS);
        }
        return $this->backoffDelayMs($attempt);
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    // -- Management API --------------------------------------------------------

    /**
     * Make an authenticated management-API request (used by the resources).
     *
     * Retries 408/429/500/502/503/504 with half-jitter exponential backoff
     * (Retry-After honored, capped at 30s); a 401 purges the cached token and
     * retries once with fresh credentials. Mutating requests carry a ULID
     * X-Idempotency-Key that stays stable across retries. SDK-set
     * Authorization always wins; a caller-set Content-Type is respected.
     *
     * With `$allowNotModified` a `304 Not Modified` is a success with no body
     * and returns {@see NotModified} instead of decoding the empty body; auth,
     * the one transparent re-auth on 401 and the retry policy are unchanged,
     * and `$headers` (the `If-None-Match`) ride on every attempt.
     *
     * @param array<string, string> $headers extra request headers (case-insensitive)
     */
    public function request(string $method, string $path, ?array $query = null, mixed $body = null, array $headers = [], bool $allowNotModified = false): mixed
    {
        $method = strtoupper($method);
        $idempotencyKey = in_array($method, ['GET', 'HEAD'], true) ? null : Ulid::generate();

        $reauthDone = false;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptRequest($method, $path, $query, $body, $headers, $idempotencyKey, $allowNotModified);
            } catch (ApiException $e) {
                // One transparent re-auth: attemptRequest purged the cached
                // token on 401, so the immediate retry runs with freshly
                // minted credentials.
                if ($e->statusCode === 401 && !$reauthDone && $attempt < $this->retryMaxAttempts) {
                    $reauthDone = true;
                    continue;
                }
                if ($attempt >= $this->retryMaxAttempts
                    || !in_array($e->statusCode, self::RETRYABLE_STATUSES, true)
                ) {
                    throw $e;
                }
                $this->sleepMs($this->retryDelayMs($e, $attempt));
            } catch (ConnectionException $e) {
                if ($attempt >= $this->retryMaxAttempts) {
                    throw $e;
                }
                $this->sleepMs($this->backoffDelayMs($attempt));
            }
        }
    }

    /** @param array<string, string> $callerHeaders */
    private function attemptRequest(string $method, string $path, ?array $query, mixed $body, array $callerHeaders, ?string $idempotencyKey, bool $allowNotModified = false): mixed
    {
        $token = $this->getToken();
        $url = $this->buildUrl($this->baseUrl, $path, $query);

        $h = self::normalizeHeaders($callerHeaders);
        self::setDefaultHeader($h, 'Accept', 'application/json');
        self::setDefaultHeader($h, 'User-Agent', self::USER_AGENT);
        // Pin the management API version; the server validates it and rejects
        // an unknown version 400. A caller-supplied header still wins.
        self::setDefaultHeader($h, 'KnoxCall-Version', $this->apiVersion);
        // SDK-set Authorization always wins over caller headers.
        self::setHeader($h, 'Authorization', $token['token_type'] . ' ' . $token['access_token']->expose());
        if ($token['token_type'] === 'DPoP') {
            self::setHeader($h, 'DPoP', $this->dpopProof($method, $url, $token['access_token']->expose()));
        }
        if ($idempotencyKey !== null) {
            self::setHeader($h, 'X-Idempotency-Key', $idempotencyKey);
        }
        $payload = $this->encodeBody($body, $h);

        $response = $this->transport->send($method, $url, self::headerMap($h), $payload, $this->timeoutMs);

        $parsed = json_decode($response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = $response['body'] !== '' ? $response['body'] : null;
        }

        if ($response['status'] === 401) {
            $this->tokenCache = null;
            throw ApiException::fromResponse(401, $parsed, $response['headers']);
        }
        if ($response['status'] >= 400) {
            throw ApiException::fromResponse($response['status'], $parsed, $response['headers']);
        }
        if ($allowNotModified && $response['status'] === 304) {
            // A conditional GET the server answered "unchanged": success, no body.
            return NotModified::instance();
        }
        return $parsed;
    }

    // -- Call / Ephemeral ------------------------------------------------------

    /**
     * Shared data-plane sender for call()/ephemeral().
     *
     * Proxied responses are returned raw (the upstream's status belongs to
     * the caller), but transport failures are mapped to ConnectionException
     * types and retried only when safe (never replays a mutation that may
     * have reached the upstream), and a 401 triggers one token purge +
     * re-mint so a revoked token can't wedge a long-lived client.
     *
     * `$legacyKeyAsHeader` is set on call() requests: the proxy's OAuth
     * detection matches the `kc_` token prefix only, so a legacy `tk_`/`AKE`
     * credential must travel as `x-knoxcall-key` (Bearer would fall through
     * to the legacy path and 401). ephemeral() is exempt — /v1/proxy accepts
     * any credential format as Bearer.
     *
     * @param array<string, string> $callerHeaders
     * @param array<string, string> $sdkHeaders    explicit arguments — always win
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function proxySend(string $method, string $url, array $callerHeaders, array $sdkHeaders, mixed $body, int $timeoutMs, bool $legacyKeyAsHeader = false): array
    {
        $reauthDone = false;
        $attempt = 0;
        while (true) {
            $attempt++;
            $token = $this->getToken();

            $h = self::normalizeHeaders($callerHeaders);
            // The SDK credential is the sole data-plane auth authority: drop
            // any caller-supplied proxy-auth headers before we set our own, so
            // SDK auth always wins (agent identity / legacy-key path included).
            foreach (self::PROXY_AUTH_HEADERS as $name) {
                self::removeHeader($h, $name);
            }
            foreach (self::SDK_MARKER_HEADERS as $name) {
                self::removeHeader($h, $name);
            }
            self::setDefaultHeader($h, 'User-Agent', self::USER_AGENT);
            // Explicit arguments (route, environment, …) win over the
            // caller's headers array.
            foreach ($sdkHeaders as $name => $value) {
                self::setHeader($h, $name, $value);
            }
            $tokenValue = $token['access_token']->expose();
            if ($legacyKeyAsHeader && $token['token_type'] === 'Bearer' && !str_starts_with($tokenValue, 'kc_')) {
                self::setHeader($h, 'x-knoxcall-key', $tokenValue);
            } else {
                self::setHeader($h, 'Authorization', $token['token_type'] . ' ' . $tokenValue);
                if ($token['token_type'] === 'DPoP') {
                    self::setHeader($h, 'DPoP', $this->dpopProof($method, $url, $tokenValue));
                }
            }
            $payload = $this->encodeBody($body, $h);

            try {
                $response = $this->transport->send($method, $url, self::headerMap($h), $payload, $timeoutMs);
            } catch (ConnectionException $e) {
                if ($this->proxyRetryable($e, $method, $attempt)) {
                    $this->sleepMs($this->backoffDelayMs($attempt));
                    continue;
                }
                throw $e;
            }

            // A 401 the UPSTREAM answered and the data plane relayed (the response
            // block's X-Knox-Upstream-Status, or the ephemeral proxy's older
            // X-Knox-Destination-Status) says nothing about OUR token: it is the
            // caller's to handle, and spending the one re-mint on it would leave a
            // real revocation un-recoverable on this call. Only a KnoxCall-origin
            // 401 triggers the purge + re-mint. Mirrors node core.ts #proxySend.
            if ($response['status'] === 401 && !$reauthDone && !self::upstreamAnswered($response['headers'])) {
                $this->tokenCache = null;
                $reauthDone = true;
                continue;
            }
            return $response;
        }
    }

    /**
     * Whether a data-plane response came from the upstream (relayed) rather
     * than from KnoxCall itself.
     *
     * @param array<string, string> $headers
     */
    private static function upstreamAnswered(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            $n = strtolower((string) $name);
            if (($n === 'x-knox-upstream-status' || $n === 'x-knox-destination-status') && (string) $value !== '') {
                return true;
            }
        }
        return false;
    }

    private function proxyRetryable(ConnectionException $e, string $method, int $attempt): bool
    {
        if ($attempt >= $this->retryMaxAttempts) {
            return false;
        }
        // The connection was never established, so the request never left
        // the machine — always safe to retry, even for mutating methods.
        if (!$e->requestSent) {
            return true;
        }
        // Anything later (read timeout, idle-keepalive reset, …) may have
        // reached the upstream; only replay methods that are safe to repeat.
        return in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * Make a proxied request through a KnoxCall route.
     * Pass the route UUID (preferred) or name.
     *
     * Options: method, path, query, body, headers, environment,
     * timeout_ms (per-call override of the client timeout).
     *
     * Returns the raw upstream response (['status', 'headers', 'body']) —
     * upstream HTTP errors are never thrown; transport failures raise
     * ConnectionException / ConnectionTimeoutException. Legacy (non-kc_)
     * keys are sent as the x-knoxcall-key header instead of Bearer.
     *
     * '_origin' is internal: the route-aware interceptors pass
     * {@see self::SDK_INTERCEPT_ORIGIN} so the request carries
     * `x-knoxcall-origin: sdk-intercept` (PARITY §21.2). A direct call sends
     * nothing — absence IS "direct" on the server.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function call(string $route, array $opts = []): array
    {
        $method = strtoupper($opts['method'] ?? 'GET');
        // 'path' is the UPSTREAM path; the entry point is the SDK's to add (PARITY §5).
        $base = $this->ensureProxyBaseUrl();
        $url = $this->buildUrl($base . self::dataPlanePathPrefix($base), $opts['path'] ?? '/', $opts['query'] ?? null);

        $sdkHeaders = ['x-knoxcall-route' => $route];
        $environment = !empty($opts['environment']) ? (string) $opts['environment'] : $this->environment;
        if ($environment !== null && $environment !== '') {
            $sdkHeaders['x-knoxcall-environment'] = $environment;
        }
        $origin = $opts['_origin'] ?? null;
        if ($origin !== null) {
            if ($origin !== self::SDK_INTERCEPT_ORIGIN) {
                throw new \InvalidArgumentException(
                    'unknown call origin ' . json_encode($origin) . '; the only marker is "' . self::SDK_INTERCEPT_ORIGIN . '"'
                );
            }
            $sdkHeaders['x-knoxcall-origin'] = self::SDK_INTERCEPT_ORIGIN;
        }

        return $this->proxySend(
            $method,
            $url,
            $opts['headers'] ?? [],
            $sdkHeaders,
            $opts['body'] ?? null,
            (int) ($opts['timeout_ms'] ?? $this->timeoutMs),
            legacyKeyAsHeader: true,
        );
    }

    /**
     * Bind a route (and optional call defaults) once, then make plain
     * HTTP-verb calls against it:
     *
     *   $printnode = $client->route('3f1e2c9a-...', ['environment' => 'production']);
     *   $computers = $printnode->get('/computers');
     *   $printnode->post('/printjobs', ['body' => $payload]);
     *
     * Defaults: environment, headers, timeout_ms. Per-call values win over
     * bound defaults; headers merge per-key with per-call winning.
     *
     * @param array{environment?: string, headers?: array<string, string>, timeout_ms?: int} $defaults
     */
    public function route(string $route, array $defaults = []): BoundRoute
    {
        return new BoundRoute($this, $route, $defaults);
    }

    /**
     * Whether this client targets the Stripe-style isolated Test environment
     * ('sandbox' => true at construction). The wrap httpClient reads it to
     * enforce both-must-agree — a wrapped provider key's Test/Live prefix must
     * match this flag (see {@see \KnoxCall\Wrap\WrapTransport::assertKeyMatchesSandbox()}).
     */
    public function sandbox(): bool
    {
        return $this->sandbox;
    }

    /** The management base URL — read by the route-aware wrap pipeline (own-host refusal). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The data-plane base URL, or null until the tenant is discovered. */
    public function proxyBaseUrl(): ?string
    {
        return $this->proxyBaseUrl;
    }

    /** The default environment for data-plane calls (the intercept manifest's environment), or null. */
    public function environment(): ?string
    {
        return $this->environment;
    }

    /**
     * Make a one-shot proxied request via the KnoxCall Ephemeral Proxy.
     * Resolves {{ token: "..." }} expressions on the wire.
     *
     * Options: method, body, headers, encrypted,
     * timeout_ms (server-side upstream bound, sent as X-Knox-Timeout-Ms),
     * request_timeout_ms (per-call override of the local client timeout —
     * set it higher than timeout_ms or the local timeout fires first).
     *
     * Wrap-support options (PR2, both optional and purely additive):
     *   mode                   the only accepted value is 'transparent', which
     *                          sends X-Knox-Proxy-Mode: transparent — the server
     *                          forwards the request body + Content-Type
     *                          byte-verbatim and skips {{ token }} templating (the
     *                          mode a wrapped third-party SDK such as Stripe or a
     *                          form-urlencoded body needs). Omitted = default
     *                          JSON + template behaviour.
     *   upstream_authorization opaque string mapped to the UPSTREAM Authorization
     *                          header server-side (the provider's own credential),
     *                          sent as X-Knox-Upstream-Authorization. Never logged
     *                          or stored server-side; the SDK's own KnoxCall auth
     *                          is unaffected.
     *   upstream_auth_secret   the NAME of an escrowed wrap credential; the server
     *                          resolves + decrypts it and injects it as the upstream
     *                          Authorization header, host-pinned. Sent as
     *                          X-Knox-Upstream-Auth-Secret. Mutually exclusive on the
     *                          wire with upstream_authorization (the server rejects
     *                          both at once).
     *   upstream_auth_scheme   auth scheme for the resolved secret (default Bearer
     *                          server-side; 'none' = raw value). Sent as
     *                          X-Knox-Upstream-Auth-Scheme.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function ephemeral(string $upstreamUrl, array $opts = []): array
    {
        $method = strtoupper($opts['method'] ?? 'GET');

        $sdkHeaders = ['X-Knox-Proxy-URL' => $upstreamUrl];
        if (!empty($opts['encrypted'])) {
            $sdkHeaders['X-Knox-Encrypted'] = (string) $opts['encrypted'];
        }
        if (isset($opts['timeout_ms'])) {
            $sdkHeaders['X-Knox-Timeout-Ms'] = (string) $opts['timeout_ms'];
        }
        if (($opts['mode'] ?? null) === 'transparent') {
            $sdkHeaders['X-Knox-Proxy-Mode'] = 'transparent';
        }
        if (isset($opts['upstream_authorization'])) {
            $sdkHeaders['X-Knox-Upstream-Authorization'] = (string) $opts['upstream_authorization'];
        }
        if (isset($opts['upstream_auth_secret'])) {
            $sdkHeaders['X-Knox-Upstream-Auth-Secret'] = (string) $opts['upstream_auth_secret'];
        }
        if (isset($opts['upstream_auth_scheme'])) {
            $sdkHeaders['X-Knox-Upstream-Auth-Scheme'] = (string) $opts['upstream_auth_scheme'];
        }

        return $this->proxySend(
            $method,
            $this->baseUrl . '/v1/proxy',
            $opts['headers'] ?? [],
            $sdkHeaders,
            $opts['body'] ?? null,
            (int) ($opts['request_timeout_ms'] ?? $this->timeoutMs),
        );
    }

    /**
     * Verify a KnoxCall webhook HMAC-SHA256 signature.
     */
    public static function verifySignature(string $rawBody, string $signature, string $secret, int $toleranceSeconds = 300, ?int $timestamp = null): bool
    {
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $parts['t'] = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $parts['v1'] = substr($part, 3);
            }
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        if ($toleranceSeconds > 0 && $timestamp !== null) {
            if (abs($timestamp - (int) $parts['t']) > $toleranceSeconds) {
                return false;
            }
        }

        $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    // -- Webhook event construction (verify + parse) -----------------------------

    /**
     * Verify an incoming webhook delivery AND parse it in one step.
     *
     * Pass the RAW request body (never re-serialized JSON), the request
     * headers (case-insensitive lookup), and the endpoint secret. On success
     * returns the delivery envelope as an associative array:
     *
     *   [
     *     'event'        => string,          // e.g. 'request.success', 'audit.event' —
     *                                        // open list, unknown types parse fine
     *     'timestamp'    => string,          // ISO-8601
     *     'webhook_id'   => string,          // present on request.* events
     *     'webhook_name' => string,          // present on request.* events
     *     'data'         => array,           // request.*: {route_id, route_name,
     *                                        //   environment, request: {method, path, ip},
     *                                        //   response: {status, latency_ms}}
     *                                        // audit.event: {id, action, resource_type,
     *                                        //   resource_id, details, ip_address}
     *   ]
     *
     * Options:
     *   'format'            one of legacy|stripe|github|slack|aws-sns|custom
     *                       (default 'legacy') — must match the webhook's
     *                       configured hmac_format;
     *   'tolerance_seconds' replay window, default 300. For stripe/slack the
     *                       check runs against the signed header timestamp;
     *                       for the other formats against the envelope's
     *                       'timestamp' field. Pass null (or 0) to disable
     *                       all timestamp checks;
     *   'header_name'       required when format is 'custom', ignored otherwise.
     *
     * Throws WebhookSignatureVerificationException on ANY verification
     * failure (missing header, signature mismatch, stale timestamp, body not
     * a JSON object) — never returns a partial event, and the message never
     * echoes the signature or secret. Option misuse (unknown format, missing
     * header_name for 'custom') throws InvalidArgumentException.
     *
     * Also available as $client->webhooks->constructEvent().
     *
     * @param array<string, string|list<string>> $headers request headers, any casing
     * @param array{format?: string, tolerance_seconds?: int|null, header_name?: string} $opts
     * @return array<string, mixed>
     */
    public static function constructEvent(string $rawBody, array $headers, #[\SensitiveParameter] string $secret, array $opts = []): array
    {
        $format = $opts['format'] ?? 'legacy';
        if (!in_array($format, ['legacy', 'stripe', 'github', 'slack', 'aws-sns', 'custom'], true)) {
            throw new \InvalidArgumentException(
                'format must be one of legacy, stripe, github, slack, aws-sns, custom'
            );
        }
        // Explicit null/0 disables replay protection entirely (documented).
        $tolerance = array_key_exists('tolerance_seconds', $opts) ? $opts['tolerance_seconds'] : 300;
        $tolerance = $tolerance !== null && (int) $tolerance > 0 ? (int) $tolerance : null;
        $now = time();

        // Case-insensitive header lookup; multi-value headers use the first value.
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        $header = static function (string $name) use ($lower): string {
            $value = $lower[strtolower($name)] ?? null;
            if ($value === null || trim($value) === '') {
                throw new WebhookSignatureVerificationException("missing signature header {$name}");
            }
            return trim($value);
        };
        // hex signatures arrive as `<prefix><hex>` (e.g. sha256=…, v0=…);
        // the prefix is shape, not signature — strip it when present.
        $stripPrefix = static fn (string $value, string $prefix): string =>
            str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;

        switch ($format) {
            case 'legacy':
            case 'github':
            case 'custom':
                $headerName = match ($format) {
                    'legacy' => 'X-Webhook-Signature',
                    'github' => 'X-Hub-Signature-256',
                    'custom' => $opts['header_name'] ?? throw new \InvalidArgumentException(
                        "header_name is required when format is 'custom'"
                    ),
                };
                $signature = $stripPrefix($header($headerName), 'sha256=');
                if (!hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
                    throw new WebhookSignatureVerificationException("signature mismatch ({$format} format)");
                }
                break;

            case 'aws-sns':
                $signature = $header('x-amz-sns-signature');
                if (!hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature)) {
                    throw new WebhookSignatureVerificationException('signature mismatch (aws-sns format)');
                }
                break;

            case 'stripe':
                // `t=<ts>,v1=<hex>` — multiple comma-separated pairs allowed;
                // any matching v1 passes (mirrors Stripe's secret rotation).
                $ts = null;
                $candidates = [];
                foreach (explode(',', $header('Stripe-Signature')) as $part) {
                    $part = trim($part);
                    if (str_starts_with($part, 't=')) {
                        $ts = substr($part, 2);
                    } elseif (str_starts_with($part, 'v1=')) {
                        $candidates[] = substr($part, 3);
                    }
                }
                if ($ts === null || $ts === '' || !ctype_digit($ts) || $candidates === []) {
                    throw new WebhookSignatureVerificationException('malformed Stripe-Signature header');
                }
                if ($tolerance !== null && abs($now - (int) $ts) > $tolerance) {
                    throw new WebhookSignatureVerificationException('timestamp outside tolerance (stripe format)');
                }
                $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
                $matched = false;
                foreach ($candidates as $candidate) {
                    // no early break — check every candidate, constant-time each
                    if (hash_equals($expected, $candidate)) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    throw new WebhookSignatureVerificationException('signature mismatch (stripe format)');
                }
                break;

            case 'slack':
                $ts = $header('X-Slack-Request-Timestamp');
                if (!ctype_digit($ts)) {
                    throw new WebhookSignatureVerificationException('malformed X-Slack-Request-Timestamp header');
                }
                if ($tolerance !== null && abs($now - (int) $ts) > $tolerance) {
                    throw new WebhookSignatureVerificationException('timestamp outside tolerance (slack format)');
                }
                $signature = $stripPrefix($header('X-Slack-Signature'), 'v0=');
                if (!hash_equals(hash_hmac('sha256', "v0:{$ts}:{$rawBody}", $secret), $signature)) {
                    throw new WebhookSignatureVerificationException('signature mismatch (slack format)');
                }
                break;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new WebhookSignatureVerificationException('delivery body is not a JSON object');
        }

        // Formats without a signed timestamp: enforce the replay window
        // against the envelope's own ISO-8601 timestamp field.
        if ($tolerance !== null && in_array($format, ['legacy', 'github', 'aws-sns', 'custom'], true)) {
            $ts = isset($event['timestamp']) && is_string($event['timestamp'])
                ? strtotime($event['timestamp'])
                : false;
            if ($ts === false) {
                throw new WebhookSignatureVerificationException(
                    'delivery timestamp missing or invalid (pass tolerance_seconds => null to skip replay checks)'
                );
            }
            if (abs($now - $ts) > $tolerance) {
                throw new WebhookSignatureVerificationException("timestamp outside tolerance ({$format} format)");
            }
        }

        return $event;
    }

    // -- Signup ------------------------------------------------------------------

    /**
     * Start creating a KnoxCall account — the one /v1 surface that needs no
     * credentials, so these are static methods rather than resources on the
     * authenticated client.
     *
     * TWO steps since 2026-08-28 (founder decision F-25). signup() never
     * returns a credential: it returns a claim handle and emails a sign-in
     * link, and the starter key is minted when that link has been clicked and
     * the claim is collected:
     *
     *   $accepted = KnoxCall::signup(['email' => 'dev@example.com', 'tenant_name' => 'Acme Inc']);
     *   $handle   = $accepted['claim_handle'];
     *
     *   // …the account owner clicks the emailed sign-in link…
     *   $claim = KnoxCall::claimSignup($handle);
     *   while ($claim['status'] === 'pending') {
     *       sleep($accepted['poll_after_seconds']);
     *       $claim = KnoxCall::claimSignup($handle);
     *   }
     *
     *   // $claim['starter']['api_key']['api_key'] is shown exactly once — store it now.
     *   $client = new KnoxCall(['api_key' => $claim['starter']['api_key']['api_key'], 'sandbox' => true]);
     *
     * $input: email (required), tenant_name (required), full_name?,
     * tenant_slug? (omit to have one derived — recommended), country?,
     * region? ('us'|'eu'|'au').
     *
     * Always answers 202, carrying status, claim_handle, claim_path,
     * poll_after_seconds, expires_at, message and documentation — and no
     * credential. Enumeration-safe: the reply is identical for an address that
     * already has an account (it receives a sign-in link and a handle that
     * stays pending). Rate limited to 3 signups/hour/IP.
     *
     * Options: base_url (default https://api.knoxcall.com), transport
     * (TransportInterface — tests inject a mock), timeout_ms (default 30000).
     *
     * @param array{email: string, tenant_name: string, full_name?: string, tenant_slug?: string, country?: string, region?: string} $input
     * @param array{base_url?: string, transport?: TransportInterface, timeout_ms?: int} $opts
     * @return array<string, mixed>
     *
     * @throws SignupException on any failure (validation, slug conflict,
     *         rate limit, unexpected body) — carries ->statusCode,
     *         ->errorCode (e.g. "slug_taken"), ->requestId
     * @throws ConnectionException on transport failure
     */
    public static function signup(array $input, array $opts = []): array
    {
        return self::signupPost('/v1/signup', $input, 'Signup', $opts);
    }

    /**
     * Poll a claim handle returned by {@see signup}.
     *
     * Returns ['status' => 'pending', ...] — a 202, and a normal SUCCESS —
     * until the emailed sign-in link has been clicked, then once returns
     * ['status' => 'ready', ...] with the tenant, the seeded demo route and a
     * one-time Test-mode API key. Polling again after that throws
     * SignupException (409); an unknown or expired handle throws it with 404.
     *
     * Do not poll faster than the poll_after_seconds signup() returned.
     *
     * @param array{base_url?: string, transport?: TransportInterface, timeout_ms?: int} $opts
     * @return array<string, mixed>
     *
     * @throws SignupException
     * @throws ConnectionException on transport failure
     */
    public static function claimSignup(string $claimHandle, array $opts = []): array
    {
        return self::signupPost('/v1/signup/claim', ['claim_handle' => $claimHandle], 'Signup claim', $opts);
    }

    /**
     * The shared credential-less POST. Note what it does NOT treat as an
     * error: a 202. Both endpoints use it for a normal, credential-less
     * success, so any sub-400 response carrying a data object is returned.
     *
     * @param array<string, mixed> $input
     * @param array{base_url?: string, transport?: TransportInterface, timeout_ms?: int} $opts
     * @return array<string, mixed>
     */
    private static function signupPost(string $path, array $input, string $what, array $opts): array
    {
        $baseUrl = rtrim($opts['base_url'] ?? 'https://api.knoxcall.com', '/');
        $transport = $opts['transport'] ?? new CurlTransport();
        $timeoutMs = (int) ($opts['timeout_ms'] ?? 30000);

        $body = json_encode($input, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new KnoxCallException('Could not JSON-encode signup input: ' . json_last_error_msg());
        }

        $response = $transport->send('POST', $baseUrl . $path, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ], $body, $timeoutMs);

        $parsed = json_decode($response['body'], true);
        $data = is_array($parsed) && is_array($parsed['data'] ?? null) ? $parsed['data'] : null;
        if ($response['status'] >= 400 || $data === null) {
            $err = is_array($parsed) && is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
            $message = isset($err['message']) && is_string($err['message'])
                ? $err['message']
                : "{$what} failed with status {$response['status']}";
            $requestId = $err['request_id'] ?? $response['headers']['x-request-id'] ?? null;
            throw new SignupException(
                $message,
                $response['status'],
                isset($err['type']) && is_string($err['type']) ? $err['type'] : null,
                is_string($requestId) ? $requestId : null,
                $response['headers'],
                $parsed,
            );
        }
        return $data;
    }
    // -- OIDC workload federation (RFC 8693 token exchange) ---------------------

    /** The only grant_type POST /v1/oauth/token accepts. */
    public const TOKEN_EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';

    /** The only subject_token_type it accepts. */
    public const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';

    /** The default (and only supported) audience. */
    public const KNOXCALL_AUDIENCE = 'knoxcall:gateway';

    /**
     * Exchange a CI OIDC token for a short-lived AI-gateway capability token
     * (AIGW-26).
     *
     *   $res = KnoxCall::exchangeToken(
     *       ['subject_token' => $ciIdToken],
     *       ['tenant' => 'acme'],
     *   );
     *   // $res['access_token'] is an agent-kind token for POST /v1/ai/...
     *
     * Like {@see signup} this is static and takes no client, and for a stronger
     * reason: the whole point is that CI holds no KnoxCall credential.
     * Constructing a client to reach this endpoint would require the very
     * secret the flow exists to remove — so NO Authorization header is sent.
     * The subject_token IS the credential, verified against the issuer's
     * published JWKS.
     *
     * Pass 'resource' — the `resource` field of an MCP server's create/get
     * response — to narrow the minted token to `tool` kind, confined to exactly
     * that one /v1/mcp/<slug> and refused on /v1/ai. Omit the KEY entirely for
     * an `agent`-kind token: an empty string is sent through and refused
     * `invalid_target`, because dropping it silently would mint an UNCONFINED
     * token while the caller believes it is audience-restricted.
     *
     * THE HOST MATTERS, and getting it wrong looks like a credential failure.
     * /v1/oauth/token is part of the DATA plane: src/server.ts hands /v1/ai/,
     * /v1/mcp/ and /v1/oauth/ to the proxy router only when the request lands on
     * a tenant data-plane host ({slug}.knoxcall.com, sandbox-{slug}...). Verified
     * against a running server on 2026-08-25: the same request answers 400
     * invalid_grant on acme.knoxcall.com and 401 on api.knoxcall.com — a caller
     * who points this at the management host reads that 401 as "my CI token was
     * rejected" when the endpoint is simply not served there. So 'tenant' (or an
     * explicit 'base_url') is REQUIRED: there is no safe default to guess.
     *
     * NOT the tenant OAuth 2.1 token endpoint at
     * https://api.knoxcall.com/oauth/token (root host, no /v1), which mints
     * kc_ MANAGEMENT tokens from client_credentials and friends.
     *
     * Returns the RFC 8693 §2.2.1 body — {access_token, issued_token_type,
     * token_type, expires_in, scope}. A BARE OAuth body, not the {data, meta}
     * envelope the rest of /v1 returns.
     *
     * Options: tenant (slug -> https://{tenant}.knoxcall.com), sandbox (bool,
     * -> https://sandbox-{tenant}.knoxcall.com), base_url (wins over tenant;
     * required for self-hosted), transport (TransportInterface — tests inject a
     * mock), timeout_ms (default 30000).
     *
     * @param array{subject_token: string, resource?: string, audience?: string} $input
     * @param array{tenant?: string, sandbox?: bool, base_url?: string, transport?: TransportInterface, timeout_ms?: int} $opts
     * @return array<string, mixed>
     *
     * @throws KnoxCallException when neither tenant nor base_url is given, or the
     *         tenant slug is not a DNS label
     * @throws TokenExchangeException on any refusal — carries ->statusCode and
     *         ->errorCode (the RFC 6749 code)
     * @throws ConnectionException on transport failure
     */
    public static function exchangeToken(array $input, array $opts = []): array
    {
        $baseUrl = self::exchangeBaseUrl($opts);
        // the request carries the workload OIDC id_token, which IS a credential -- the
        // whole point of the flow. PARITY 15 already warns when a CLIENT is constructed
        // against plaintext http to a non-loopback host, and this function deliberately
        // constructs no client, so without this the control exists on one path and is
        // simply absent on the parallel one. A warning rather than a refusal because the
        // acceptance harness and local dev legitimately use http://127.0.0.1.
        if (Warn::isInsecureRemoteUrl($baseUrl)) {
            Warn::warnOnce(
                'KNOXCALL_INSECURE_TRANSPORT',
                "KnoxCall: exchanging a workload OIDC token over plaintext HTTP to {$baseUrl} — the "
                . 'subject token is a credential and is readable on the wire. Use https://.'
            );
        }
        $transport = $opts['transport'] ?? new CurlTransport();
        $timeoutMs = (int) ($opts['timeout_ms'] ?? 30000);

        $payload = [
            'grant_type' => self::TOKEN_EXCHANGE_GRANT,
            'subject_token' => $input['subject_token'] ?? '',
            'subject_token_type' => self::ID_TOKEN_TYPE,
            'audience' => $input['audience'] ?? self::KNOXCALL_AUDIENCE,
        ];
        // array_key_exists, NOT isset: a caller who passed 'resource' => '' asked
        // for a confined token and must get the server's invalid_target, never
        // an unconfined agent token.
        if (array_key_exists('resource', $input)) {
            $payload['resource'] = $input['resource'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new KnoxCallException('Could not JSON-encode token-exchange input: ' . json_last_error_msg());
        }

        $response = $transport->send('POST', $baseUrl . '/v1/oauth/token', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ], $body, $timeoutMs);

        // A non-JSON error page (a proxy 502) decodes to null and falls through
        // to the status check rather than masking the status.
        $parsed = json_decode($response['body'], true);
        $token = is_array($parsed) && isset($parsed['access_token']) && is_string($parsed['access_token'])
            ? $parsed['access_token']
            : null;

        if ($response['status'] >= 400 || $token === null || $token === '') {
            // AIGW-163: this endpoint is on the TENANT DATA PLANE, so when the
            // AI gateway has failed to boot it is answered by the plane's 503
            // sentinel — the data-plane envelope with code
            // "ai_gateway_unavailable" and a Retry-After — not by an RFC 6749
            // error. Typing it means a CI job is told to wait rather than handed
            // a generic exchange failure. The discriminator is exact: an
            // RFC 6749 body carries no `code` at all.
            $aiErr = AIGatewayException::fromDataPlaneResponse(
                $response['status'],
                $parsed,
                $response['headers'],
            );
            if ($aiErr !== null) {
                throw $aiErr;
            }
            $code = is_array($parsed) && isset($parsed['error']) && is_string($parsed['error'])
                ? $parsed['error']
                : 'token_exchange_failed';
            $message = is_array($parsed) && isset($parsed['error_description']) && is_string($parsed['error_description'])
                ? $parsed['error_description']
                : "Token exchange failed with status {$response['status']}";
            throw new TokenExchangeException(
                $message,
                $response['status'],
                $code,
                null,
                $response['headers'],
                $parsed,
            );
        }
        return $parsed;
    }

    /**
     * The data-plane origin for {@see exchangeToken}. There is no default — see
     * that method's "THE HOST MATTERS" note.
     *
     * A tenant slug becomes a hostname, so it must be a bare DNS label: a slug
     * adopted from config or an environment variable that is not one
     * ('evil.com#') would send the workload's OIDC token to an attacker host.
     *
     * @param array{tenant?: string, sandbox?: bool, base_url?: string} $opts
     */
    private static function exchangeBaseUrl(array $opts): string
    {
        if (isset($opts['base_url']) && $opts['base_url'] !== '') {
            return rtrim((string) $opts['base_url'], '/');
        }
        $tenant = (string) ($opts['tenant'] ?? '');
        if ($tenant === '') {
            throw new KnoxCallException(
                'exchangeToken needs a tenant slug or a base_url: POST /v1/oauth/token is served '
                . 'only on the tenant data-plane host (https://{tenant}.knoxcall.com). Pointing it '
                . 'at api.knoxcall.com answers 401, which reads like a rejected subject_token but '
                . 'means the endpoint is not there.'
            );
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $tenant) !== 1) {
            throw new KnoxCallException(
                'invalid tenant slug ' . json_encode($tenant) . ' — expected a DNS label; refusing '
                . 'to send a subject token to a host derived from it'
            );
        }
        $host = ($opts['sandbox'] ?? false) === true ? 'sandbox-' . $tenant : $tenant;
        return 'https://' . $host . '.knoxcall.com';
    }

    // -- Interactive login helpers (login / ensureLogin) -------------------------

    /**
     * Run interactive first-run auth (OPT-IN, PARITY §14), persist the
     * credential to ~/.knoxcall/credentials.json, and return a ready client
     * bound to the written profile:
     *
     *   $client = KnoxCall::login(['tenant' => 'acme']);
     *
     * Reuses the SAME (already-tested) `knoxcall login` flow — auth-code+PKCE
     * loopback or the RFC 8628 device code — and the persist-under-lock helper.
     *
     * This is NEVER on the request path: constructing a client and calling
     * ->call() never pop a browser or block on a device code — they raise
     * {@see NotAuthenticatedException}. The browser/device flow is reachable
     * ONLY through login()/ensureLogin().
     *
     * Interactive guard (mandatory): refuses to prompt — throwing
     * NotAuthenticatedException with a provision-a-credential hint — when there
     * is no TTY, or KNOXCALL_NO_INTERACTIVE / CI is set. Pass
     * 'allow_non_interactive' => true to override.
     *
     * Options: tenant, sandbox (bool), base_url, profile, mode
     * ('auto'|'browser'|'device', default 'auto'), timeout (loopback wait,
     * seconds), allow_non_interactive (bool), client_options (array forwarded
     * to the returned client). Test seams: transport (TransportInterface),
     * sleep (callable(float): void), open_browser (callable(string): void),
     * out (stream resource).
     *
     * @param array<string, mixed> $opts
     */
    public static function login(array $opts = []): self
    {
        self::loginInteractiveGuard($opts);

        $sandbox = ($opts['sandbox'] ?? false) === true;
        $baseUrl = rtrim((string) ($opts['base_url'] ?? CliCommon::defaultBaseUrl($sandbox)), '/');
        $profile = CredentialsFile::resolveProfile(
            isset($opts['profile']) ? (string) $opts['profile'] : null,
        );
        $tenant = isset($opts['tenant']) ? (string) $opts['tenant'] : null;

        $mode = $opts['mode'] ?? 'auto';
        if (!in_array($mode, ['auto', 'browser', 'device'], true)) {
            throw new \InvalidArgumentException(
                'mode must be "auto", "browser", or "device"'
            );
        }
        $useDevice = $mode === 'device' || ($mode === 'auto' && !self::hasDesktopBrowser());

        $transport = $opts['transport'] ?? new CurlTransport();
        $sleep = isset($opts['sleep'])
            ? \Closure::fromCallable($opts['sleep'])
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            };
        $openBrowser = isset($opts['open_browser'])
            ? \Closure::fromCallable($opts['open_browser'])
            : static function (string $url): void {
                CliLogin::openSystemBrowser($url);
            };
        $out = $opts['out'] ?? (\defined('STDOUT') ? \STDOUT : fopen('php://output', 'w'));

        $flow = new CliLogin($transport, $sleep, $openBrowser, $out);
        $tokenBody = $useDevice
            ? $flow->deviceFlow($baseUrl)
            : $flow->authCodeFlow($baseUrl, $tenant, (float) ($opts['timeout'] ?? 300.0));

        CliCommon::persistLogin(CliCommon::requireCredentialsPath(), $profile, $baseUrl, $tokenBody, $tenant);

        return self::clientFromProfile($profile, $opts);
    }

    /**
     * Return a client from an already-stored credential for the profile when
     * one is present (NO prompt, NO network), otherwise run {@see self::login()}
     * once. The ergonomic "make sure I'm authenticated, then give me a client"
     * entry point (PARITY §14). Same options as login().
     *
     * @param array<string, mixed> $opts
     */
    public static function ensureLogin(array $opts = []): self
    {
        $profile = CredentialsFile::resolveProfile(
            isset($opts['profile']) ? (string) $opts['profile'] : null,
        );
        $path = CredentialsFile::resolvePath();
        if ($path !== null && CredentialsFile::profileAvailable($path, $profile)) {
            return self::clientFromProfile($profile, $opts);
        }
        return self::login($opts);
    }

    /**
     * Refuse to pop a browser / block on a device code where it is unsafe: a
     * non-interactive process (no TTY), CI, or an explicit opt-out. The caller
     * overrides with 'allow_non_interactive' => true when they know it is safe.
     *
     * @param array<string, mixed> $opts
     */
    private static function loginInteractiveGuard(array $opts): void
    {
        if (($opts['allow_non_interactive'] ?? false) === true) {
            return;
        }
        if (self::envIsSet('KNOXCALL_NO_INTERACTIVE') || self::envIsSet('CI')) {
            throw new NotAuthenticatedException(
                'interactive login is disabled here (KNOXCALL_NO_INTERACTIVE or CI is set). '
                . 'Provision a non-interactive credential (client_id/secret or workload OIDC) instead.'
            );
        }
        if (!self::streamIsTty('STDIN') || !self::streamIsTty('STDOUT')) {
            throw new NotAuthenticatedException(
                'no interactive terminal detected — run `knoxcall login` in a terminal, '
                . 'or provision a non-interactive credential (client_id/secret or workload OIDC).'
            );
        }
    }

    /** A non-empty environment variable counts as "set" (empty string does not). */
    private static function envIsSet(string $name): bool
    {
        $value = getenv($name);
        return is_string($value) && $value !== '';
    }

    /**
     * Is the given standard stream ('STDIN'|'STDOUT') an interactive terminal?
     * Uses stream_isatty() (core, PHP 7.2+), falling back to posix_isatty()
     * when only ext-posix offers it; a non-CLI SAPI (no STD* constants) is
     * never a terminal.
     */
    private static function streamIsTty(string $constant): bool
    {
        if (!\defined($constant)) {
            return false;
        }
        $stream = \constant($constant);
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream);
        }
        return false;
    }

    /**
     * Choose browser vs device in 'auto' mode. Headless CI is already blocked
     * by the interactive guard, so on Linux only require a display server;
     * every other desktop OS is assumed to have a browser. Mirrors node
     * login.ts hasDesktopBrowser().
     */
    private static function hasDesktopBrowser(): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $display = getenv('DISPLAY') ?: getenv('WAYLAND_DISPLAY') ?: '';
            return $display !== '';
        }
        return true;
    }

    /**
     * Build a client bound to a credentials-file profile. Holds no secret — the
     * StoredCredentials bootstrap reads (and refreshes) the file lazily. The
     * file's tenant/base_url seed the client (constructor seeding); 'sandbox'
     * and any 'client_options' the caller passed are forwarded.
     *
     * @param array<string, mixed> $opts
     */
    private static function clientFromProfile(string $profile, array $opts): self
    {
        $clientOpts = (is_array($opts['client_options'] ?? null) ? $opts['client_options'] : []) + [
            'credentials' => new StoredCredentials(null, $profile),
            'sandbox' => ($opts['sandbox'] ?? false) === true,
        ];
        if (isset($opts['transport'])) {
            $clientOpts['transport'] = $opts['transport'];
        }
        return new self($clientOpts);
    }
}
