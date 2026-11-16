<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use KnoxCall\ConnectionException;
use KnoxCall\KnoxCall;
use KnoxCall\PermissionDeniedException;
use KnoxCall\Warn;
use KnoxCall\Wrap\EgressObservationReporter;
use KnoxCall\Wrap\EgressObservations;
use KnoxCall\Wrap\WrapTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Uncovered-egress observations (PARITY §21.3) — the PHP mirror of
 * sdk/knoxcall-node/test/egress-observations.test.ts. Three layers: the
 * classifier, driven by the CROSS-LANGUAGE fixture
 * sdk/fixtures/egress-observation.json; the reporter against an injected
 * report closure; and the seams (the Guzzle middleware, the PSR-18 client)
 * with the MockTransport recording what LEAVES the process — so the report
 * body is asserted to carry the credential header's NAME and never its
 * VALUE, never the query.
 *
 * Every client here is built on https:// hosts, and every warning the SDK
 * emits (E_USER_WARNING) is captured by a scoped handler — phpunit.xml.dist
 * runs with failOnWarning.
 */
final class EgressObservationsTest extends TestCase
{
    private const API = 'https://api.test';
    private const PROXY = 'https://acme.test';
    private const OBS = self::API . '/v1/wrap/egress-observations';

    private MockTransport $t;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->t = new MockTransport();
        $this->psr17 = new Psr17Factory();
        Warn::resetForTests();
        putenv('KNOXCALL_INTERCEPT');
        putenv('KNOXCALL_OBSERVE_UNCOVERED');
    }

    protected function tearDown(): void
    {
        putenv('KNOXCALL_INTERCEPT');
        putenv('KNOXCALL_OBSERVE_UNCOVERED');
        Warn::resetForTests();
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/egress-observation.json'), true);
    }

    /** @return list<string> */
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

    private function client(): KnoxCall
    {
        return new KnoxCall([
            'tenant' => 'acme', 'api_key' => 'kc_live_x', 'transport' => $this->t, 'retry_base_delay_ms' => 0,
            'retry_max_attempts' => 1, 'base_url' => self::API, 'proxy_base_url' => self::PROXY,
        ]);
    }

    private function queueManifest(): void
    {
        $this->t->queueJson(200, ['data' => ['version' => 'sha256:empty', 'ttl_seconds' => 60,
            'environment' => 'production', 'sandbox' => false, 'routes' => []], 'meta' => ['request_id' => 'm']]);
    }

    private function queueAccepted(int $n): void
    {
        $this->t->queueJson(202, ['data' => ['accepted' => $n, 'dropped' => 0, 'reasons' => []], 'meta' => ['request_id' => 'o']]);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}> */
    private function reports(): array
    {
        return array_values(array_filter($this->t->requests, static fn (array $r): bool => $r['url'] === self::OBS));
    }

    // ── 1. the classifier: shared fixtures ───────────────────────────────────

    public function testTheSharedFixtureTable(): void
    {
        $f = self::fixture();
        self::assertGreaterThan(20, count($f['header_cases']));
        self::assertGreaterThan(3, count($f['cases']));
        self::assertSame($f['credential_headers']['allowlist'], EgressObservations::CREDENTIAL_HEADER_ALLOWLIST);
        self::assertSame($f['credential_headers']['suffixes'], EgressObservations::CREDENTIAL_HEADER_SUFFIXES);
        foreach ($f['header_cases'] as $c) {
            self::assertSame($c['counts'], EgressObservations::isCredentialHeaderName($c['name']), "header {$c['name']}");
        }
        foreach ($f['pick_cases'] as $c) {
            $headers = array_fill_keys($c['headers'], 'value');
            self::assertSame($c['expect'], EgressObservations::credentialHeaderName($headers), implode(',', $c['headers']));
        }
        foreach ($f['first_segment_cases'] as $c) {
            self::assertSame($c['expect'], EgressObservations::firstSegment($c['url']), $c['url']);
        }
        foreach ($f['segment_redaction_cases'] as $c) {
            self::assertSame($c['redacted'], EgressObservations::firstSegmentLooksLikeCredential($c['segment']), $c['segment']);
        }
        foreach ($f['host_cases'] as $c) {
            self::assertSame($c['expect'], WrapTransport::normalizeHost((string) parse_url($c['url'], PHP_URL_HOST)), $c['url']);
        }
        foreach ($f['cases'] as $c) {
            $got = EgressObservations::observationFor($c['url'], $c['method'], $c['headers']);
            self::assertSame($c['expect'], $got, $c['name']);
            foreach ($c['headers'] as $v) {
                if (trim($v) !== '') {
                    self::assertStringNotContainsString($v, (string) json_encode($got), 'names, never values');
                }
            }
        }
    }

    // ── 2. the reporter ──────────────────────────────────────────────────────

    /** @return array{host: string, first_segment: string, method: string, header_name: string} */
    private static function obs(int $i, string $method = 'GET'): array
    {
        return ['host' => "h{$i}.example", 'first_segment' => '/v1', 'method' => $method, 'header_name' => 'authorization'];
    }

    public function testTheReporterAggregatesFlushesAt200ChunksAndStops(): void
    {
        $t = 1700000000.0;
        $calls = [];
        $r = new EgressObservationReporter(
            static function (array $o) use (&$calls): array {
                $calls[] = $o;
                return ['accepted' => count($o), 'dropped' => 0, 'reasons' => []];
            },
            now: static function () use (&$t): float {
                return $t;
            },
            rand: static fn (): float => 0.5,
            flushOnShutdown: false,
        );
        $r->record(self::obs(1));
        $t += 1;
        $r->record(self::obs(1));
        $r->record(self::obs(1, 'POST'));
        self::assertSame(2, $r->size());
        $r->flush();
        self::assertSame(['host' => 'h1.example', 'first_segment' => '/v1', 'method' => 'GET', 'header_name' => 'authorization',
            'count' => 2, 'first_seen' => '2023-11-14T22:13:20.000Z', 'last_seen' => '2023-11-14T22:13:21.000Z'], $calls[0][0]);

        for ($i = 0; $i < 199; $i++) {
            $r->record(self::obs($i));
        }
        self::assertCount(1, $calls);
        $r->record(self::obs(199));
        self::assertCount(2, $calls, 'the 200th distinct key flushes at once');
        self::assertCount(200, $calls[1]);

        // The lazy timer: the first record after ~60 s flushes.
        $r->record(self::obs(1));
        $t += 67;
        $r->record(self::obs(2));
        self::assertCount(3, $calls);

        $r->record(self::obs(3));
        $r->stop();
        self::assertCount(4, $calls);
        $r->record(self::obs(4));
        self::assertSame(0, $r->size());
    }

    public function testTheReporterCapsAt1000AndA403StopsReportingWithOneWarning(): void
    {
        $messages = $this->captureWarnings(function (): void {
            $r = new EgressObservationReporter(static fn (array $o): array => [], flushAtKeys: 10000, flushOnShutdown: false);
            for ($i = 0; $i < 1005; $i++) {
                $r->record(self::obs($i));
            }
            self::assertSame(1000, $r->size());

            $calls = 0;
            $denied = new EgressObservationReporter(static function () use (&$calls): array {
                $calls++;
                throw new PermissionDeniedException('insufficient scope', 403);
            }, flushOnShutdown: false);
            $denied->record(self::obs(1));
            $denied->flush();
            self::assertTrue($denied->forbidden());
            $denied->record(self::obs(2));
            self::assertSame(0, $denied->size());
            $denied->stop();
            self::assertSame(1, $calls);
        });
        self::assertCount(2, $messages);
        self::assertStringContainsString('1000', $messages[0]);
        self::assertStringContainsString('routes:read', $messages[1]);
    }

    public function testAnyOtherFailureDropsTheBatchWithOneWarningAndKeepsGoing(): void
    {
        $fail = true;
        $calls = [];
        $messages = $this->captureWarnings(function () use (&$fail, &$calls): void {
            $r = new EgressObservationReporter(static function (array $o) use (&$fail, &$calls): array {
                if ($fail) {
                    throw new ConnectionException('connection refused', false);
                }
                $calls[] = $o;
                return ['accepted' => count($o), 'dropped' => 0];
            }, flushOnShutdown: false);
            $r->record(self::obs(1));
            $r->flush();
            self::assertSame(0, $r->size());
            $fail = false;
            $r->record(self::obs(2));
            $r->flush();
            $fail = true;
            $r->record(self::obs(3));
            $r->flush();
        });
        self::assertCount(1, $calls);
        self::assertSame('h2.example', $calls[0][0]['host']);
        self::assertCount(1, $messages);
    }

    // ── 3. the seams ─────────────────────────────────────────────────────────

    public function testTheGuzzleMiddlewareRecordsAnUnlistedCredentialedCallNamesNeverValues(): void
    {
        $this->queueManifest();
        $this->queueAccepted(1);
        $flushes = [];
        $mock = new MockHandler([new GuzzleResponse(200, [], 'direct'), new GuzzleResponse(200, [], 'direct'),
            new GuzzleResponse(200, [], 'direct')]);
        $stack = HandlerStack::create($mock);
        $middleware = $this->client()->wrap->guzzleMiddleware([
            'hosts' => ['api.resend.com'], 'response_factory' => $this->psr17, 'stream_factory' => $this->psr17,
            'on_observation_flush' => static function (array $i) use (&$flushes): void {
                $flushes[] = $i;
            },
        ]);
        $stack->push($middleware, 'knoxcall');
        $guzzle = new \GuzzleHttp\Client(['handler' => $stack]);

        $res = $guzzle->post('https://a.klaviyo.com/api/profiles/?x=1&token=leak-in-query', [
            'headers' => ['Authorization' => 'Klaviyo-API-Key pk_live_should_never_appear'],
            'body' => '{"email":"never-appears@example.com"}',
        ]);
        self::assertSame('direct', (string) $res->getBody());
        $guzzle->post('https://a.klaviyo.com/api/profiles/?x=2', ['headers' => ['authorization' => 'Klaviyo-API-Key pk_live_2']]);
        $guzzle->get('https://plain.example/x'); // no credential
        self::assertSame([], $this->reports(), 'nothing leaves the process on the request\'s own path');

        $middleware->pipeline()->stop();
        $reports = $this->reports();
        self::assertCount(1, $reports);
        self::assertSame('POST', $reports[0]['method']);
        self::assertSame('Bearer kc_live_x', $this->headerOf($reports[0], 'Authorization'));
        $body = json_decode((string) $reports[0]['body'], true);
        self::assertSame('php/' . KnoxCall::SDK_VERSION, $body['sdk']);
        self::assertCount(1, $body['observations']);
        $o = $body['observations'][0];
        self::assertSame(['a.klaviyo.com', '/api', 'POST', 'authorization', 2],
            [$o['host'], $o['first_segment'], $o['method'], $o['header_name'], $o['count']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $o['first_seen']);
        foreach (['pk_live', 'x=1', 'x=2', 'leak-in-query', 'never-appears', 'profiles'] as $leak) {
            self::assertStringNotContainsString($leak, (string) $reports[0]['body']);
        }
        self::assertSame([['accepted' => 1, 'dropped' => 0]], $flushes);
    }

    public function testNothingIsRecordedForOtherDecisionsOrWhenOptedOut(): void
    {
        $this->queueManifest();
        $this->t->queueJson(200, ['ok' => true]); // the ephemeral hop for the listed host
        $mock = new MockHandler(array_fill(0, 6, new GuzzleResponse(200, [], 'direct')));
        $stack = HandlerStack::create($mock);
        $middleware = $this->client()->wrap->guzzleMiddleware([
            'hosts' => ['api.resend.com'], 'response_factory' => $this->psr17, 'stream_factory' => $this->psr17,
        ]);
        $stack->push($middleware, 'knoxcall');
        $guzzle = new \GuzzleHttp\Client(['handler' => $stack]);
        $auth = ['headers' => ['Authorization' => 'Bearer x']];
        $guzzle->get(self::API . '/v1/anything', $auth);              // own_host
        $guzzle->get('https://acme.knoxcall.com/x', $auth);           // platform host
        $guzzle->post('https://api.stripe.com/v1/tokens', $auth);    // route_around
        $guzzle->post('https://api.resend.com/emails', $auth);       // ephemeral
        putenv('KNOXCALL_INTERCEPT=off');
        $guzzle->get('https://unlisted.example/x', $auth);            // kill_switch
        putenv('KNOXCALL_INTERCEPT');
        $observer = $middleware->pipeline()->observer();
        self::assertNotNull($observer);
        self::assertSame(0, $observer->size());
        $middleware->pipeline()->stop();
        self::assertSame([], $this->reports());

        $opts = ['response_factory' => $this->psr17, 'stream_factory' => $this->psr17];
        self::assertNull($this->client()->wrap->guzzleMiddleware($opts + ['observe_uncovered' => false])->pipeline()->observer());
        putenv('KNOXCALL_OBSERVE_UNCOVERED=off');
        self::assertNull($this->client()->wrap->guzzleMiddleware($opts)->pipeline()->observer());
        putenv('KNOXCALL_OBSERVE_UNCOVERED');
        // The PSR-18 client treats every host as listed: a reporter only with routes 'auto', and it never records.
        self::assertNull($this->client()->wrap->httpClient($opts)->pipeline()->observer());
        self::assertNotNull($this->client()->wrap->httpClient($opts + ['routes' => 'auto'])->pipeline()->observer());
    }

    public function testReportEgressObservationsPostsSdkAndObservationsThroughRequest(): void
    {
        $this->t->queueJson(202, ['data' => ['accepted' => 1, 'dropped' => 1, 'reasons' => ['unknown_host' => 1]], 'meta' => ['request_id' => 'o']]);
        $observations = [['host' => 'h.example', 'first_segment' => '/v1', 'method' => 'GET', 'header_name' => 'authorization',
            'count' => 3, 'first_seen' => '2026-09-26T00:00:00.000Z', 'last_seen' => '2026-09-26T00:01:00.000Z']];
        $res = $this->client()->wrap->reportEgressObservations($observations);
        self::assertSame(['accepted' => 1, 'dropped' => 1, 'reasons' => ['unknown_host' => 1]], $res);
        $req = $this->t->lastRequest();
        self::assertSame('POST', $req['method']);
        self::assertSame(self::OBS, $req['url']);
        self::assertSame(['sdk' => 'php/' . KnoxCall::SDK_VERSION, 'observations' => $observations], json_decode((string) $req['body'], true));
    }

    /** @param array{headers: array<string, string>} $req */
    private function headerOf(array $req, string $name): ?string
    {
        foreach ($req['headers'] as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }
}
