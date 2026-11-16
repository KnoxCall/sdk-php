<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\KnoxCall;
use PHPUnit\Framework\TestCase;

/**
 * PARITY §5 — where the data plane lives under a proxy base.
 *
 * On a KnoxCall cloud tenant host the proxy is served ONLY under `/api`
 * (server.ts strips the prefix; every other path on that host is the
 * dashboard). Until 2026-09-25 call() sent base + path, so every documented
 * `'path' => '/users'` example answered the dashboard HTML on a real tenant
 * host, and the five live smokes passed only because each hard-coded
 * `'path' => '/api/get'`. Measured on a local server that day: `GET /api/get`
 * → 200 with X-Knox-Upstream-Status; `GET /get` → the SPA branch, no upstream
 * call. These pin the URL call() builds for every base shape.
 */
final class DataPlanePathTest extends TestCase
{
    private const ENV_VARS = ['KNOXCALL_PROXY_BASE_URL', 'KNOXCALL_BASE_URL', 'KNOXCALL_API_BASE_URL', 'KNOXCALL_TENANT', 'KNOXCALL_ENVIRONMENT'];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $v) {
            putenv($v);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $v) {
            putenv($v);
        }
    }

    public function testPrefixRule(): void
    {
        $cases = [
            'https://acme.knoxcall.com' => '/api',
            'https://acme.knoxcall.com/' => '/api',
            'https://sandbox-acme.knoxcall.com' => '/api',
            'http://sandbox-acme.knoxcall.com:3100' => '/api',
            'https://ACME.KnoxCall.com' => '/api',
            'https://acme.knoxcall.com/api' => '',
            'https://acme.knoxcall.com/proxy' => '',
            'https://api.knoxcall.com' => '',
            'https://sandbox.knoxcall.com' => '',
            'https://api-staging.knoxcall.com' => '',
            'https://sandbox-staging.knoxcall.com' => '',
            'https://www.knoxcall.com' => '',
            'https://staging.knoxcall.com' => '',
            'https://admin.knoxcall.com' => '',
            'https://a.b.knoxcall.com' => '',
            'https://knoxcall.com' => '',
            'https://acme.knoxcall.com.evil.test' => '',
            'http://localhost:3000' => '',
            'https://knox.example.com' => '',
            'not a url' => '',
        ];
        foreach ($cases as $base => $want) {
            $this->assertSame($want, KnoxCall::dataPlanePathPrefix($base), $base);
        }
    }

    private function urlOf(array $opts, string $path = '/users'): string
    {
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);
        $client = new KnoxCall($opts + [
            'tenant' => 'acme',
            'access_token' => 'kc_live_pre',
            'transport' => $t,
            'retry_base_delay_ms' => 0,
        ]);
        $client->call('r_1', ['path' => $path]);
        $this->assertCount(1, $t->requests);
        return $t->requests[0]['url'];
    }

    public function testDerivedSandboxShape(): void
    {
        $this->assertSame('https://sandbox-acme.knoxcall.com/api/users', $this->urlOf(['base_url' => 'https://sandbox.knoxcall.com']));
    }

    public function testDerivedPlainShape(): void
    {
        $this->assertSame('https://acme.knoxcall.com/api/users', $this->urlOf(['base_url' => 'https://api.knoxcall.com']));
    }

    public function testExplicitOverrideNamingATenantHostAnyPort(): void
    {
        // The live smoke harness's shape. Plain http:// to a non-loopback host
        // raises the SDK's E_USER_WARNING (twice: base and proxy), which
        // failOnWarning="true" would otherwise turn into a test failure — the
        // same scoped handler WarnTest uses absorbs it here.
        $url = $this->absorbingUserWarnings(fn () => $this->urlOf(
            ['base_url' => 'http://sandbox.knoxcall.com:3100', 'proxy_base_url' => 'http://sandbox-acme.knoxcall.com:3100'],
            '/get',
        ));
        $this->assertSame('http://sandbox-acme.knoxcall.com:3100/api/get', $url);
    }

    /** Run $fn with a scoped handler that absorbs E_USER_WARNING (the SDK's warn channel). */
    private function absorbingUserWarnings(callable $fn): mixed
    {
        set_error_handler(static fn (int $no, string $msg): bool => $no === E_USER_WARNING, E_USER_WARNING);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function testEnvOverrideBehavesTheSame(): void
    {
        putenv('KNOXCALL_PROXY_BASE_URL=https://sandbox-acme.knoxcall.com');
        $this->assertSame('https://sandbox-acme.knoxcall.com/api/get', $this->urlOf(['base_url' => 'https://sandbox.knoxcall.com'], '/get'));
    }

    public function testOverrideCarryingTheEntryPointIsVerbatimNeverDoubled(): void
    {
        $this->assertSame(
            'https://sandbox-acme.knoxcall.com/api/get',
            $this->urlOf(['base_url' => 'https://sandbox.knoxcall.com', 'proxy_base_url' => 'https://sandbox-acme.knoxcall.com/api'], '/get'),
        );
    }

    public function testLoopbackOverrideIsVerbatim(): void
    {
        $this->assertSame('http://localhost:3000/get', $this->urlOf(['base_url' => 'http://localhost:3000', 'proxy_base_url' => 'http://localhost:3000'], '/get'));
    }

    public function testSelfHostedBaseIsVerbatim(): void
    {
        $this->assertSame('https://knox.example.com/get', $this->urlOf(['base_url' => 'https://knox.example.com'], '/get'));
    }

    public function testUnslashedBareAndApiPrefixedUpstreamPaths(): void
    {
        $this->assertSame('https://acme.knoxcall.com/api/users', $this->urlOf(['base_url' => 'https://api.knoxcall.com'], 'users'));
        $this->assertSame('https://acme.knoxcall.com/api/api/v2/tickets', $this->urlOf(['base_url' => 'https://api.knoxcall.com'], '/api/v2/tickets'));
        $this->assertSame('https://acme.knoxcall.com/api/', $this->urlOf(['base_url' => 'https://api.knoxcall.com'], '/'));
    }

    public function testBoundRoutesInheritIt(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);
        $client = new KnoxCall(['tenant' => 'acme', 'base_url' => 'https://api.knoxcall.com', 'access_token' => 'kc_live_pre', 'transport' => $t, 'retry_base_delay_ms' => 0]);
        $client->route('r_1')->get('/users');
        $this->assertSame('https://acme.knoxcall.com/api/users', $t->requests[0]['url']);
    }
}
