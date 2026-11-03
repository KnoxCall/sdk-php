<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use KnoxCall\ConnectionException;
use KnoxCall\KnoxCall;
use KnoxCall\Warn;
use KnoxCall\Wrap\KnoxCallHttpClient;
use KnoxCall\Wrap\WrapNetworkException;
use KnoxCall\Wrap\WrapSandboxMismatchError;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Route-aware interception at the SDK's HTTP boundary
 * (route-aware-interception-plan.md §2, PARITY §21.1) for both PHP seams: the
 * PSR-18 client (wrap->httpClient(['routes' => 'auto'])) and the Guzzle
 * middleware (wrap->guzzleMiddleware()). Capture is the injected MockTransport
 * (every KnoxCall-bound request, in order: the manifest poll, the /v1/proxy
 * request, the route data-plane request) and the FakeDirectClient / Guzzle
 * MockHandler for direct traffic — never a mocked pipeline. The decision table
 * itself is pinned by the shared fixtures (InterceptResolverTest); these tests
 * prove what LEAVES the process, with which headers, and when.
 */
final class WrapInterceptTest extends TestCase
{
    private const API = 'https://api.test';
    private const PROXY = 'https://acme.test';

    private MockTransport $t;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->t = new MockTransport();
        $this->psr17 = new Psr17Factory();
        Warn::resetForTests();
        putenv('KNOXCALL_INTERCEPT');
    }

    protected function tearDown(): void
    {
        putenv('KNOXCALL_INTERCEPT');
        Warn::resetForTests();
    }

    /**
     * A pre-acquired kc_ token means no token-endpoint round trip, so the
     * MockTransport queue is consumed in exactly the order the pipeline sends.
     *
     * @param array<string, mixed> $extra
     */
    private function client(array $extra = []): KnoxCall
    {
        return new KnoxCall($extra + [
            'tenant' => 'acme',
            'api_key' => 'kc_live_x',
            'transport' => $this->t,
            'retry_base_delay_ms' => 0,
            'base_url' => self::API,
            'proxy_base_url' => self::PROXY,
        ]);
    }

    /** @param array<string, mixed> $opts */
    private function httpClient(KnoxCall $client, array $opts = []): KnoxCallHttpClient
    {
        return $client->wrap->httpClient($opts + ['response_factory' => $this->psr17, 'stream_factory' => $this->psr17]);
    }

    /** @param array<string, string> $headers */
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

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function entry(string $host, string $base, string $slug, array $extra = []): array
    {
        return $extra + ['host' => $host, 'base_path' => $base, 'slug' => $slug, 'route_id' => "r-{$slug}",
            'requires_clients' => false, 'allowed_methods' => null, 'updated_at' => null];
    }

    /** @param list<array<string, mixed>> $routes */
    private function queueManifest(array $routes, ?string $version = null): void
    {
        $this->t->queueJson(200, ['data' => [
            'version' => $version ?? 'sha256:' . implode(',', array_column($routes, 'slug')),
            'ttl_seconds' => 60, 'environment' => 'production', 'sandbox' => false, 'routes' => $routes,
        ], 'meta' => ['request_id' => 'req_m']]);
    }

    /** @return array<string, mixed> */
    private static function hubspotCrm(): array
    {
        return self::entry('api.hubapi.com', '/crm/v3', 'hubspot-crm');
    }

    /** @return list<string> the URLs the KnoxCall transport saw, in order */
    private function urls(): array
    {
        return array_map(static fn (array $r): string => $r['url'], $this->t->requests);
    }

    private function direct(): FakeDirectClient
    {
        return new FakeDirectClient($this->psr17->createResponse(200)->withBody($this->psr17->createStream('direct')));
    }

    /** @return list<string> the E_USER_WARNING messages $fn emitted */
    private function captureWarnings(callable $fn): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$messages): bool {
            $messages[] = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $messages;
    }

    // ── the explicit transport: httpClient(['routes' => 'auto']) ─────────────

    public function testRoutesAutoSendsACoveredRequestThroughTheRoute(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueJson(200, ['routed' => true]);
        $reroutes = [];
        $http = $this->httpClient($this->client(['environment' => 'staging']), [
            'routes' => 'auto',
            'on_reroute' => static function (array $i) use (&$reroutes): void { $reroutes[] = $i; },
        ]);
        self::assertSame('sha256:hubspot-crm', $http->ready()['version']);

        $response = $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects/contacts?limit=1&after=x', [
            'Authorization' => 'Bearer pat-provider-secret',
            'Content-Type' => 'application/json',
            'X-Custom' => '1',
        ], '{"properties":{}}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            self::API . '/v1/wrap/intercept-manifest?environment=staging',
            self::PROXY . '/objects/contacts?limit=1&after=x', // rebased under /crm/v3, query kept
        ], $this->urls());
        self::assertSame('POST', $this->t->requests[1]['method']);
        self::assertSame('hubspot-crm', $this->t->header(1, 'x-knoxcall-route'));
        // The reroute marker the API Log renders as "SDK intercept" (PARITY §21.2).
        self::assertSame('sdk-intercept', $this->t->header(1, 'x-knoxcall-origin'));
        self::assertSame('staging', $this->t->header(1, 'x-knoxcall-environment'));
        self::assertSame('Bearer kc_live_x', $this->t->header(1, 'authorization'));
        self::assertNull($this->t->header(1, 'x-knox-upstream-authorization'), 'route mode never lifts the SDK key');
        self::assertNull($this->t->header(1, 'x-knox-proxy-url'));
        self::assertSame('1', $this->t->header(1, 'x-custom'));
        self::assertSame('application/json', $this->t->header(1, 'content-type'));
        self::assertSame('{"properties":{}}', $this->t->requests[1]['body']);
        self::assertCount(1, $reroutes);
        self::assertSame('route', $reroutes[0]['mode']);
        self::assertSame('hubspot-crm', $reroutes[0]['slug']);
        self::assertSame('manifest', $reroutes[0]['reason']);
    }

    public function testAHostWithNoRouteGoesEphemeralAndAnUnmatchedPathFiresTheHookOnce(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        foreach (range(1, 4) as $_) {
            $this->t->queueJson(200, ['ok' => true]);
        }
        $unmatched = [];
        $reroutes = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_unmatched_path' => static function (array $i) use (&$unmatched): void { $unmatched[] = $i; },
            'on_reroute' => static function (array $i) use (&$reroutes): void { $reroutes[] = $i; },
        ]);

        $http->sendRequest($this->request('GET', 'https://api.resend.com/emails', ['Authorization' => 'Bearer re_secret']));
        self::assertSame(self::API . '/v1/proxy', $this->t->requests[1]['url']);
        self::assertSame('https://api.resend.com/emails', $this->t->header(1, 'x-knox-proxy-url'));
        self::assertSame('transparent', $this->t->header(1, 'x-knox-proxy-mode'));
        self::assertSame('Bearer re_secret', $this->t->header(1, 'x-knox-upstream-authorization'));
        // The reroute marker is a ROUTE-mode fact (PARITY §21.2); an ephemeral
        // hop is a different log and carries nothing.
        self::assertNull($this->t->header(1, 'x-knoxcall-origin'));
        self::assertSame('no_route', $reroutes[0]['reason']);

        foreach (['/oauth/v1/token', '/oauth/v1/token', '/oauth/v2/x'] as $path) {
            $http->sendRequest($this->request('POST', 'https://api.hubapi.com' . $path, [], 'grant_type=refresh_token'));
        }
        self::assertCount(5, $this->t->requests); // manifest + 4 ephemeral
        self::assertSame(self::API . '/v1/proxy', $this->t->requests[4]['url']);
        self::assertSame([['host' => 'api.hubapi.com', 'url' => 'https://api.hubapi.com/oauth/v1/token']], $unmatched);
        self::assertSame('no_base_path_match', $reroutes[count($reroutes) - 1]['reason']);
    }

    public function testRoutesOffNeverPollsTheManifest(): void
    {
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client());
        self::assertNull($http->manifest());
        self::assertNull($http->refresh());
        $http->sendRequest($this->request('GET', 'https://api.hubapi.com/crm/v3/objects', ['Authorization' => 'Bearer pat']));
        self::assertSame([self::API . '/v1/proxy'], $this->urls());
    }

    public function testOwnAndPlatformHostsGoDirectEvenAsTheExplicitTransport(): void
    {
        $this->queueManifest([]);
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), ['routes' => 'auto', 'direct_client' => $direct]);
        $http->sendRequest($this->request('GET', self::API . '/v1/anything', ['Authorization' => 'Bearer x']));
        $http->sendRequest($this->request('GET', 'https://acme.knoxcall.com/x'));
        $http->sendRequest($this->request('GET', 'https://KNOXCALL.COM./y'));
        self::assertCount(3, $direct->requests);
        self::assertSame([self::API . '/v1/wrap/intercept-manifest'], $this->urls(), 'only the poll reached KnoxCall');
    }

    public function testKillSwitchSendsEverythingDirectPerRequest(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueJson(200, ['routed' => true]);
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), ['routes' => 'auto', 'direct_client' => $direct]);
        $http->ready();

        putenv('KNOXCALL_INTERCEPT=off');
        $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects', ['Authorization' => 'Bearer sk_provider'], 'x'));
        self::assertCount(1, $direct->requests);
        self::assertSame('Bearer sk_provider', $direct->requests[0]->getHeaderLine('Authorization'), 'the original request, untouched');
        self::assertCount(1, $this->t->requests, 'KnoxCall not contacted');

        putenv('KNOXCALL_INTERCEPT');
        $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects', [], 'x'));
        self::assertSame(self::PROXY . '/objects', $this->t->requests[1]['url']);
    }

    public function testAKnoxCallOriginRefusalRefreshesOnceAndResendsWhenTheDecisionChanged(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueRaw(401, '{"error":"Unauthorized"}'); // the refusal…
        $this->t->queueRaw(401, '{"error":"Unauthorized"}'); // …and call()'s own one re-mint
        $this->queueManifest([], 'sha256:none');              // the refusal refresh: the route is gone
        $this->t->queueJson(200, ['ok' => true]);              // the resend, ephemeral
        $refused = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_refused' => static function (array $i) use (&$refused): void { $refused[] = $i; },
        ]);

        $response = $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects', ['Authorization' => 'Bearer pat'], '{"a":1}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            self::API . '/v1/wrap/intercept-manifest',
            self::PROXY . '/objects',
            self::PROXY . '/objects',
            self::API . '/v1/wrap/intercept-manifest',
            self::API . '/v1/proxy',
        ], $this->urls(), 'refusal + re-mint, ONE refresh, one resend — never a third route call');
        self::assertSame('{"a":1}', $this->t->requests[4]['body']);
        self::assertSame('Bearer pat', $this->t->header(4, 'x-knox-upstream-authorization'));
        self::assertSame([['host' => 'api.hubapi.com', 'url' => 'https://api.hubapi.com/crm/v3/objects', 'slug' => 'hubspot-crm',
            'status' => 401, 'redecided' => 'ephemeral']], $refused);
    }

    public function testARefusalThatSurvivesTheRefreshIsReturnedAsIs(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueRaw(401, '{"error":"Unauthorized"}');
        $this->t->queueRaw(401, '{"error":"Unauthorized"}');
        $this->queueManifest([self::hubspotCrm()]); // unchanged
        $refused = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_refused' => static function (array $i) use (&$refused): void { $refused[] = $i; },
        ]);
        $response = $http->sendRequest($this->request('GET', 'https://api.hubapi.com/crm/v3/objects'));
        self::assertSame(401, $response->getStatusCode());
        self::assertCount(4, $this->t->requests, 'no replay after an unchanged refresh');
        self::assertNull($refused[0]['redecided']);
    }

    public function testAnUpstream401RelayedByTheDataPlaneNeverRemintsOrRefreshes(): void
    {
        foreach (['x-knox-upstream-status', 'x-knox-destination-status'] as $header) {
            $this->t = new MockTransport();
            $this->queueManifest([self::hubspotCrm()]);
            $this->t->queueRaw(401, '{}', [$header => '401']);
            $http = $this->httpClient($this->client(), ['routes' => 'auto']);
            $response = $http->sendRequest($this->request('GET', 'https://api.hubapi.com/crm/v3/objects'));
            self::assertSame(401, $response->getStatusCode(), $header);
            self::assertCount(2, $this->t->requests, "{$header}: the upstream's 401 is the caller's — no re-mint, no refresh");
        }
    }

    private const ROUTE_NOT_FOUND = '{"error":{"type":"route_not_found","message":"Route \'hubspot-crm\' not found.","request_id":"req_x"}}';
    private const KNOX_BLOCK = ['x-knox-origin' => 'knoxcall', 'x-knox-error' => 'route_not_found', 'x-knox-plane' => 'route'];

    /**
     * Founder decision 2026-09-26: an AUTHENTICATED key gets a real 404 for a
     * route that does not resolve. A stale manifest naming a Route deleted since
     * the poll is exactly that, so the 404 route_not_found envelope is a refresh
     * trigger too (PARITY §21.1). No re-mint is spent on it — call()'s rule is
     * 401-only — so the Route is called ONCE.
     */
    public function testAKnoxCallOrigin404RouteNotFoundRefreshesOnceAndResendsWhenTheDecisionChanged(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueRaw(404, self::ROUTE_NOT_FOUND, self::KNOX_BLOCK); // the refusal — no re-mint on a 404
        $this->queueManifest([], 'sha256:none');                         // the refusal refresh: the route is gone
        $this->t->queueJson(200, ['ok' => true]);                         // the resend, ephemeral
        $refused = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_refused' => static function (array $i) use (&$refused): void { $refused[] = $i; },
        ]);

        $response = $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects', ['Authorization' => 'Bearer pat'], '{"a":1}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            self::API . '/v1/wrap/intercept-manifest',
            self::PROXY . '/objects',
            self::API . '/v1/wrap/intercept-manifest',
            self::API . '/v1/proxy',
        ], $this->urls(), 'one route call (no re-mint on a 404), ONE refresh, one resend');
        self::assertSame('{"a":1}', $this->t->requests[3]['body']);
        self::assertSame([['host' => 'api.hubapi.com', 'url' => 'https://api.hubapi.com/crm/v3/objects', 'slug' => 'hubspot-crm',
            'status' => 404, 'redecided' => 'ephemeral']], $refused);
    }

    public function testA404OfAnEnvironmentTypeIsRefusedAsIs(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $body = '{"error":{"type":"environment_not_configured","message":"Environment \'staging\' is not configured for this route.","request_id":"r"}}';
        $this->t->queueRaw(404, $body, ['x-knox-origin' => 'knoxcall', 'x-knox-error' => 'environment_not_configured']);
        $refused = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_refused' => static function (array $i) use (&$refused): void { $refused[] = $i; },
        ]);
        $response = $http->sendRequest($this->request('GET', 'https://api.hubapi.com/crm/v3/objects'));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame($body, (string) $response->getBody());
        self::assertCount(2, $this->t->requests, 'one route call, no refresh');
        self::assertSame([], $refused);
    }

    public function testAnUpstream404RelayedByTheDataPlaneIsNotARefusalEvenWithAnImitatedEnvelope(): void
    {
        foreach (['x-knox-upstream-status', 'x-knox-destination-status'] as $header) {
            $this->t = new MockTransport();
            $this->queueManifest([self::hubspotCrm()]);
            $this->t->queueRaw(404, self::ROUTE_NOT_FOUND, [$header => '404']);
            $http = $this->httpClient($this->client(), ['routes' => 'auto']);
            $response = $http->sendRequest($this->request('GET', 'https://api.hubapi.com/crm/v3/objects'));
            self::assertSame(404, $response->getStatusCode(), $header);
            self::assertSame(self::ROUTE_NOT_FOUND, (string) $response->getBody(), "{$header}: the upstream's body, intact");
            self::assertCount(2, $this->t->requests, "{$header}: the upstream's 404 is the caller's — no refresh");
        }
    }

    public function testPromotedRouteHintFiresTheHookAndRefreshesTheManifest(): void
    {
        $this->queueManifest([]);
        $this->t->queueJson(200, ['ok' => true], ['x-knox-promoted-route' => 'resend']);
        $this->queueManifest([self::entry('api.resend.com', '/', 'resend')]);
        $this->t->queueJson(200, ['routed' => true]);
        $promoted = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'on_promoted' => static function (array $i) use (&$promoted): void { $promoted[] = $i; },
        ]);
        $http->ready();
        $http->pipeline()->store()?->setMinRefreshGap(0.0); // the hint would otherwise sit inside the start refresh's gap

        $http->sendRequest($this->request('GET', 'https://api.resend.com/emails'));
        self::assertSame([['host' => 'api.resend.com', 'slug' => 'resend']], $promoted);
        $http->sendRequest($this->request('GET', 'https://api.resend.com/emails')); // the NEXT request pays the refresh…
        self::assertSame([
            self::API . '/v1/wrap/intercept-manifest',
            self::API . '/v1/proxy',
            self::API . '/v1/wrap/intercept-manifest',
            self::PROXY . '/emails', // …and goes through the Route it learned about
        ], $this->urls());
    }

    // ── D4: KnoxCall unreachable ─────────────────────────────────────────────

    /** requestSent:true + POST => proxySend does not retry, so one queued failure surfaces. */
    private function queueUnreachable(): void
    {
        $this->t->queueThrow(new ConnectionException('connection refused', true));
    }

    public function testTransitFailsClosedByDefault(): void
    {
        $this->queueManifest([]);
        $this->queueUnreachable();
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), ['routes' => 'auto', 'direct_client' => $direct]);
        $this->expectException(WrapNetworkException::class);
        try {
            $http->sendRequest($this->request('POST', 'https://api.resend.com/emails', ['Authorization' => 'Bearer re_secret'], 'x=1'));
        } finally {
            self::assertCount(0, $direct->requests, 'never "try KnoxCall then send the key direct" by default');
        }
    }

    public function testTransitMayOptIntoDirectAndTheOriginalRequestGoesUntouched(): void
    {
        $this->queueManifest([]);
        $this->queueUnreachable();
        $direct = $this->direct();
        $fallbacks = [];
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto', 'direct_client' => $direct, 'unavailable' => 'direct',
            'on_fallback' => static function (array $i) use (&$fallbacks): void { $fallbacks[] = $i; },
        ]);
        $response = $http->sendRequest($this->request('POST', 'https://api.resend.com/emails', ['Authorization' => 'Bearer re_secret'], 'x=1'));
        self::assertSame('direct', (string) $response->getBody());
        self::assertCount(1, $direct->requests);
        self::assertSame('api.resend.com', $direct->requests[0]->getUri()->getHost());
        self::assertSame('Bearer re_secret', $direct->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('x=1', (string) $direct->requests[0]->getBody());
        self::assertCount(1, $fallbacks);
        self::assertSame('api.resend.com', $fallbacks[0]['host']);
        self::assertInstanceOf(ConnectionException::class, $fallbacks[0]['error']);
    }

    public function testPerHostOptInCoversOnlyThatHost(): void
    {
        $this->queueManifest([]);
        $this->queueUnreachable();
        $this->queueUnreachable();
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto', 'direct_client' => $direct,
            'hosts' => ['api.resend.com' => ['unavailable' => 'direct']],
        ]);
        $http->sendRequest($this->request('POST', 'https://api.resend.com/emails', [], 'x'));
        self::assertCount(1, $direct->requests);
        $this->expectException(WrapNetworkException::class);
        $http->sendRequest($this->request('POST', 'https://api.other.example/x', [], 'x'));
    }

    public function testEscrowNeverGoesDirectEvenWithBothOptIns(): void
    {
        $this->queueManifest([]);
        $this->queueUnreachable();
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto', 'direct_client' => $direct, 'unavailable' => 'direct',
            'hosts' => ['api.resend.com' => ['credential' => ['secret' => 'resend-key'], 'unavailable' => 'direct']],
        ]);
        $this->expectException(WrapNetworkException::class);
        try {
            $http->sendRequest($this->request('POST', 'https://api.resend.com/emails', [], 'x'));
        } finally {
            self::assertCount(0, $direct->requests, 'there is no credential to go direct with');
        }
    }

    public function testRouteModeNeverGoesDirect(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->queueUnreachable();
        $direct = $this->direct();
        $http = $this->httpClient($this->client(), ['routes' => 'auto', 'direct_client' => $direct, 'unavailable' => 'direct']);
        $this->expectException(WrapNetworkException::class);
        try {
            $http->sendRequest($this->request('POST', 'https://api.hubapi.com/crm/v3/objects', [], 'x'));
        } finally {
            self::assertCount(0, $direct->requests);
        }
    }

    // ── warnings, per-host escrow, misconfiguration ──────────────────────────

    public function testWarnsOnceForRequiresClientsAndAmbiguousEntries(): void
    {
        $this->queueManifest([
            self::entry('api.openai.com', '/v1', 'openai', ['requires_clients' => true, 'allowed_methods' => ['GET', 'POST']]),
            self::entry('dup.example', '/', 'a-first', ['ambiguous' => true]),
            self::entry('dup.example', '/', 'b-second', ['ambiguous' => true]),
        ], 'sha256:warn');
        $this->queueManifest([
            self::entry('api.openai.com', '/v1', 'openai', ['requires_clients' => true, 'allowed_methods' => ['GET', 'POST']]),
            self::entry('dup.example', '/', 'a-first', ['ambiguous' => true]),
            self::entry('dup.example', '/', 'b-second', ['ambiguous' => true]),
        ], 'sha256:warn');
        $http = $this->httpClient($this->client(), ['routes' => 'auto']);
        $warnings = $this->captureWarnings(static function () use ($http): void {
            $http->ready();
            $http->refresh();
        });
        $requires = array_filter($warnings, static fn (string $m): bool => str_contains($m, 'route "openai"') && str_contains($m, 'requires a registered client'));
        $ambiguous = array_filter($warnings, static fn (string $m): bool => str_contains($m, 'more than one intercept-enabled route covers dup.example/'));
        self::assertCount(1, $requires);
        self::assertCount(1, $ambiguous);
    }

    public function testPerHostEscrowUsesTheNamedCredentialForThatHostOnly(): void
    {
        $this->queueManifest([]);
        $this->t->queueJson(200, ['ok' => true]);
        $this->t->queueJson(200, ['ok' => true]);
        $http = $this->httpClient($this->client(), [
            'routes' => 'auto',
            'hosts' => ['api.resend.com' => ['credential' => ['secret' => 'resend-key', 'scheme' => 'none']]],
        ]);
        $http->sendRequest($this->request('POST', 'https://api.resend.com/emails', ['Authorization' => 'Bearer placeholder'], '{}'));
        $http->sendRequest($this->request('GET', 'https://api.other.example/x', ['Authorization' => 'Bearer other_secret']));
        self::assertSame('resend-key', $this->t->header(1, 'x-knox-upstream-auth-secret'));
        self::assertSame('none', $this->t->header(1, 'x-knox-upstream-auth-scheme'));
        self::assertNull($this->t->header(1, 'x-knox-upstream-authorization'));
        self::assertSame('Bearer other_secret', $this->t->header(2, 'x-knox-upstream-authorization'));
        self::assertNull($this->t->header(2, 'x-knox-upstream-auth-secret'));
    }

    // ── the conditional poll on the wire (PARITY §21.1 "Conditional poll") ──────
    // The store-level walk of sdk/fixtures/intercept-store-conditional.json lives
    // in InterceptManifestStoreTest; here the same contract is proven at the SDK's
    // HTTP boundary — which header leaves, in which form, and what a 304 becomes —
    // through the real interceptManifest() and the real request pipeline.

    public function testWireFormOfEveryFixtureStepIsTheServersWeakEtag(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/intercept-store-conditional.json');
        self::assertIsString($raw);
        $fixture = json_decode($raw, true);
        self::assertIsArray($fixture);
        foreach ($fixture['steps'] as $step) {
            $held = $step['expect']['fetch_if_none_match'];
            if ($held === null) {
                self::assertNull($step['expect']['wire_if_none_match'], $step['name']);
                continue;
            }
            self::assertSame($step['expect']['wire_if_none_match'], \KnoxCall\Resources\WrapResource::manifestEtag($held), $step['name']);
        }
    }

    public function testInterceptManifestSendsIfNoneMatchAsTheWeakEtagAndReturnsNullOn304(): void
    {
        $client = $this->client();
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueRaw(304, '', ['etag' => 'W/"sha256:hubspot-crm"']);
        $this->queueManifest([self::hubspotCrm()]); // a stale tag: the server answers 200
        $this->queueManifest([self::hubspotCrm()]); // an empty if_none_match is the unconditional call

        $first = $client->wrap->interceptManifest();
        self::assertSame(['hubspot-crm'], array_column($first['routes'] ?? [], 'slug'));
        self::assertNull($this->t->header(0, 'If-None-Match'));

        self::assertNull($client->wrap->interceptManifest(['if_none_match' => $first['version']]));
        self::assertSame('W/"sha256:hubspot-crm"', $this->t->header(1, 'If-None-Match'));

        $stale = $client->wrap->interceptManifest(['if_none_match' => 'sha256:stale']);
        self::assertSame('sha256:hubspot-crm', $stale['version'] ?? null);
        self::assertSame('W/"sha256:stale"', $this->t->header(2, 'If-None-Match'));

        $explicit = $client->wrap->interceptManifest(['if_none_match' => '']);
        self::assertSame('sha256:hubspot-crm', $explicit['version'] ?? null);
        self::assertNull($this->t->header(3, 'If-None-Match'));
    }

    public function testA401OnTheConditionalPollStillGetsTheOneReauthAndTheRetryCarriesTheHeader(): void
    {
        $client = $this->client();
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueJson(401, ['error' => ['type' => 'authentication_error', 'message' => 'expired', 'request_id' => 'r']]);
        $this->t->queueRaw(304, '', ['etag' => 'W/"sha256:hubspot-crm"']);

        $first = $client->wrap->interceptManifest();
        self::assertNull($client->wrap->interceptManifest(['if_none_match' => $first['version']]));
        self::assertCount(3, $this->t->requests); // unconditional, the 401, the re-authed retry
        self::assertSame('W/"sha256:hubspot-crm"', $this->t->header(1, 'If-None-Match'));
        self::assertSame('W/"sha256:hubspot-crm"', $this->t->header(2, 'If-None-Match'));
    }

    public function testWithoutTheOptInA304KeepsItsOldShape(): void
    {
        $client = $this->client();
        $this->t->queueRaw(304, '', ['etag' => 'W/"x"']);
        $this->t->queueRaw(304, '', ['etag' => 'W/"x"']);
        self::assertNull($client->request('GET', '/v1/wrap/intercept-manifest'));
        self::assertInstanceOf(\KnoxCall\NotModified::class, $client->request('GET', '/v1/wrap/intercept-manifest', null, null, [], true));
    }

    public function testTheRouteAwareClientPollsConditionallyA304KeepsTheManifestAndAChangeReplacesIt(): void
    {
        $client = $this->client();
        $this->queueManifest([self::hubspotCrm()]);                            // ready: unconditional
        $this->t->queueRaw(304, '', ['etag' => 'W/"sha256:hubspot-crm"']);     // forced refresh: unchanged
        $this->queueManifest([self::entry('api.hubapi.com', '/', 'hubspot')]); // forced refresh: a change
        $this->t->queueRaw(304, '', ['etag' => 'W/"sha256:hubspot"']);         // forced refresh: unchanged again
        $refreshes = [];
        $hc = $this->httpClient($client, ['routes' => 'auto', 'on_refresh' => function (array $info) use (&$refreshes): void {
            $refreshes[] = $info;
        }]);

        $hc->ready();
        self::assertSame('sha256:hubspot-crm', $hc->manifest()['version'] ?? null);
        self::assertNull($this->t->header(0, 'If-None-Match'));
        self::assertCount(1, $refreshes);

        $hc->refresh(); // forced, as a refusal-driven refresh is: still conditional
        self::assertSame('W/"sha256:hubspot-crm"', $this->t->header(1, 'If-None-Match'));
        self::assertSame(['hubspot-crm'], array_column($hc->manifest()['routes'] ?? [], 'slug'));
        self::assertCount(1, $refreshes); // a 304 changed nothing, so no hook

        $hc->refresh();
        self::assertSame('W/"sha256:hubspot-crm"', $this->t->header(2, 'If-None-Match'));
        self::assertSame('sha256:hubspot', $hc->manifest()['version'] ?? null);
        self::assertCount(2, $refreshes);
        self::assertSame(['hubspot'], array_column($refreshes[1]['added'], 'slug'));
        self::assertSame(['hubspot-crm'], array_column($refreshes[1]['removed'], 'slug'));

        $hc->refresh();
        self::assertSame('W/"sha256:hubspot"', $this->t->header(3, 'If-None-Match'));
        self::assertSame('sha256:hubspot', $hc->manifest()['version'] ?? null);
        self::assertCount(2, $refreshes);
        self::assertCount(4, $this->t->requests);
    }

    public function testMisconfigurationFailsLoudAtConstruction(): void
    {
        $client = $this->client();
        try {
            $this->httpClient($client, ['hosts' => ['https://x.example/path']]);
            self::fail('a non-bare host must be refused');
        } catch (WrapSandboxMismatchError $e) {
            self::assertStringContainsString('bare DNS hostname', $e->getMessage());
        }
        try {
            $this->httpClient($client, ['hosts' => ['h.example' => ['credential' => ['secret' => '']]]]);
            self::fail('an empty per-host escrow secret must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('non-empty', $e->getMessage());
        }
        try {
            $this->httpClient($client, ['on_nonsense' => static fn () => null]);
            self::fail('a typo\'d hook must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('on_nonsense', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->httpClient($client, ['routes' => 'sometimes']);
    }

    // ── the Guzzle middleware ────────────────────────────────────────────────

    public function testGuzzleMiddlewareAnswersCoveredAndListedHostsAndPassesTheRestDown(): void
    {
        $this->queueManifest([self::hubspotCrm()]);
        $this->t->queueJson(201, ['routed' => true], ['x-foo' => 'bar']);
        $this->t->queueJson(200, ['ok' => true]);
        $mock = new MockHandler([new GuzzleResponse(200, [], 'direct')]);
        $stack = HandlerStack::create($mock);
        $middleware = $this->client()->wrap->guzzleMiddleware([
            'hosts' => ['api.resend.com'],
            'response_factory' => $this->psr17,
            'stream_factory' => $this->psr17,
        ]);
        $stack->push($middleware, 'knoxcall');
        $guzzle = new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]);

        $routed = $guzzle->get('https://api.hubapi.com/crm/v3/objects');
        self::assertSame(201, $routed->getStatusCode());
        self::assertSame('{"routed":true}', (string) $routed->getBody());
        self::assertSame('bar', $routed->getHeaderLine('x-foo'));
        $guzzle->get('https://api.resend.com/emails');
        $direct = $guzzle->get('https://unlisted.example/x');
        self::assertSame('direct', (string) $direct->getBody());

        self::assertSame([
            self::API . '/v1/wrap/intercept-manifest',
            self::PROXY . '/objects',
            self::API . '/v1/proxy',
        ], $this->urls());
        self::assertSame('hubspot-crm', $this->t->header(1, 'x-knoxcall-route'));
        self::assertSame(0, $mock->count(), 'the unlisted host consumed the stack\'s own handler');
        self::assertSame('sha256:hubspot-crm', $middleware->pipeline()->manifest()['version']);
    }

    public function testGuzzleMiddlewareRejectsWithGuzzlesConnectExceptionWhenKnoxCallIsUnreachable(): void
    {
        $this->queueManifest([]);
        $this->queueUnreachable();
        $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], 'direct')]));
        $stack->push($this->client()->wrap->guzzleMiddleware([
            'hosts' => ['api.resend.com'], 'response_factory' => $this->psr17, 'stream_factory' => $this->psr17,
        ]), 'knoxcall');
        $guzzle = new \GuzzleHttp\Client(['handler' => $stack]);
        $this->expectException(\GuzzleHttp\Exception\ConnectException::class);
        $guzzle->post('https://api.resend.com/emails', ['body' => 'x']);
    }
}
