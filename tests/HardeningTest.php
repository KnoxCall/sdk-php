<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\ApiException;
use KnoxCall\Auth\AccessToken;
use KnoxCall\Auth\ClientCredentials;
use KnoxCall\Auth\DpopKeyPair;
use KnoxCall\Auth\OIDCTokenExchange;
use KnoxCall\AuthenticationException;
use KnoxCall\ConflictException;
use KnoxCall\ConnectionException;
use KnoxCall\ConnectionTimeoutException;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\NotFoundException;
use KnoxCall\PaymentRequiredException;
use KnoxCall\PermissionDeniedException;
use KnoxCall\RateLimitException;
use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase
{
    private const SECRET = 'kc_secret_shh_dont_leak';

    /** The real management-API success envelope (src/client-api/helpers.ts). */
    private static function envelope(mixed $data): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    /** The real management-API error envelope. */
    private static function apiError(string $type, string $message): array
    {
        return ['error' => ['type' => $type, 'message' => $message, 'request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    private function makeClient(MockTransport $transport, array $opts = []): KnoxCall
    {
        return new KnoxCall($opts + [
            'tenant' => 'acme',
            'credentials' => new ClientCredentials('cid_1', self::SECRET),
            'transport' => $transport,
            'retry_base_delay_ms' => 0,
            'base_url' => 'https://api.example.test',
            'proxy_base_url' => 'https://acme.example.test',
        ]);
    }

    // -- call() hardening ------------------------------------------------------

    public function testCall401PurgesTokenAndRemintsOnce(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_revoked');
        $t->queueJson(401, ['error' => 'invalid_token']);
        $t->queueToken('kc_live_fresh');
        $t->queueJson(200, ['ok' => true]);

        $res = $this->makeClient($t)->call('r_1', ['path' => '/things']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(4, $t->requests);
        $this->assertSame('Bearer kc_live_fresh', $t->header(3, 'Authorization'));
    }

    public function testCallSecond401IsReturnedAsIsNotLooped(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(401, ['error' => 'invalid_token']);
        $t->queueToken('kc_live_two');
        $t->queueJson(401, ['error' => 'invalid_token']);

        $res = $this->makeClient($t)->call('r_1', ['path' => '/things']);

        $this->assertSame(401, $res['status']);
        $this->assertCount(4, $t->requests);
    }

    public function testCallReturnsUpstreamErrorsRawWithoutThrowing(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueRaw(503, 'upstream exploded', ['content-type' => 'text/plain']);

        $res = $this->makeClient($t)->call('r_1', ['path' => '/things']);

        $this->assertSame(503, $res['status']);
        $this->assertSame('upstream exploded', $res['body']);
        $this->assertSame('text/plain', $res['headers']['content-type']);
    }

    public function testMutatingCallIsNeverReplayedWhenRequestMayHaveBeenSent(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueThrow(new ConnectionTimeoutException('read timeout', requestSent: true));

        try {
            $this->makeClient($t)->call('r_1', ['method' => 'POST', 'path' => '/charges', 'body' => ['amount' => 5]]);
            $this->fail('expected ConnectionTimeoutException');
        } catch (ConnectionTimeoutException $e) {
            $this->assertTrue($e->requestSent);
        }
        // token mint + exactly ONE proxied attempt — the POST was not replayed.
        $this->assertCount(2, $t->requests);
    }

    public function testMutatingCallRetriedWhenConnectionNeverEstablished(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueThrow(new ConnectionException('connection refused', requestSent: false));
        $t->queueJson(200, ['ok' => true]);

        $res = $this->makeClient($t)->call('r_1', ['method' => 'POST', 'path' => '/charges', 'body' => ['amount' => 5]]);

        $this->assertSame(200, $res['status']);
        $this->assertCount(3, $t->requests);
    }

    public function testGetCallRetriedOnReadTimeout(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueThrow(new ConnectionTimeoutException('read timeout', requestSent: true));
        $t->queueJson(200, ['ok' => true]);

        $res = $this->makeClient($t)->call('r_1', ['path' => '/things']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(3, $t->requests);
    }

    public function testPerCallTimeoutOverride(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->call('r_1', ['path' => '/things', 'timeout_ms' => 1234]);

        $this->assertSame(1234, $t->lastRequest()['timeoutMs']);
        // default still applies to the token request
        $this->assertSame(30000, $t->requests[0]['timeoutMs']);
    }

    public function testExplicitRouteArgumentWinsOverCallerHeaders(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->call('r_good', [
            'path' => '/things',
            'environment' => 'production',
            'headers' => [
                'X-KnoxCall-Route' => 'r_evil',
                'X-KnoxCall-Environment' => 'test',
                'Authorization' => 'Bearer stolen',
            ],
        ]);

        $this->assertSame('r_good', $t->header(1, 'x-knoxcall-route'));
        $this->assertSame('production', $t->header(1, 'x-knoxcall-environment'));
        $this->assertSame('Bearer kc_live_one', $t->header(1, 'Authorization'));
    }

    // -- SDK auth is the sole data-plane authority (PARITY §5, hardened 2026-08) --

    public function testCallStripsCallerSuppliedProxyAuthHeaders(): void
    {
        // An integrator forwarding untrusted end-user headers must not be able
        // to inject an alternate proxy identity: call() strips every
        // caller-supplied proxy-auth header before setting the SDK credential.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->call('r_1', [
            'path' => '/things',
            'headers' => [
                'Authorization' => 'Bearer attacker_token',
                'DPoP' => 'attacker_proof',
                'X-KnoxCall-Key' => 'tk_attacker',
                'X-KnoxCall-Agent-Id' => 'agent_evil',
                'X-KnoxCall-Agent-Token' => 'agent_secret',
                // The interceptors' reroute marker (PARITY §21.2) is SDK-owned
                // too: an app must not relabel its own direct calls as intercepted.
                'X-KnoxCall-Origin' => 'sdk-intercept',
                'X-Trace-Id' => 'keep-me', // a non-auth caller header survives
            ],
        ]);

        // The SDK's own credential reaches the wire as the sole proxy authority.
        $this->assertSame('Bearer kc_live_sdk', $t->header(1, 'Authorization'));
        // Every caller-supplied proxy-auth header was stripped before dispatch
        // (the SDK sets none of these on a kc_ Bearer call, so a survivor here
        // could only be the caller's injected copy).
        $this->assertNull($t->header(1, 'DPoP'));
        $this->assertNull($t->header(1, 'x-knoxcall-key'));
        $this->assertNull($t->header(1, 'x-knoxcall-agent-id'));
        $this->assertNull($t->header(1, 'x-knoxcall-agent-token'));
        $this->assertNull($t->header(1, 'x-knoxcall-origin'));
        // A non-auth caller header still passes through untouched.
        $this->assertSame('keep-me', $t->header(1, 'X-Trace-Id'));
    }

    public function testDirectCallCarriesNoOriginMarkerAndRejectsAnUnknownOne(): void
    {
        // A direct call() sends no x-knoxcall-origin — absence IS "direct" on
        // the server (PARITY §21.2) — and the internal '_origin' option accepts
        // only the one marker, so a typo cannot silently send nothing.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t);
        $client->call('r_1', ['path' => '/things']);
        $this->assertSame('r_1', $t->header(1, 'x-knoxcall-route'));
        $this->assertNull($t->header(1, 'x-knoxcall-origin'));

        $client->route('r_1')->get('/things');
        $this->assertNull($t->header(2, 'x-knoxcall-origin'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown call origin');
        $client->call('r_1', ['path' => '/things', '_origin' => 'something-else']);
    }

    public function testCallLegacyKeyPathStripsCallerAuthorization(): void
    {
        // On the legacy-key path the SDK sends its credential as x-knoxcall-key;
        // a caller-supplied "Authorization: Bearer" would be honored by the
        // proxy OVER the SDK's key, so it must be stripped.
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]); // legacy key = no token mint

        $client = new KnoxCall([
            'tenant' => 'acme',
            'api_key' => 'tk_live_legacy',
            'transport' => $t,
            'base_url' => 'https://api.example.test',
            'proxy_base_url' => 'https://acme.example.test',
        ]);
        $client->call('r_1', [
            'path' => '/things',
            'headers' => ['Authorization' => 'Bearer attacker_kc_token'],
        ]);

        // The SDK's legacy key travels as x-knoxcall-key; the caller's injected
        // Authorization is gone, so the proxy can only honor the SDK identity.
        $this->assertSame('tk_live_legacy', $t->header(0, 'x-knoxcall-key'));
        $this->assertNull($t->header(0, 'Authorization'));
    }

    public function testEphemeralStripsCallerSuppliedProxyAuthHeaders(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->ephemeral('https://upstream.test/v1/charge', [
            'method' => 'POST',
            'body' => ['amount' => 1],
            'headers' => [
                'Authorization' => 'Bearer attacker_token',
                'X-KnoxCall-Agent-Id' => 'agent_evil',
                'X-Trace-Id' => 'keep-me',
            ],
        ]);

        // ephemeral() targets /v1/proxy (any credential format as Bearer), so
        // the SDK sends its kc_ token as Bearer — and the caller's copies are gone.
        $this->assertSame('Bearer kc_live_sdk', $t->header(1, 'Authorization'));
        $this->assertNull($t->header(1, 'x-knoxcall-agent-id'));
        $this->assertSame('keep-me', $t->header(1, 'X-Trace-Id'));
    }

    public function testEphemeralWrapSupportOptionsEmitProxyModeAndUpstreamAuthHeaders(): void
    {
        // PR2 wrap-support: mode:'transparent' + upstream_authorization are
        // purely additive and surface at the SDK's HTTP boundary as
        // X-Knox-Proxy-Mode / X-Knox-Upstream-Authorization.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->ephemeral('https://api.stripe.test/v1/charges', [
            'method' => 'POST',
            'mode' => 'transparent',
            'upstream_authorization' => 'Bearer sk_test_provider_secret',
            'body' => 'amount=100&currency=usd',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        // (a) mode:'transparent' emits the proxy-mode header verbatim.
        $this->assertSame('transparent', $t->header(1, 'X-Knox-Proxy-Mode'));
        // (b) upstream_authorization emits the upstream Authorization header verbatim.
        $this->assertSame('Bearer sk_test_provider_secret', $t->header(1, 'X-Knox-Upstream-Authorization'));
        // The SDK's own KnoxCall auth is unaffected — it still travels as Bearer.
        $this->assertSame('Bearer kc_live_sdk', $t->header(1, 'Authorization'));
    }

    public function testEphemeralEscrowedSecretOptionsEmitUpstreamAuthSecretAndSchemeHeaders(): void
    {
        // PR3 wrap-support: upstream_auth_secret names an escrowed wrap credential
        // that the server resolves + injects host-pinned; upstream_auth_scheme
        // selects the auth scheme. Both are purely additive and surface at the
        // SDK's HTTP boundary as X-Knox-Upstream-Auth-Secret / -Auth-Scheme.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->ephemeral('https://api.stripe.test/v1/charges', [
            'method' => 'POST',
            'mode' => 'transparent',
            'upstream_auth_secret' => 'stripe_provider_key',
            'upstream_auth_scheme' => 'Bearer',
            'body' => 'amount=100&currency=usd',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        // (a) upstream_auth_secret emits the escrowed-credential name verbatim.
        $this->assertSame('stripe_provider_key', $t->header(1, 'X-Knox-Upstream-Auth-Secret'));
        // (b) upstream_auth_scheme emits the scheme header verbatim.
        $this->assertSame('Bearer', $t->header(1, 'X-Knox-Upstream-Auth-Scheme'));
        // The SDK's own KnoxCall auth is unaffected — it still travels as Bearer.
        $this->assertSame('Bearer kc_live_sdk', $t->header(1, 'Authorization'));
    }

    public function testEphemeralWrapSupportOptionsAreAbsentByDefault(): void
    {
        // Omitting the new options leaves the request byte-for-byte as before:
        // no wrap-support header is present.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->ephemeral('https://upstream.test/v1/charge', [
            'method' => 'POST',
            'body' => ['amount' => 1],
        ]);

        $this->assertNull($t->header(1, 'X-Knox-Proxy-Mode'));
        $this->assertNull($t->header(1, 'X-Knox-Upstream-Authorization'));
        $this->assertNull($t->header(1, 'X-Knox-Upstream-Auth-Secret'));
        $this->assertNull($t->header(1, 'X-Knox-Upstream-Auth-Scheme'));
    }

    public function testBoundRouteDelegationStripsCallerSuppliedProxyAuthHeaders(): void
    {
        // Bound-route calls delegate to call(), so they inherit the strip.
        $t = new MockTransport();
        $t->queueToken('kc_live_sdk');
        $t->queueJson(200, ['ok' => true]);

        $route = $this->makeClient($t)->route('r_1', ['environment' => 'production']);
        $route->get('/things', ['headers' => [
            'X-KnoxCall-Agent-Id' => 'agent_evil',
            'Authorization' => 'Bearer attacker_token',
            'X-Trace-Id' => 'keep-me',
        ]]);

        $this->assertSame('Bearer kc_live_sdk', $t->header(1, 'Authorization'));
        $this->assertNull($t->header(1, 'x-knoxcall-agent-id'));
        $this->assertSame('keep-me', $t->header(1, 'X-Trace-Id'));
    }

    // -- Request bodies ----------------------------------------------------------

    public function testRawStringBodyPassesThroughWithCallerContentType(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t)->call('r_1', [
            'method' => 'POST',
            'path' => '/import',
            'body' => "a,b\n1,2\n",
            'headers' => ['Content-Type' => 'text/csv'],
        ]);

        $this->assertSame("a,b\n1,2\n", $t->lastRequest()['body']);
        $this->assertSame('text/csv', $t->header(1, 'Content-Type'));
    }

    public function testDateTimeEncodedAsIso8601(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $when = new \DateTimeImmutable('2026-06-10T01:02:03+00:00');
        $this->makeClient($t)->request('POST', '/v1/routes', null, [
            'name' => 'r',
            'expires' => $when,
            'nested' => ['at' => $when],
        ]);

        $decoded = json_decode($t->lastRequest()['body'], true);
        $this->assertSame('2026-06-10T01:02:03+00:00', $decoded['expires']);
        $this->assertSame('2026-06-10T01:02:03+00:00', $decoded['nested']['at']);
        $this->assertSame('application/json', $t->header(1, 'Content-Type'));
    }

    public function testJsonDefaultHookHandlesUnsupportedObjects(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = $this->makeClient($t, [
            'json_default' => static fn (object $o) => 'hooked:' . $o::class,
        ]);
        $client->request('POST', '/v1/routes', null, ['thing' => new \SplStack()]);

        $decoded = json_decode($t->lastRequest()['body'], true);
        $this->assertSame('hooked:SplStack', $decoded['thing']);
    }

    // -- Management retry --------------------------------------------------------

    public function testManagement401PurgesTokenAndReauthsOnce(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_revoked');
        $t->queueJson(401, self::apiError('unauthorized', 'Invalid or expired token.'));
        $t->queueToken('kc_live_fresh');
        $envelope = self::envelope(['id' => 'rt_1']);
        $t->queueJson(200, $envelope);

        $out = $this->makeClient($t)->request('GET', '/v1/routes/rt_1');

        // The core request() is envelope-agnostic — unwrapping lives in the
        // resource layer.
        $this->assertSame($envelope, $out);
        $this->assertSame('Bearer kc_live_fresh', $t->header(3, 'Authorization'));
    }

    public function testManagementSecond401Surfaces(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(401, self::apiError('unauthorized', 'Invalid or expired token.'));
        $t->queueToken('kc_live_two');
        $t->queueJson(401, self::apiError('unauthorized', 'Invalid or expired token.'));

        $this->expectException(AuthenticationException::class);
        $this->makeClient($t)->request('GET', '/v1/routes');
    }

    public function testManagementDoesNotRetry409(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(409, self::apiError('conflict', 'name taken'));

        try {
            $this->makeClient($t)->request('POST', '/v1/routes', null, ['name' => 'dupe']);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->statusCode);
            // the nested v1 error envelope maps type → errorCode, message → message
            $this->assertSame('conflict', $e->errorCode);
            $this->assertStringContainsString('name taken', $e->getMessage());
        }
        // token + a single attempt — a real conflict is never replayed.
        $this->assertCount(2, $t->requests);
    }

    public function testManagementRetries503ThenSucceeds(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(503, self::apiError('internal_error', 'unavailable'));
        $envelope = self::envelope(['ok' => true]);
        $t->queueJson(200, $envelope);

        $out = $this->makeClient($t)->request('GET', '/v1/routes');

        $this->assertSame($envelope, $out);
        $this->assertCount(3, $t->requests);
    }

    public function testIdempotencyKeyStableAcrossRetriesAndAbsentOnGet(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(503, self::apiError('internal_error', 'unavailable'));
        $t->queueJson(200, self::envelope(['ok' => true]));
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = $this->makeClient($t);
        $client->request('POST', '/v1/routes', null, ['name' => 'r']);

        $first = $t->header(1, 'X-Idempotency-Key');
        $second = $t->header(2, 'X-Idempotency-Key');
        $this->assertNotNull($first);
        $this->assertSame(26, strlen($first));
        $this->assertSame($first, $second);

        $client->request('GET', '/v1/routes');
        $this->assertNull($t->header(3, 'X-Idempotency-Key'));
    }

    public function testRetryAfterParsedAndCappedAt30s(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(429, self::apiError('rate_limited', 'Too many requests.'), ['retry-after' => '120']);

        $client = $this->makeClient($t, ['retry_max_attempts' => 1]);
        try {
            $client->request('GET', '/v1/routes');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(120, $e->retryAfter);
        }

        $delay = new \ReflectionMethod($client, 'retryDelayMs');
        $this->assertSame(30_000, $delay->invoke($client, new RateLimitException('rl', 429, 120), 1));
        $this->assertSame(2_000, $delay->invoke($client, new RateLimitException('rl', 429, 2), 1));
    }

    // -- Token lifecycle -----------------------------------------------------------

    public function testTokenCachedAndRefreshAheadUsesHalfLifetimeForShortTokens(): void
    {
        $now = 1_000_000.0;
        $t = new MockTransport();
        // string expires_in must parse robustly
        $t->queueToken('kc_live_short', '60');
        $t->queueJson(200, self::envelope(['ok' => 1]));
        $t->queueJson(200, self::envelope(['ok' => 2]));

        $client = $this->makeClient($t, ['clock' => function () use (&$now): float {
            return $now;
        }]);

        $client->request('GET', '/v1/routes');
        // 29s in: remaining 31s > half-lifetime window (30s) → cached token reused.
        $now += 29;
        $client->request('GET', '/v1/routes');
        $this->assertCount(3, $t->requests); // exactly one token mint

        // 31s in: remaining 29s < 30s window → refresh-ahead mints a new token.
        $now += 2;
        $t->queueToken('kc_live_short2', '60');
        $t->queueJson(200, self::envelope(['ok' => 3]));
        $client->request('GET', '/v1/routes');
        $this->assertSame('Bearer kc_live_short2', $t->header(4, 'Authorization'));
    }

    public function testStaleButValidTokenUsedWhenTokenEndpointIsDown(): void
    {
        $now = 1_000_000.0;
        $t = new MockTransport();
        $t->queueToken('kc_live_stale', 600);
        $t->queueJson(200, self::envelope(['ok' => 1]));

        $client = $this->makeClient($t, ['clock' => function () use (&$now): float {
            return $now;
        }]);
        $client->request('GET', '/v1/routes');

        // Inside the refresh-ahead window (remaining 200s < 300s) but still
        // valid; the refresh attempt fails → stale token serves the request.
        $now += 400;
        $t->queueThrow(new ConnectionException('token endpoint down', requestSent: false));
        $envelope = self::envelope(['ok' => 2]);
        $t->queueJson(200, $envelope);
        $out = $client->request('GET', '/v1/routes');

        $this->assertSame($envelope, $out);
        $this->assertSame('Bearer kc_live_stale', $t->header(3, 'Authorization'));
    }

    public function testNonJsonTokenResponseRaisesTypedError(): void
    {
        $t = new MockTransport();
        $t->queueRaw(200, '<html>edge proxy interstitial</html>');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('unexpected response');
        $this->makeClient($t)->request('GET', '/v1/routes');
    }

    public function testMissingAccessTokenRaisesTypedError(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['token_type' => 'Bearer', 'expires_in' => 3600]);

        $this->expectException(AuthenticationException::class);
        $this->makeClient($t)->request('GET', '/v1/routes');
    }

    // -- DPoP (PARITY §7) --------------------------------------------------------

    /** @return array<string, mixed> */
    private static function decodeJwtPart(string $part): array
    {
        $decoded = json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);
        self::assertIsArray($decoded, 'JWT part did not decode to a JSON object');
        return $decoded;
    }

    public function testDpopBoundTokenWithoutKeypairRaisesClearError(): void
    {
        // The server hands back a DPoP-bound token without ever challenging
        // (no invalid_dpop_proof), so no keypair exists in either mode — the
        // SDK must fail loudly, never send "Authorization: DPoP" proofless.
        foreach (['never', 'auto'] as $mode) {
            $t = new MockTransport();
            $t->queueJson(200, ['access_token' => 'x', 'token_type' => 'DPoP', 'expires_in' => 3600]);
            try {
                $this->makeClient($t, ['dpop' => $mode])->request('GET', '/v1/routes');
                $this->fail("expected a clear DPoP error for mode \"{$mode}\"");
            } catch (KnoxCallException $e) {
                $this->assertStringContainsString('DPoP', $e->getMessage());
            }
        }
    }

    public function testDpopInvalidModeRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dpop');
        $this->makeClient(new MockTransport(), ['dpop' => 'sometimes']);
    }

    public function testDpopAutoUpgradesWhenClientRequiresIt(): void
    {
        $t = new MockTransport();
        $t->queueJson(400, ['error' => 'invalid_dpop_proof', 'error_description' => 'DPoP proof required']);
        $t->queueJson(200, ['access_token' => 'kc_live_dpop', 'token_type' => 'DPoP', 'expires_in' => 3600]);
        $t->queueJson(200, self::envelope(['ok' => true]));

        $this->makeClient($t)->request('GET', '/v1/routes'); // default mode: auto

        // First token attempt proofless, automatic retry carries a proof.
        $this->assertArrayNotHasKey('DPoP', $t->requests[0]['headers']);
        $this->assertArrayHasKey('DPoP', $t->requests[1]['headers']);
        $this->assertCount(3, explode('.', $t->requests[1]['headers']['DPoP']));
        // Management request operates as DPoP thereafter.
        $this->assertSame('DPoP kc_live_dpop', $t->requests[2]['headers']['Authorization']);
        $this->assertArrayHasKey('DPoP', $t->requests[2]['headers']);
    }

    public function testDpopAlwaysSendsProofOnTokenAndCall(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['access_token' => 'kc_live_dpop', 'token_type' => 'DPoP', 'expires_in' => 3600]);
        $t->queueJson(200, ['ok' => true]);

        $this->makeClient($t, ['dpop' => 'always'])
            ->call('r_1', ['path' => '/things', 'query' => ['a' => '1']]);

        // Token request itself carries a proof (POST, no ath — no token yet).
        $tokenParts = explode('.', $t->requests[0]['headers']['DPoP'] ?? '');
        $this->assertCount(3, $tokenParts);
        $tokenHeader = self::decodeJwtPart($tokenParts[0]);
        $this->assertSame('ES256', $tokenHeader['alg']);
        $this->assertSame('dpop+jwt', $tokenHeader['typ']);
        $tokenClaims = self::decodeJwtPart($tokenParts[1]);
        $this->assertSame('POST', $tokenClaims['htm']);
        $this->assertSame('https://api.example.test/oauth/token', $tokenClaims['htu']);
        $this->assertArrayNotHasKey('ath', $tokenClaims);

        // Data-plane request: DPoP scheme + fresh proof bound to the token,
        // htu stripped of the query string.
        $call = $t->requests[1];
        $this->assertSame('DPoP kc_live_dpop', $call['headers']['Authorization']);
        $callClaims = self::decodeJwtPart(explode('.', $call['headers']['DPoP'])[1]);
        $this->assertSame('GET', $callClaims['htm']);
        $this->assertSame('https://acme.example.test/things', $callClaims['htu']);
        $this->assertNotEmpty($callClaims['ath']);
        $this->assertNotSame($tokenClaims['jti'], $callClaims['jti'], 'every proof must carry a fresh jti');
    }

    public function testDpopProofShapeAndThumbprintStability(): void
    {
        $kp = DpopKeyPair::generate();
        $this->assertNotSame('', $kp->thumbprint());
        $this->assertSame($kp->thumbprint(), $kp->thumbprint());

        $proof = $kp->sign('get', 'https://x.example/path?q=1#frag', 'kc_live_tok');
        $parts = explode('.', $proof);
        $this->assertCount(3, $parts);
        $claims = self::decodeJwtPart($parts[1]);
        $this->assertSame('GET', $claims['htm']);
        $this->assertSame('https://x.example/path', $claims['htu']);
        $expectedAth = rtrim(strtr(base64_encode(hash('sha256', 'kc_live_tok', true)), '+/', '-_'), '=');
        $this->assertSame($expectedAth, $claims['ath']);
        $this->assertNotEmpty($claims['jti']);
    }

    public function testOidcTokenExchangeGrantShape(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_oidc');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = $this->makeClient($t, [
            'credentials' => new OIDCTokenExchange('eyJ.subject.token', 'https://token.actions.githubusercontent.com'),
        ]);
        $client->request('GET', '/v1/routes');

        parse_str($t->requests[0]['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:token-exchange', $form['grant_type']);
        $this->assertSame('eyJ.subject.token', $form['subject_token']);
        $this->assertSame('knoxcall:api', $form['audience']);
    }

    public function testApiKeyOptionUsedDirectlyAsBearer(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall([
            'tenant' => 'acme',
            'api_key' => 'kc_key_abc',
            'transport' => $t,
            'base_url' => 'https://api.example.test',
        ]);
        $client->request('GET', '/v1/routes');

        // No token-endpoint round trip — the key is the bearer credential.
        $this->assertCount(1, $t->requests);
        $this->assertSame('Bearer kc_key_abc', $t->header(0, 'Authorization'));
    }

    // -- Naming / alias compatibility ---------------------------------------------

    public function testExceptionHierarchyBackCompat(): void
    {
        $e = ApiException::fromResponse(404, ['message' => 'nope'], []);
        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertInstanceOf(ApiException::class, $e);
        $this->assertInstanceOf(KnoxCallException::class, $e);
        $this->assertSame(404, $e->statusCode);

        $this->assertInstanceOf(
            PermissionDeniedException::class,
            ApiException::fromResponse(403, ['error' => 'forbidden'], []),
        );
    }

    public function testPaymentRequired402MapsToPaymentRequiredException(): void
    {
        // A plan/billing limit (type plan_limit) is 402 — distinct from a 403
        // access denial so callers can prompt an upgrade rather than "denied".
        $e = ApiException::fromResponse(
            402,
            ['error' => ['type' => 'plan_limit', 'message' => 'Vault limit reached. Upgrade your plan.', 'request_id' => 'req-2']],
            [],
        );

        $this->assertInstanceOf(PaymentRequiredException::class, $e);
        $this->assertInstanceOf(ApiException::class, $e);
        // Not the 403 permission class — the whole point of the split.
        $this->assertNotInstanceOf(PermissionDeniedException::class, $e);
        $this->assertSame(402, $e->statusCode);
        $this->assertSame('plan_limit', $e->errorCode);
        $this->assertSame('req-2', $e->requestId);
        $this->assertStringContainsString('Upgrade your plan.', $e->getMessage());
    }

    public function testKnoxCallVersionHeaderPinnedAndConfigurable(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $this->makeClient($t)->request('GET', '/v1/routes');

        // request 0 is the token round trip; request 1 is the management call.
        $this->assertSame('2026-08-05', $t->header(1, 'KnoxCall-Version'));
        // The legacy header name is gone.
        $this->assertNull($t->header(1, 'X-KnoxCall-Api-Version'));

        // The pinned version is overridable via the api_version option.
        $t2 = new MockTransport();
        $t2->queueToken('kc_live_two');
        $t2->queueJson(200, self::envelope(['ok' => true]));
        $this->makeClient($t2, ['api_version' => '2026-01-01'])->request('GET', '/v1/routes');
        $this->assertSame('2026-01-01', $t2->header(1, 'KnoxCall-Version'));
    }

    public function testNestedV1ErrorEnvelopeParsed(): void
    {
        // The v1 API's error(res, ...) shape: {error: {type, message, request_id}}.
        $e = ApiException::fromResponse(404, [
            'error' => ['type' => 'not_found', 'message' => 'Route not found.', 'request_id' => 'req-abc'],
        ], []);

        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame('not_found', $e->errorCode);
        $this->assertSame('req-abc', $e->requestId);
        $this->assertStringContainsString('Route not found.', $e->getMessage());

        // The flat OAuth shape (error / error_description) still parses.
        $oauth = ApiException::fromResponse(400, ['error' => 'invalid_client', 'error_description' => 'bad client'], []);
        $this->assertSame('invalid_client', $oauth->errorCode);
        $this->assertStringContainsString('bad client', $oauth->getMessage());
    }

    public function testCredentialTypeDiscriminatorDefaults(): void
    {
        $this->assertSame('client_credentials', (new ClientCredentials('id', 's'))->type);
        $this->assertSame('access_token', (new AccessToken('t'))->type);
        $this->assertSame('oidc_token_exchange', (new OIDCTokenExchange('t', 'https://i'))->type);
    }

    // -- Secret hygiene -------------------------------------------------------------

    public function testSecretsNeverAppearInDebugOutput(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_minted_secret');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $credentials = new ClientCredentials('cid_1', self::SECRET);
        $client = $this->makeClient($t, ['credentials' => $credentials]);
        $client->request('GET', '/v1/routes'); // populate the token cache

        // The mock transport records raw requests (incl. Authorization) for
        // assertions — a test-only artifact; drop them so the dump checks
        // exercise the client's own state.
        $t->requests = [];

        foreach ([$credentials, $client] as $subject) {
            ob_start();
            var_dump($subject);
            $dump = ob_get_clean();
            $printed = print_r($subject, true);
            foreach ([self::SECRET, 'kc_live_minted_secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $dump);
                $this->assertStringNotContainsString($secret, $printed);
            }
        }

        $token = new AccessToken('kc_token_hush');
        $this->assertStringNotContainsString('kc_token_hush', print_r($token, true));
        $oidc = new OIDCTokenExchange('subject_hush', 'https://i');
        $this->assertStringNotContainsString('subject_hush', print_r($oidc, true));
        $this->assertStringNotContainsString('subject_hush', json_encode($oidc) ?: '');
    }

    public function testTokenEndpointFailureMessageDoesNotLeakSecret(): void
    {
        $t = new MockTransport();
        $t->queueJson(400, ['error' => 'invalid_client', 'error_description' => 'bad client']);

        try {
            $this->makeClient($t)->request('GET', '/v1/routes');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }
}
