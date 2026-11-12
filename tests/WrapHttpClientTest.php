<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\KnoxCall;
use KnoxCall\Wrap\WrapSandboxMismatchError;
use KnoxCall\Wrap\WrapTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Data-plane wrap transport tests (PHP mirror of
 * sdk/knoxcall-node/test/wrap-transport.test.ts).
 *
 * Asserts the PSR-18 httpClient re-targets to /v1/proxy in transparent mode,
 * lifts the provider credential out-of-band, preserves the request bytes,
 * enforces both-must-agree, routes escrow refs (never the raw key), sends
 * raw-card / opted-in requests directly to the provider, and honours route
 * mode + the promoted-route hint.
 *
 * Capture is at the SDK's transport boundary (the injected MockTransport for
 * the KnoxCall-bound calls; a FakeDirectClient for route-around), never by
 * mocking the wrapper — the same discipline the Node/server tests use.
 */
final class WrapHttpClientTest extends TestCase
{
    private const STRIPE_FORM = 'amount=2000&currency=usd&source=tok_visa';

    private MockTransport $t;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->t = new MockTransport();
        $this->psr17 = new Psr17Factory();
    }

    /**
     * A KnoxCall client whose data plane is a MockTransport. A pre-acquired
     * kc_ token means no token-endpoint round trip, so transport request #N is
     * proxy call #N.
     */
    private function client(bool $sandbox = false): KnoxCall
    {
        return new KnoxCall([
            'tenant' => 'acme',
            'api_key' => 'kc_live_x',
            'transport' => $this->t,
            'retry_base_delay_ms' => 0,
            'base_url' => 'https://api.test',
            'proxy_base_url' => 'https://acme.test',
            'sandbox' => $sandbox,
        ]);
    }

    /** Build the wrap httpClient with deterministic PSR-17 factories injected. */
    private function httpClient(KnoxCall $client, array $opts = []): ClientInterface
    {
        return $client->wrap->httpClient($opts + [
            'response_factory' => $this->psr17,
            'stream_factory' => $this->psr17,
        ]);
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null): RequestInterface
    {
        $req = $this->psr17->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        if ($body !== null) {
            $req = $req->withBody($this->psr17->createStream($body));
        }
        return $req;
    }

    // ── Transit mode (lift the SDK Authorization) ────────────────────────────

    public function testTransitModeRetargetsToProxyLiftsAuthorizationPreservesBody(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(sandbox: false));

        $response = $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_live_provider',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Idempotency-Key' => 'idem-1',
        ], self::STRIPE_FORM));

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertCount(1, $this->t->requests);
        $req = $this->t->lastRequest();
        // Goes to KnoxCall's proxy, not Stripe.
        $this->assertSame('https://api.test/v1/proxy', $req['url']);
        $this->assertSame('https://api.stripe.com/v1/charges', $this->t->header(0, 'x-knox-proxy-url'));
        $this->assertSame('transparent', $this->t->header(0, 'x-knox-proxy-mode'));
        // Provider credential lifted out-of-band; NOT forwarded raw.
        $this->assertSame('Bearer sk_live_provider', $this->t->header(0, 'x-knox-upstream-authorization'));
        // KnoxCall's own credential authenticates the proxy call.
        $this->assertSame('Bearer kc_live_x', $this->t->header(0, 'authorization'));
        // SDK headers preserved; body byte-identical.
        $this->assertSame('idem-1', $this->t->header(0, 'idempotency-key'));
        $this->assertSame(self::STRIPE_FORM, $req['body']);
    }

    public function testForwardsContentTypeVerbatim(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client());

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_live_x',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], self::STRIPE_FORM));

        // Transparent mode forwards the SDK's Content-Type verbatim — never
        // rewrites it to application/json.
        $this->assertSame('application/x-www-form-urlencoded', $this->t->header(0, 'content-type'));
    }

    public function testBothMustAgreeTestKeyOnLiveClientThrows(): void
    {
        $http = $this->httpClient($this->client(sandbox: false));
        $this->expectException(WrapSandboxMismatchError::class);
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_test_x',
        ]));
    }

    public function testBothMustAgreeLiveKeyOnSandboxClientThrows(): void
    {
        $http = $this->httpClient($this->client(sandbox: true));
        $this->expectException(WrapSandboxMismatchError::class);
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_live_x',
        ]));
    }

    public function testAcceptsRestrictedKeyMatchingSandbox(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(sandbox: false));

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer rk_live_restricted',
        ]));

        $this->assertSame('Bearer rk_live_restricted', $this->t->header(0, 'x-knox-upstream-authorization'));
    }

    public function testRejectsPublishableKeyOutright(): void
    {
        $http = $this->httpClient($this->client(sandbox: false));
        $this->expectException(WrapSandboxMismatchError::class);
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer pk_live_x',
        ]));
    }

    public function testBothMustAgreeNotBypassedByLeadingSpaceBeforeBearer(): void
    {
        $http = $this->httpClient($this->client(sandbox: true));
        $this->expectException(WrapSandboxMismatchError::class);
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => ' Bearer sk_live_x',
        ]));
    }

    // ── Escrow mode ──────────────────────────────────────────────────────────

    public function testEscrowModeSendsSecretRefAndNeverARawKey(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(), ['credential' => ['secret' => 'wrap-stripe-live']]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_managed_by_knoxcall',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], self::STRIPE_FORM));

        $this->assertSame('wrap-stripe-live', $this->t->header(0, 'x-knox-upstream-auth-secret'));
        // The placeholder key the SDK set is NOT forwarded out-of-band.
        $this->assertNull($this->t->header(0, 'x-knox-upstream-authorization'));
        // No both-must-agree check in escrow mode (placeholder key ignored).
        $this->assertSame(self::STRIPE_FORM, $this->t->lastRequest()['body']);
    }

    public function testEscrowModeCustomSchemePassesThrough(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(), ['credential' => ['secret' => 'wrap-x', 'scheme' => 'none']]);

        $http->sendRequest($this->request('POST', 'https://api.example.com/x'));

        $this->assertSame('none', $this->t->header(0, 'x-knox-upstream-auth-scheme'));
    }

    public function testThrowsOnMalformedEscrowCredentialRatherThanFallingThroughToTransit(): void
    {
        $client = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $this->httpClient($client, ['credential' => []]);
    }

    public function testThrowsOnEmptyEscrowSecret(): void
    {
        $client = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $this->httpClient($client, ['credential' => ['secret' => '']]);
    }

    // ── Client-side route-around ─────────────────────────────────────────────

    public function testRouteAroundSendsRawCardEndpointDirectlyToProvider(): void
    {
        $direct = new FakeDirectClient($this->psr17->createResponse(200));
        $seen = [];
        $http = $this->httpClient($this->client(), [
            'direct_client' => $direct,
            'on_route_around' => static function (array $info) use (&$seen): void {
                $seen[] = $info;
            },
        ]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/tokens', [
            'Authorization' => 'Bearer sk_live_x',
        ], 'card[number]=4242424242424242'));

        // Went direct — KnoxCall's transport was never touched.
        $this->assertCount(0, $this->t->requests);
        $this->assertCount(1, $direct->requests);
        $this->assertCount(1, $seen);
        $this->assertSame('api.stripe.com', $seen[0]['host']);
    }

    public function testDoesNotRouteAroundNormalEndpoint(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $direct = new FakeDirectClient($this->psr17->createResponse(200));
        $http = $this->httpClient($this->client(), ['direct_client' => $direct]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_live_x',
        ], self::STRIPE_FORM));

        $this->assertCount(0, $direct->requests);
        $this->assertCount(1, $this->t->requests);
        $this->assertSame('https://api.test/v1/proxy', $this->t->lastRequest()['url']);
    }

    public function testHonoursCallerSuppliedExtraRouteAroundRule(): void
    {
        $direct = new FakeDirectClient($this->psr17->createResponse(200));
        $http = $this->httpClient($this->client(), [
            'direct_client' => $direct,
            'route_around' => [['host' => 'files.stripe.com', 'reason' => 'multipart upload']],
        ]);

        $http->sendRequest($this->request('POST', 'https://files.stripe.com/v1/files', [
            'Authorization' => 'Bearer sk_live_x',
        ], 'x'));

        $this->assertCount(1, $direct->requests);
        $this->assertCount(0, $this->t->requests);
    }

    public function testTrailingDotHostStillRoutesAround(): void
    {
        $direct = new FakeDirectClient($this->psr17->createResponse(200));
        $http = $this->httpClient($this->client(), ['direct_client' => $direct]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com./v1/tokens', [
            'Authorization' => 'Bearer sk_live_x',
        ], 'card[number]=4242'));

        // Routed around despite the trailing dot.
        $this->assertCount(1, $direct->requests);
        $this->assertCount(0, $this->t->requests);
    }

    public function testThrowsOnNonBareRouteAroundHost(): void
    {
        $client = $this->client();
        $this->expectException(WrapSandboxMismatchError::class);
        $this->httpClient($client, ['route_around' => [['host' => 'https://api.stripe.com', 'reason' => 'x']]]);
    }

    // ── Route mode + promoted-route hint (PR6) ───────────────────────────────

    public function testRouteModeSendsViaDurableRouteWithNoUpstreamCredential(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(), ['route' => 'stripe-api']);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges?limit=3', [
            'Authorization' => 'Bearer sk_live_x',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], self::STRIPE_FORM));

        $req = $this->t->lastRequest();
        // Goes to the route data plane with the route header — NOT /v1/proxy.
        $this->assertSame('https://acme.test/v1/charges?limit=3', $req['url']);
        $this->assertSame('stripe-api', $this->t->header(0, 'x-knoxcall-route'));
        // The route injects the stored secret: no provider credential travels.
        $this->assertNull($this->t->header(0, 'x-knox-upstream-authorization'));
        $this->assertNull($this->t->header(0, 'x-knox-proxy-url'));
        // KnoxCall's own credential still authenticates.
        $this->assertSame('Bearer kc_live_x', $this->t->header(0, 'authorization'));
        $this->assertSame(self::STRIPE_FORM, $req['body']);
    }

    public function testFiresOnPromotedWhenResponseAdvertisesAPromotedRoute(): void
    {
        $this->t->queueJson(200, ['ok' => true], ['x-knox-promoted-route' => 'stripe-api']);
        $seen = [];
        $http = $this->httpClient($this->client(), [
            'on_promoted' => static function (array $info) use (&$seen): void {
                $seen[] = $info;
            },
        ]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', [
            'Authorization' => 'Bearer sk_live_x',
        ]));

        $this->assertSame([['host' => 'api.stripe.com', 'slug' => 'stripe-api']], $seen);
    }

    public function testDoesNotAutoSwitchByDefault(): void
    {
        $this->t->queueJson(200, ['ok' => true], ['x-knox-promoted-route' => 'stripe-api']);
        $this->t->queueJson(200, ['ok' => true], ['x-knox-promoted-route' => 'stripe-api']);
        $http = $this->httpClient($this->client());

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));

        // Both calls stayed ephemeral (/v1/proxy), no route header.
        $this->assertSame('https://api.test/v1/proxy', $this->t->requests[0]['url']);
        $this->assertSame('https://api.test/v1/proxy', $this->t->requests[1]['url']);
        $this->assertNull($this->t->header(0, 'x-knoxcall-route'));
        $this->assertNull($this->t->header(1, 'x-knoxcall-route'));
    }

    public function testAutoSwitchesSubsequentCallsToTheRoute(): void
    {
        $this->t->queueJson(200, ['ok' => true], ['x-knox-promoted-route' => 'stripe-api']);
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(), ['auto_switch' => true]);

        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));
        $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));

        // First call ephemeral (learns the hint); second call switched to the route.
        $this->assertSame('https://api.test/v1/proxy', $this->t->requests[0]['url']);
        $this->assertSame('https://acme.test/v1/charges', $this->t->requests[1]['url']);
        $this->assertSame('stripe-api', $this->t->header(1, 'x-knoxcall-route'));
        $this->assertNull($this->t->header(1, 'x-knox-upstream-authorization'));
    }

    // ── Response shape + PSR-17 discovery fallback ───────────────────────────

    public function testReturnsPsrResponseWithUpstreamStatusHeadersAndBody(): void
    {
        $this->t->queueJson(201, ['id' => 'ch_1'], ['x-foo' => 'bar']);
        $http = $this->httpClient($this->client());

        $response = $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('bar', $response->getHeaderLine('x-foo'));
        $this->assertStringContainsString('ch_1', (string) $response->getBody());
    }

    public function testMapsTransportFailureToPsr18NetworkException(): void
    {
        // requestSent:true + POST => proxySend does not retry, so a single
        // queued failure surfaces immediately.
        $this->t->queueThrow(new \KnoxCall\ConnectionException('connection reset', true));
        $http = $this->httpClient($this->client());
        $request = $this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']);

        try {
            $http->sendRequest($request);
            $this->fail('expected a PSR-18 network exception');
        } catch (\Psr\Http\Client\NetworkExceptionInterface $e) {
            // PSR-18 consumers catch NetworkExceptionInterface…
            $this->assertInstanceOf(\KnoxCall\Wrap\WrapNetworkException::class, $e);
            // …while existing ConnectionException catches keep working.
            $this->assertInstanceOf(\KnoxCall\ConnectionException::class, $e);
            $this->assertSame($request, $e->getRequest());
        }
    }

    public function testResolvesPsr17FactoriesViaNyholmFallbackWhenNotProvided(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        // No response_factory/stream_factory passed — exercises resolvePsr17()
        // discovery (nyholm/psr7 is a dev dependency).
        $http = $this->client()->wrap->httpClient();

        $response = $http->sendRequest($this->request('POST', 'https://api.stripe.com/v1/charges', ['Authorization' => 'Bearer sk_live_x']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://api.test/v1/proxy', $this->t->lastRequest()['url']);
    }

    // ── Pure-helper hardening (locked regardless of PSR-7 value handling) ─────

    public function testAssertKeyMatchesSandboxThrowsOnLeadingSpaceBeforeBearer(): void
    {
        // Node review-hardening #1: leading whitespace must not slip a live key
        // past the check. Asserted on the pure helper because a PSR-7
        // implementation may trim the header value before it reaches us.
        $this->expectException(WrapSandboxMismatchError::class);
        WrapTransport::assertKeyMatchesSandbox(' Bearer sk_live_x', true);
    }

    public function testAssertKeyMatchesSandboxLeavesNonStripeSchemesAlone(): void
    {
        // An unclassifiable scheme is not asserted on (returns without throwing).
        WrapTransport::assertKeyMatchesSandbox('Bearer some_other_provider_token', true);
        WrapTransport::assertKeyMatchesSandbox(null, false);
        $this->addToAssertionCount(1);
    }

    public function testAssertKeyMatchesSandboxAcceptsMatchingKeys(): void
    {
        WrapTransport::assertKeyMatchesSandbox('Bearer sk_test_x', true);
        WrapTransport::assertKeyMatchesSandbox('Bearer sk_live_x', false);
        WrapTransport::assertKeyMatchesSandbox('Bearer rk_test_x', true);
        $this->addToAssertionCount(1);
    }
}
