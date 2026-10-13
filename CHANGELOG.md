# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/); versions follow
[Semantic Versioning](https://semver.org/).

**First release: 1.0.0, 2026-09-27** — published to Packagist as `knoxcall/sdk` (monorepo tag `knoxcall-php-v1.0.0`).

Release headings take the form `## [X.Y.Z] — YYYY-MM-DD`.
`tests/coverage/sdk-changelog-honesty.test.ts` refuses any release heading that
has no matching git tag, so this file cannot claim a release that did not happen.

## [Unreleased]

### Changed
- **1.1.0 — registry install instructions.** A registry renders the README that was inside the version it published and never lets it be edited, so the README inside the previous release still showed pre-release install instructions (build from a source checkout) after the package was live; the corrected README reaches a registry only as a new version. Packagist and the `KnoxCall/sdk-php` mirror render the README from the mirror, which resyncs on the next push to `main`; the `v1.1.0` tag is what lists the version. `KnoxCall::SDK_VERSION` (the `knoxcall-php/<v>` User-Agent) is `1.1.0`. The version is a minor, not a patch: the `Retry-After` change below adds `ServerException::$retryAfter`, which is new public API.
- **`Retry-After` is honoured on a 503, not only on a 429.** KnoxCall now answers a request it could not serve because one of its own dependencies did not answer in time with `503 { error: { type: "dependency_unavailable", … } }` + `Retry-After` (on the data plane this used to surface as an opaque `401 Unauthorized`; on `/v1` as `500 internal_error`). `ServerException` exposes the header as `->retryAfter` (`null` when none was sent), and the retry loop waits it — capped at 30 s — before the next attempt, exactly as it does for a 429; a plain 5xx with no header keeps the jittered backoff. Nothing to change in calling code: it is a retryable server error, never an authentication failure, and never a manifest-refresh trigger. (PARITY §4, §16.)

### Fixed
- **`ServerException`'s constructor stays 1.0.0-compatible; `retryAfter` is the last parameter.** It is `ApiException`'s constructor parameter for parameter (`$message, $statusCode, $errorCode, $requestId, $responseHeaders, $responseBody, $previous`) followed by `?int $retryAfter = null`, so `new ServerException('boom', 500, 'internal_error', 'req_1')` means what it meant in 1.0.0. An unreleased revision of the `Retry-After` change above had inserted `$retryAfter` as the third parameter, which made that call a `TypeError` and, with a `null` error code, silently filed the request id as the error code. No release carried it. Pass it by name: `new ServerException('shed', 503, retryAfter: 5)`. The 1.0.0 constructor shape of every exception class is now pinned by `tests/ExceptionConstructorCompatTest.php`.

## [1.0.0] — 2026-09-27

### Changed
- **SDK version is 1.0.0** (`SDK_VERSION`, sent in the user agent; was 0.1.0), aligned with the other SDKs for the first release. The release tag on `KnoxCall/sdk-php` is `v1.0.0`.
- **Composer package is now `knoxcall/sdk`** (was `knoxcall/knoxcall-php`), so every KnoxCall package follows one `knoxcall/sdk` convention. Nothing was ever published under the old name. The `KnoxCall\` namespace and the `knoxcall` bin are unchanged.
- **Refusal-driven refresh learns the 404.** The route data plane now answers an AUTHENTICATED credential's call to a Route that does not resolve with `404 {"error":{"type":"route_not_found"|"environment_not_configured"|"environment_disabled","message","request_id"}}` plus the KnoxCall response block, instead of the opaque `401 Unauthorized` (which callers the tenant has not authenticated keep — founder decision 2026-09-26, PARITY §21). `wrap->httpClient(['routes' => 'auto'])` and `wrap->guzzleMiddleware()` now treat a KnoxCall-origin `404` whose envelope `error.type` is `route_not_found` as a refresh trigger alongside the 401 — a stale manifest naming a deleted Route is exactly that — refreshing once and re-deciding once, never looping (`Wrap\RouteRefusal`). The `environment_*` types are surfaced as-is; an UPSTREAM 404 (`X-Knox-Upstream-Status` present) never triggers it, whatever its body says. `on_refused` reports `status` 404 for that case. `call()` is unchanged: it returns the 404 raw (PARITY §5) and spends no re-mint on it. Cross-language contract: `sdk/fixtures/route-refusal.json`.

### Added
- **Uncovered-egress observations (PARITY §21.3), on by default.** `wrap->guzzleMiddleware()` (and `wrap->httpClient(['routes' => 'auto'])`) now counts calls sent direct because their host is `unlisted` while they carry a credential-bearing header — host, first path segment, method and the header NAME; never the value, the query string or the body — and reports them to `POST /v1/wrap/egress-observations`: at 200 distinct keys, on the first call after ~60 s (PHP has no background timer), on `stop()`, and at process shutdown. Opt out with `'observe_uncovered' => false` or `KNOXCALL_OBSERVE_UNCOVERED=off`; nothing is reported while `KNOXCALL_INTERCEPT=off`; a 403 stops reporting with one warning. New `on_observation_flush` hook, `wrap->reportEgressObservations($observations)`, `Wrap\EgressObservations`, `Wrap\EgressObservationReporter`; `KnoxCall::SDK_VERSION` is now public. (Founder decision 2026-09-26: default-on with an opt-out.)
- **A credential in the path is never reported.** Before an uncovered-egress observation is sent, a first path segment that looks like a credential (Telegram's `/bot<id>:<secret>`, a Stripe/GitHub/AWS/Google/Slack/JWT token, any segment over 64 characters, or a 24+ character mixed-class run — raw or percent-decoded) is reported as `/`; the server's identical rule (#1022) counts it under the receipt's new `redacted` field, now on the report type. (PARITY §21.3.)
- `$knox->wrap->interceptManifest(['if_none_match' => $version])` — the conditional poll. Pass the manifest `version` you hold and the SDK sends `If-None-Match: W/"<version>"` (`WrapResource::manifestEtag()`); the server's `304` returns `null` — keep what you hold (the return type is now `?array`; the unconditional call never returns `null`). The route-aware store (`wrap->httpClient(['routes' => 'auto'])`, `wrap->guzzleMiddleware()`) now polls this way on every refresh after the first, lazy or forced: a `304` keeps the manifest, restarts the TTL clock, clears backoff, re-stamps the shared PSR-16 record and fires no `on_refresh`, so a steady-state poll costs no body bytes. A `manifest_fetch` closure that declares no parameter keeps polling unconditionally. `KnoxCall::request()` gained a trailing `bool $allowNotModified = false` (internal; answered with the `@internal` `NotModified` marker). Auth, the one re-auth on 401 and retries are unchanged. (PARITY §21.1 "Conditional poll"; fixture `sdk/fixtures/intercept-store-conditional.json`.)
- **Origin marker on rerouted calls.** Every route-mode send from `wrap->httpClient(['routes' => 'auto'])` / `wrap->guzzleMiddleware()` (and the legacy explicit `route` form) now carries `x-knoxcall-origin: sdk-intercept`, so the API Log shows the call as **SDK intercept** rather than **Direct** (`client_origin` on request-log rows: `direct` | `sdk_intercept`). A direct `call()` / bound route sends nothing; an ephemeral hop sends nothing. A caller-supplied `x-knoxcall-origin` in `call()` / `ephemeral()` `headers` is stripped like the proxy-auth headers — the server treats the marker as informational either way. The seam is `call()`'s internal `'_origin'` option (only `KnoxCall::SDK_INTERCEPT_ORIGIN` is accepted; anything else throws `InvalidArgumentException`). (PARITY §21.2.)
- **Route-aware interception.** `wrap->httpClient(['routes' => 'auto'])` — the PSR-18 client now consults the intercept manifest (`GET /v1/wrap/intercept-manifest`, refreshed lazily at its TTL, optionally shared across processes via a PSR-16 `cache`) and sends each request through the Route that covers its host + path (the Route injects the secret; no provider credential travels), through the ephemeral proxy otherwise — an explicit transport treats every host as listed. The default stays `'off'`. New `wrap->guzzleMiddleware(['hosts' => …])` for a Guzzle `HandlerStack` an SDK builds itself (route discovery on by default; only route-covered or listed hosts are touched, decision D2). The returned `KnoxCallHttpClient` / `GuzzleMiddleware::pipeline()` carry `ready()`, `refresh()`, `manifest()`, `stop()`. New options: `hosts` (per-host `credential` / `unavailable`), `unavailable` (`'direct'`, transit only — route mode and escrow always fail closed, D4), `cache`, and the hooks `on_reroute`, `on_refresh`, `on_manifest_error`, `on_unmatched_path`, `on_refused`, `on_fallback`; a typo'd `on_*` option or a non-bare host is refused at construction. `KNOXCALL_INTERCEPT=off` is the kill switch. New classes `Wrap\InterceptResolver`, `Wrap\InterceptManifestStore`, `Wrap\InterceptPipeline`, `Wrap\GuzzleMiddleware`. PHP has no process-wide HTTP seam, so there is no `intercept()` — documented, not faked. (route-aware-interception-plan.md PR5; PARITY §21.1.) `guzzlehttp/guzzle` is a dev dependency only.
- `wrap->interceptManifest(['environment' => …])` — `GET /v1/wrap/intercept-manifest`, the per-environment list of upstream hosts an intercept-enabled Route covers (`version` doubles as the ETag). What a route-aware interceptor polls (route-aware-interception-plan.md PR1). `intercept_enabled` is accepted by `routes->create/update/upsertEnvironment` input arrays and echoed on reads.

### Fixed
- `CurlTransport` no longer calls `curl_close()`: a no-op since PHP 8.0 (the handle is freed when it goes out of scope) and deprecated in PHP 8.5, where it printed a deprecation on every request. The package floor is `>=8.1`.
- `call()` — and bound routes, the CLI and the wrap transports' route mode, which delegate to it — now places the upstream path under the tenant host's `/api` data-plane entry point whenever the proxy base is a KnoxCall cloud tenant host with no path of its own (derived, or an explicit override naming one); any other base is used verbatim. Before, `$client->call('r', ['path' => '/users'])` sent `https://{tenant}.knoxcall.com/users`, which a tenant host answers with the dashboard, not the proxy — every documented example was affected, and `'path' => '/api/…'` was the only form that worked. `path` is now always the upstream path (PARITY §5).
- `call()` / `ephemeral()` no longer spend their one token re-mint on an UPSTREAM 401 relayed by the data plane: a response carrying `X-Knox-Upstream-Status` (the route data plane's response block) or `X-Knox-Destination-Status` (the ephemeral proxy) is the upstream's answer and is returned as-is. Mirrors the Node, Python and Go fix.
- `WrapTransport::normalizeHost()` now also trims surrounding whitespace and IPv6 brackets (PARITY §21's host contract).

The package's behaviour is specified by [`sdk/PARITY.md`](../PARITY.md), which is
authoritative over this file for anything describing current behaviour.

### Added

- `KnoxCall\Auth\WorkloadCredentialProvider` — caches a workload-identity
  capability token and refreshes it on a two-tier schedule (advisory at
  expiry-120s, mandatory at expiry-30s), calling the assertion callable before
  every exchange. Because KnoxCall assertions are single-use, a source that returns
  bytes already spent is refused locally with `StaleAssertionException` rather than
  sent and refused as a replay. PARITY section 20.

### Owed at first release

- Replace the local-path / `git` install in the public docs with a **pinned**
  registry install (`composer require knoxcall/sdk:^X.Y`) — see `sdk/PARITY.md` §"Documented installs must
  pin a version".
- Write the first `## [X.Y.Z] — YYYY-MM-DD` heading, and tag it.
