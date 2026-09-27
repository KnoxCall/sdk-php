# knoxcall/sdk

Official KnoxCall API client for PHP. Standards-based OAuth 2.1 under the hood — your code just calls methods. PHP >= 8.1, `ext-curl` + `ext-json` only, no other runtime dependencies.

## Install

Not yet published to Packagist — install from the monorepo via a [path repository](https://getcomposer.org/doc/05-repositories.md#path) (`"repositories": [{"type": "path", "url": "../knoxcall/sdk/knoxcall-php"}]` + `composer require knoxcall/sdk:@dev`) or a git checkout.

## Quickstart

```php
use KnoxCall\KnoxCall;

// Credentials inline — no extra imports
$client = new KnoxCall(['client_id' => 'tk_xxxxxxxx', 'client_secret' => '...']); // tenant auto-discovered

// Or zero-arg with the environment configured
// (KNOXCALL_TENANT, KNOXCALL_CLIENT_ID, KNOXCALL_CLIENT_SECRET)
$client = new KnoxCall();

// Management API
$page = $client->routes->list();          // ['data' => [...], 'meta' => ['total' => ..., ...]]
foreach ($page['data'] as $route) {
    echo $route['name'], "\n";
}

// Data plane: proxy a request through a route to your upstream
$resp = $client->call('billing-stripe', ['path' => '/v1/customers']); // slug preferred
$customers = json_decode($resp['body'], true);
```

For the Stripe-style isolated Test environment, pass `'sandbox' => true` (uses `https://sandbox.knoxcall.com` + `https://sandbox-{tenant}.knoxcall.com`; requires a `tk_test_…` key).

## Authentication

Pass credentials as plain constructor options:

| Option | Use |
|---|---|
| `client_id` + `client_secret` | OAuth client-credentials grant (recommended for servers) |
| `access_token` / `api_key` | a pre-acquired token or key — two spellings, one behavior; works with `kc_…` tokens and legacy `tk_…`/`AKE…` keys |
| `credentials` | advanced: a `ClientCredentials`, `AccessToken`, `OIDCTokenExchange` (workload identity via RFC 8693 token exchange), or `StoredCredentials` (`knoxcall login` file, explicit path/profile) instance |

With no explicit credentials, the SDK resolves them in this order (PHP has no cloud auto-detect, so it is strictly explicit > environment):

1. explicit constructor credentials (flat options or a `credentials` object)
2. `KNOXCALL_ACCESS_TOKEN` / `KNOXCALL_API_KEY`
3. the `knoxcall login` credentials file (`~/.knoxcall/credentials.json`)
4. `KNOXCALL_CLIENT_ID` + `KNOXCALL_CLIENT_SECRET`

Conflicting explicit options (e.g. `api_key` + `client_id`) throw at construction, before any environment fill.

A pre-acquired `access_token` / `api_key` is **not auto-renewed** — it has no refresh token, so the SDK sends it as-is until the server rejects it with a 401 (surfaced as an `AuthenticationException`). For durable, self-renewing auth use `client_id` + `client_secret` (client-credentials) or `knoxcall login` (the credentials file refreshes and rotates for you).

### Log in once with the CLI

This package ships the `knoxcall` executable (Composer `bin`):

```bash
composer global require knoxcall/sdk   # puts `knoxcall` on your PATH (Composer's global vendor/bin)
knoxcall login
```

or, inside a project that already requires the SDK:

```bash
vendor/bin/knoxcall login
```

`knoxcall login` opens your browser (authorization-code + PKCE; use `--device` or `--no-browser` on headless/SSH machines, `--sandbox` for the sandbox environment, `--profile NAME` for multiple accounts). `knoxcall whoami` shows the signed-in tenant; `knoxcall logout` revokes and removes the stored profile.

`knoxcall init` gets you started wrapping a provider SDK — it works against the tenant you are already signed in to and never provisions one. With no `--provider` it prints a two-step wrap quickstart. With `--provider stripe --secret-name wrap-stripe --host api.stripe.com` it moves a provider key into KnoxCall custody and prints the gateway `base_url` to point your SDK at; the key is read from the `KNOXCALL_WRAP_SECRET` environment variable (never a flag, so it stays out of your shell history) and is never echoed back.

Every KnoxCall SDK ships the same CLI, and every implementation writes the same file — `~/.knoxcall/credentials.json` — so it doesn't matter which one you run `login` from: one sign-in covers PHP, Python, Node, Go, and Ruby on that machine. With no explicit credentials and no credential env vars, `new KnoxCall()` picks it up automatically — including the file's `tenant` and `base_url`, unless you set those yourself (constructor options, env vars, and `'sandbox' => true` always win). A missing or malformed file/profile is skipped silently and resolution continues down the list above.

The stored access token is used as-is while it has more than 60s of validity. Past that, the SDK refreshes it under a cross-process file lock (`credentials.json.lock`) and atomically writes the rotated refresh token back — the server's refresh tokens are single-use, so never copy them out of the file. If the stored credentials were revoked or expired (`invalid_grant`), you get an `AuthenticationException` telling you to run `knoxcall login` again.

PHP-FPM note: each request is its own process, so the in-memory token cache lives for at most one request — with stored credentials the file's fresh-token fast path is the effective cross-request cache. A request re-reads the file (a local read, no HTTP) while the stored token is fresh and only performs a real refresh-token round trip when it is not, so no APCu/Redis store is needed for this credential type.

### Environment variables

| Variable | Meaning |
|---|---|
| `KNOXCALL_TENANT` | tenant slug (optional — auto-discovered from the credential when unset) |
| `KNOXCALL_ENVIRONMENT` | default environment for data-plane calls |
| `KNOXCALL_CLIENT_ID` / `KNOXCALL_CLIENT_SECRET` | client-credentials grant |
| `KNOXCALL_ACCESS_TOKEN` / `KNOXCALL_API_KEY` | pre-acquired token (ACCESS_TOKEN wins) |
| `KNOXCALL_CREDENTIALS_FILE` | `knoxcall login` credentials-file path override (default `~/.knoxcall/credentials.json`) |
| `KNOXCALL_PROFILE` | credentials-file profile (default `default`) |
| `KNOXCALL_BASE_URL` | management API base override (legacy `KNOXCALL_API_BASE_URL` still accepted) |
| `KNOXCALL_PROXY_BASE_URL` | data-plane base override |

Token caching: typical PHP-FPM deployments are per-request processes, so under `client_credentials` each request mints one token. If that matters, mint out of band, cache it yourself (APCu/Redis), and construct with `['access_token' => $cached]`. Long-running workers (CLI, queues, Octane) get in-process caching with refresh-ahead and stale-but-valid fallback automatically.

### Security warnings

The SDK emits one-time, non-blocking `E_USER_WARNING`s (they never raise and never change behavior) for two misconfigurations:

- **Plaintext transport** — at construction, if the resolved management base URL or the data-plane proxy URL is `http://` to a non-loopback host, the SDK warns that credentials and tokens will be sent unencrypted. Use `https://`. Plain `http://` to `localhost` (or `127.0.0.0/8`, `::1`, `0.0.0.0`, `*.localhost`) is the normal dev case and does **not** warn.
- **World-readable credentials file** — on POSIX, when reading `~/.knoxcall/credentials.json`, if the file is group/other-accessible the SDK warns you to `chmod 600` it (it holds a refresh token). The check is skipped on Windows, where the mode bits are advisory and confidentiality rests on the `%USERPROFILE%` ACL.

## Data plane

### `call()` — proxy through a route

Returns the raw upstream response (`['status', 'headers', 'body']`) — upstream HTTP errors are never thrown; they belong to you.

```php
$resp = $client->call('billing-stripe', [
    'method' => 'POST',
    'path' => '/v1/charges',
    'body' => ['amount' => 2000, 'currency' => 'usd'],
    'environment' => 'staging',
]);
```

`path` is the **upstream** path. On a KnoxCall cloud tenant host the data plane is served under `/api` (`https://{tenant}.knoxcall.com/api/<path>`); the SDK adds that prefix itself whenever the proxy base is a cloud tenant host with no path of its own, and uses any other base verbatim (self-hosted, or an override that already carries a path). So `/api/v2/tickets` reaches an upstream path that itself begins with `/api`.

Legacy (non-`kc_`) keys automatically travel as the `x-knoxcall-key` header instead of `Authorization: Bearer`.

### Bound routes

State the route (and optional defaults) once, then use plain HTTP verbs:

```php
$printnode = $client->route('printnode-api', ['environment' => 'production']);

$computers = json_decode($printnode->get('/computers')['body'], true);
$printnode->post('/printjobs', ['body' => ['printerId' => 1, 'title' => 'Invoice']]);
$printnode->request('DELETE', '/printjobs/42');
// per-call options still override the bound defaults:
$printnode->get('/computers', ['environment' => 'staging']);
```

### `ephemeral()` — one-shot proxy

Proxies a single request via the KnoxCall Ephemeral Proxy, resolving `{{ token: "..." }}` expressions on the wire:

```php
$resp = $client->ephemeral('https://api.stripe.com/v1/charges', [
    'method' => 'POST',
    'body' => ['amount' => 2000, 'card' => '{{ token: "tok_vault_abc" }}'],
]);
```

### Route-aware interception (preview)

Send an untouched third-party SDK's traffic through the Route that covers it —
and through the ephemeral proxy where no Route does — with no per-SDK wiring:

```php
$knox = new KnoxCall\KnoxCall(['api_key' => $apiKey]);
$http = $knox->wrap->httpClient(['routes' => 'auto']);      // a PSR-18 client, route-aware
$http->ready();                                              // first manifest loaded

$hubspot = HubSpot\Factory::createWithAccessToken('placeholder', new GuzzleHttp\Client(['handler' => $stack]));
// …or any SDK that takes a PSR-18 client: every call to api.hubapi.com goes via the Route that covers it
```

Per request: the kill switch (`KNOXCALL_INTERCEPT=off`), KnoxCall's own hosts
and route-around rules go direct; a Route in the manifest covering host + path
goes through that Route (the Route injects the stored secret — no provider
credential travels); everything else goes through the ephemeral proxy — an
explicit transport treats every host as listed. Turn a Route's **Intercept**
toggle on and it takes effect on the next request after the 60 s TTL, or on
the next refusal, with no code change. Per-host options:
`'hosts' => ['api.resend.com' => ['credential' => ['secret' => 'resend-key']]]`
(escrow) or `['unavailable' => 'direct']` (transit only: send direct when
KnoxCall is unreachable; the default is fail closed). PHP processes are usually
per-request, so pass a PSR-16 cache (`'cache' => $apcu`) to share the manifest
across them; without one each process fetches it once per TTL.

For an SDK that builds its own Guzzle stack and lets you push middleware:

```php
$stack = GuzzleHttp\HandlerStack::create();
$stack->push($knox->wrap->guzzleMiddleware(['hosts' => ['api.resend.com']]), 'knoxcall');
$guzzle = new GuzzleHttp\Client(['handler' => $stack]);
```

Only route-covered or listed hosts are touched there; everything else continues
down the stack. **PHP has no process-wide HTTP seam** (curl has no hook;
`stream_wrapper` misses curl), so these two seams are the coverage: any SDK
accepting a PSR-18 client, a Guzzle client or a handler stack. An SDK that
builds its own curl handle uses `gatewayUrl()` (base-URL mode).

Route mode is the custody path — the key never enters your process.

#### What the SDK reports about uncovered calls, and how to turn it off

**Reporting is on by default.** When the interceptor sends a call direct
because no Route covers its host and you did not list the host, and that call
carries a credential header (`Authorization`, `X-Api-Key`, or any name ending
in `-api-key`, `-token`, `-secret` or `-auth`), the SDK counts it. About once a
minute, the SDK reports the counts to KnoxCall
(`POST /v1/wrap/egress-observations`) with its own credential. The dashboard
uses the report to show which credentials still leave your process outside
KnoxCall custody.

**What is sent.** Each report carries the host, the first path segment, the
method, the credential header's **name**, a count, and first/last-seen times.
The header's **value** is never sent, and neither are the query string, the
body, or any deeper path.

**How to turn it off.** Pass `'observe_uncovered' => false` when you install, or set
`KNOXCALL_OBSERVE_UNCOVERED=off` in the environment. Nothing is reported while
`KNOXCALL_INTERCEPT=off`. If your key lacks `routes:read`, the first report is
refused, you get one warning, and reporting stops. `'on_observation_flush'` receives the
server's `{accepted, dropped}` after each report.

## Pagination

The server wraps every JSON response in `{data, meta}` and paginates with `page` / `per_page` (default 20, max 100). Paginated `list()` methods return that envelope; single-object methods return `data` unwrapped; `iterate()` walks all pages for you:

```php
$page = $client->routes->list(['page' => 2, 'per_page' => 50]);
// $page['data'] = rows; $page['meta'] = ['total', 'page', 'per_page', 'total_pages', 'request_id']

foreach ($client->routes->iterate() as $route) {   // every page, transparently
    echo $route['slug'], "\n";
}
```

Bare-array endpoints (`environments->list()`, `agents->list()`, `crypto->listKeys()`, `routes->listEnvironments()`, …) return a plain array and take no page params.

## Resources

```php
// Routes — reference by slug (preferred), UUID also works; bare names are legacy
$route = $client->routes->create(['name' => 'stripe', 'target_base_url' => 'https://api.stripe.com']);
$client->routes->createAction($route['id'], [
    'direction' => 'response', 'action' => 'tokenize', 'selectors' => ['$.card.number'],
]);

// Secrets
$secret = $client->secrets->create(['name' => 'Stripe Key', 'value' => 'sk_live_…']);
$client->secrets->setValue($secret['id'], 'sk_live_new', 'production');

// OAuth2 secrets — the proxy injects the provider's access token upstream
$oauth2 = $client->secrets->createOAuth2([
    'name' => 'Google Drive', 'provider' => 'google',
    'client_id' => '…', 'client_secret' => '…',
    'scopes' => ['drive.readonly'], 'grant_type' => 'authorization_code',
]);

// Certificate / mTLS secrets
$cert = $client->secrets->createCertificate([
    'name' => 'mTLS Client', 'certificate_content' => $pem,
    'private_key' => $key, 'certificate_type' => 'pem',
]);

// Wrap — escrow a raw provider credential into custody in one call.
// The value is sent ONCE and never returned; reference it by name afterwards.
$wrapped = $client->wrap->escrow([
    'provider' => 'stripe', 'name' => 'stripe-live',
    'value' => 'sk_live_…', 'hosts' => ['api.stripe.com'],
]); // => ['secret_id' => …, 'allowed_hosts' => ['api.stripe.com'], 'sandbox' => false, …]

// Webhooks (create returns the once-only secret_key — store it)
$hook = $client->webhooks->create(['name' => 'orders', 'url' => 'https://example.com/hook', 'event_types' => ['request.completed']]);
$client->webhooks->test($hook['id']);
$event = $client->webhooks->constructEvent($rawBody, $headers, $hook['secret_key']); // see below

// Clients (device/server identities) + credentials
$device = $client->clients->create(['name' => 'warehouse-pi', 'type' => 'server', 'ip_address' => '203.0.113.9']);
$client->clients->createCredential($device['id'], 'mtls_thumbprint', 'rack-4', ['mode' => 'issue']);

// OAuth clients (machine-to-machine credentials; create returns the once-only client_secret)
$oauth = $client->oauthClients->create(['name' => 'ci', 'grant_types' => ['client_credentials']]);

// Environments
$client->environments->create(['name' => 'staging', 'display_name' => 'Staging', 'color' => '#f59e0b']);

// API keys (create returns the once-only plaintext api_key)
$key = $client->apiKeys->create(['name' => 'ci key']);

// Account + usage
$account = $client->account->get();
$usage = $client->account->getUsage();

// Audit logs
foreach ($client->auditLogs->iterate(['action' => 'route.create']) as $row) { /* … */ }

// Agents (create returns the once-only agent_secret)
$agent = $client->agents->create('build-agent');

// Crypto / Transit (encryption-as-a-service)
$client->crypto->createKey(['name' => 'app-default', 'mode' => 'cloud-only']);
['ciphertext' => $ct] = $client->crypto->encrypt('app-default', ['plaintext' => 'hello']);
['plaintext' => $pt] = $client->crypto->decrypt('app-default', $ct, 'utf8');
$jwt = $client->crypto->signJwt('app-default', ['sub' => 'user_1']);

// Portable kc: encryption — arbitrary JSON in, same shape out with kc: ciphertexts
$sealed = $client->crypto->encryptData(['ssn' => '123-45-6789'], ['role' => 'pii']);
$open = $client->crypto->decryptData($sealed['ciphertext'], ['role' => 'pii']);
$client->crypto->inspect($sealed['ciphertext']['ssn']);    // metadata, no decryption
$client->crypto->mintClientToken(['action' => 'decrypt', 'data' => $sealed['ciphertext']['ssn']]); // one-shot browser reveal
$bundle = $client->crypto->getSealingBundle();              // public bits for client-side sealing

// PKI (customer CA)
$client->pki->createRoot('internal-ca', ['common_name' => 'Acme Internal CA']);
$leaf = $client->pki->issueCert('internal-ca', 'web-servers', ['common_name' => 'api.internal.test']);
$pem = $client->pki->getRootCert('internal-ca');            // raw PEM string

// Vaults (tokenization)
$vault = $client->vaults->create(['name' => 'cards', 'token_format' => 'pan']);
$token = $client->vaults->tokenize('cards', '4242424242424242');
$value = $client->vaults->detokenize('cards', $token['token']);
foreach ($client->vaults->iterateTokens('cards') as $t) { /* … */ }

// Dynamic DB credentials
$client->dynamicDb->create(['name' => 'analytics-db', 'engine' => 'postgres', /* … */]);
$creds = $client->dynamicDb->mint('analytics-db', 'read-only', 3600); // once-only password
$client->dynamicDb->listLeases();

// AI Gateway (secret → gateway → agent → capability token).
// provider + upstream_secret_id compose the upstream route. An agent created
// with NEITHER those nor primary_route_id has no upstream and 502s on its
// first data-plane call. provider is a plain string — the catalog is
// server-side, and a bad value returns a 400 naming the valid set.
$secret = $client->secrets->create(['name' => 'anthropic-key', 'value' => getenv('ANTHROPIC_API_KEY')]);
$gw = $client->aiGateway->createGateway(['name' => 'Prod', 'slug' => 'prod']);
$agent = $client->aiGateway->createAgent($gw['id'], [
    'name' => 'summarizer', 'slug' => 'summarizer',
    'provider' => 'anthropic', 'upstream_secret_id' => $secret['id'],
    'default_model' => 'claude-sonnet-5',
]);
$minted = $client->aiGateway->mintToken($agent['id'], ['kind' => 'agent']); // $minted['token'] is plaintext shown ONCE
foreach ($client->aiGateway->iterateGateways() as $g) { /* … */ }
$usage = $client->aiGateway->usage(['period' => '30d']); // cost + tokens by model

// Signup — credential-less, static (no client needed). Two steps: signup()
// returns a claim handle and emails a sign-in link; claimSignup() collects the
// one-time test key once the owner has clicked it.
$accepted = KnoxCall::signup(['email' => 'dev@example.com', 'tenant_name' => 'Acme Inc']);
$claim = KnoxCall::claimSignup($accepted['claim_handle']);   // poll until status === 'ready'
$client = new KnoxCall(['api_key' => $claim['starter']['api_key']['api_key'], 'sandbox' => true]);
```

## Webhook verification

`constructEvent()` verifies the delivery AND parses it in one step — use it in your webhook endpoint with the RAW request body:

```php
use KnoxCall\KnoxCall;
use KnoxCall\WebhookSignatureVerificationException;

$rawBody = file_get_contents('php://input');

try {
    $event = KnoxCall::constructEvent($rawBody, getallheaders(), $endpointSecret, [
        'format' => 'stripe',          // must match the webhook's hmac_format (default 'legacy')
        'tolerance_seconds' => 300,    // replay window; null disables
    ]);
} catch (WebhookSignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

match ($event['event']) {
    'request.server_error' => alert($event['data']['route_name'], $event['data']['response']['status']),
    'audit.event' => log($event['data']['action']),
    default => null, // the event-type list is open — unknown types still parse
};
```

Supported formats mirror the server exactly: `legacy`, `stripe`, `github`, `slack`, `aws-sns`, and `custom` (pass `header_name`). All are HMAC-SHA256 with constant-time comparison. The boolean `KnoxCall::verifySignature()` helper remains for stripe-shaped headers when you only need a yes/no.

## Errors

```text
KnoxCallException                       base (extends RuntimeException)
├── ApiException                        HTTP errors (->statusCode, ->errorCode, ->requestId, ->responseHeaders, ->responseBody)
│   ├── AuthenticationException         401
│   ├── PermissionDeniedException       403
│   ├── NotFoundException               404
│   ├── ConflictException               409
│   ├── ValidationException             422 (->fields)
│   ├── RateLimitException              429 (->retryAfter)
│   ├── ServerException                 5xx
│   └── SignupException                 KnoxCall::signup() failures
├── ConnectionException                 transport failures (->requestSent)
│   └── ConnectionTimeoutException
└── WebhookSignatureVerificationException   constructEvent() failures
```

Every `ApiException` carries the server's `request_id` (`->requestId`) — include it when contacting support.

```php
try {
    $client->routes->create(['name' => 'x', 'target_base_url' => 'y']);
} catch (\KnoxCall\RateLimitException $e) {
    sleep($e->retryAfter ?? 1);
} catch (\KnoxCall\ValidationException $e) {
    var_dump($e->fields);
}
```

## Retries & idempotency

Management requests retry HTTP 408/429/500/502/503/504 (never 409) with half-jitter exponential backoff, honoring `Retry-After` up to 30s; a 401 purges the cached token and re-auths once. Every mutating request carries a ULID `X-Idempotency-Key` that stays stable across retries, so replays are safe. Data-plane calls never replay a mutation that may have reached the upstream. Configure with `retry_max_attempts`, `retry_base_delay_ms`, `retry_max_delay_ms`, `timeout_ms`.

## DPoP — sender-constrained tokens

For higher-security tenants, enable DPoP (RFC 9449; requires `ext-openssl`):

```php
$client = new KnoxCall(['tenant' => 'acme', 'dpop' => 'always']);
```

The SDK generates an ES256 keypair, binds the access token to it via the `cnf.jkt` claim, and signs a fresh proof JWT per request — token, management, and data-plane alike. Stolen tokens become useless without the keypair.

In the default `'auto'` mode the SDK starts with plain Bearer tokens and upgrades to DPoP automatically when the OAuth client record has `require_dpop` set (the token endpoint answers `invalid_dpop_proof`; the SDK generates a keypair, retries once, and operates as DPoP from then on). `'dpop' => 'never'` opts out — if the server issues a DPoP-bound token anyway, the SDK throws a typed exception rather than 401-looping.

## Route references

Reference routes by **slug** — the write-once machine handle set on the route (rename-proof, portable across tenants). UUIDs also work; bare names are legacy.
