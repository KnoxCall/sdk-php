<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\ClientCredentials;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use PHPUnit\Framework\TestCase;

/**
 * Flat construction, env fallbacks, and bound routes (PARITY §2/§5/§6) —
 * mirrors knoxcall-python tests/test_construction.py.
 */
final class ConstructionTest extends TestCase
{
    private const ENV_VARS = [
        'KNOXCALL_TENANT',
        'KNOXCALL_ENVIRONMENT',
        'KNOXCALL_BASE_URL',
        'KNOXCALL_API_BASE_URL',
        'KNOXCALL_PROXY_BASE_URL',
        'KNOXCALL_ACCESS_TOKEN',
        'KNOXCALL_API_KEY',
        'KNOXCALL_CLIENT_ID',
        'KNOXCALL_CLIENT_SECRET',
    ];

    protected function setUp(): void
    {
        $this->clearKnoxCallEnv();
    }

    protected function tearDown(): void
    {
        $this->clearKnoxCallEnv();
    }

    private function clearKnoxCallEnv(): void
    {
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
    }

    private function makeClient(MockTransport $transport, array $opts = []): KnoxCall
    {
        return new KnoxCall($opts + [
            'tenant' => 'acme',
            'transport' => $transport,
            'retry_base_delay_ms' => 0,
            'base_url' => 'https://api.example.test',
            'proxy_base_url' => 'https://acme.example.test',
        ]);
    }

    /** The real management-API success envelope (src/client-api/helpers.ts). */
    private static function envelope(mixed $data): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    // -- Flat credential options -------------------------------------------------

    public function testFlatClientCredentialsMintIdenticallyToCredentialsObject(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_aaaa');
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 'sec']);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://api.example.test/oauth/token', $t->requests[0]['url']);
        parse_str($t->requests[0]['body'], $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('tk_x', $form['client_id']);
        $this->assertSame('sec', $form['client_secret']);
        $this->assertSame('Bearer kc_live_aaaa', $t->header(1, 'Authorization'));
    }

    public function testFlatAccessTokenAndApiKeyAttachBearerWithoutTokenEndpoint(): void
    {
        foreach (['access_token', 'api_key'] as $opt) {
            $t = new MockTransport();
            $t->queueJson(200, ['ok' => true]);

            $client = $this->makeClient($t, [$opt => 'kc_live_pre']);
            $res = $client->call('r_1', ['path' => '/x']);

            $this->assertSame(200, $res['status']);
            // No token-endpoint round trip — the token is the credential.
            $this->assertCount(1, $t->requests, "option {$opt}");
            $this->assertSame('Bearer kc_live_pre', $t->header(0, 'Authorization'), "option {$opt}");
        }
    }

    public function testLegacyTkKeyTravelsAsXKnoxcallKeyOnCall(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['api_key' => 'tk_live_legacy']);
        $client->call('r_1', ['path' => '/x']);

        // proxy OAuth detection matches `Bearer kc_` only — tk_ must use the header
        $this->assertSame('tk_live_legacy', $t->header(0, 'x-knoxcall-key'));
        $this->assertNull($t->header(0, 'Authorization'));
    }

    public function testLegacyTkKeyStaysBearerOnEphemeral(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['api_key' => 'tk_live_legacy']);
        $client->ephemeral('https://upstream.example.test/v1/x');

        // /v1/proxy accepts any credential format as Bearer (PARITY §5).
        $this->assertSame('Bearer tk_live_legacy', $t->header(0, 'Authorization'));
        $this->assertNull($t->header(0, 'x-knoxcall-key'));
    }

    public function testConflictMatrixThrowsAtConstruction(): void
    {
        $conflicts = [
            ['credentials' => new ClientCredentials('tk_x', 'sec'), 'client_id' => 'tk_x'],
            ['access_token' => 'kc_a', 'api_key' => 'kc_b'],
            ['api_key' => 'kc_a', 'client_id' => 'tk_x', 'client_secret' => 's'],
            ['client_id' => 'tk_x'],   // missing client_secret
            ['client_secret' => 's'],  // missing client_id
        ];
        foreach ($conflicts as $opts) {
            try {
                new KnoxCall($opts + ['tenant' => 'acme']);
                $this->fail('expected InvalidArgumentException for ' . implode(', ', array_keys($opts)));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConflictsCheckedOnExplicitOptionsBeforeEnvFill(): void
    {
        // The env could "complete" the pair — the explicit half-pair must
        // still throw (PARITY §2: conflicts run before any env fill).
        putenv('KNOXCALL_CLIENT_SECRET=env_sec');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('client_id and client_secret must be provided together');
        new KnoxCall(['tenant' => 'acme', 'client_id' => 'tk_x']);
    }

    // -- Tenant env fallback / zero-arg --------------------------------------------

    public function testTenantFromEnvEnablesZeroTenantConstruction(): void
    {
        putenv('KNOXCALL_TENANT=envcorp');
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall(['access_token' => 'kc_live_x', 'transport' => $t]);
        $client->call('r_1', ['path' => '/x']);

        // proxy URL must derive from the env-resolved tenant
        $this->assertSame('https://envcorp.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    public function testExplicitTenantBeatsEnv(): void
    {
        putenv('KNOXCALL_TENANT=envcorp');
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall(['tenant' => 'explicit', 'access_token' => 'kc_live_x', 'transport' => $t]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://explicit.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    public function testTenantDiscoveredFromTokenResponse(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, [
            'access_token' => 'kc_live_a', 'token_type' => 'Bearer',
            'expires_in' => 3600, 'tenant' => 'discovered',
        ]);
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall([
            'client_id' => 'tk_x', 'client_secret' => 's', 'transport' => $t,
        ]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://discovered.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    public function testTenantDiscoveredViaAccountForStaticTokens(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['slug' => 'fromaccount', 'name' => 'X']));
        $t->queueJson(200, ['ok' => true]);
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall(['access_token' => 'kc_live_pre', 'transport' => $t]);
        $client->call('r_1', ['path' => '/x']);
        $client->call('r_1', ['path' => '/y']);

        // discovered once via /v1/account, then cached on the instance
        $accountCalls = array_filter(
            $t->requests,
            static fn (array $r): bool => str_contains($r['url'], '/v1/account'),
        );
        $this->assertCount(1, $accountCalls);
        $this->assertSame('https://fromaccount.knoxcall.com/api/y', $t->lastRequest()['url']);
    }

    public function testUndiscoverableTenantRaisesActionableError(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['name' => 'no slug here']));

        $client = new KnoxCall(['access_token' => 'kc_live_pre', 'transport' => $t]);
        $this->expectException(KnoxCallException::class);
        $this->expectExceptionMessage('KNOXCALL_TENANT');
        $client->call('r_1', ['path' => '/x']);
    }

    public function testManagementRequestsNeedNoTenant(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $envelope = self::envelope([]);
        $t->queueJson(200, $envelope);

        $client = new KnoxCall([
            'client_id' => 'tk_x', 'client_secret' => 's', 'transport' => $t,
            'base_url' => 'https://api.example.test',
        ]);
        $this->assertSame($envelope, $client->request('GET', '/v1/routes'));
        // never needed, never discovered
        $this->assertNull((new \ReflectionProperty($client, 'tenant'))->getValue($client));
    }

    public function testZeroArgConstructionWithFullEnv(): void
    {
        putenv('KNOXCALL_TENANT=envcorp');
        putenv('KNOXCALL_CLIENT_ID=tk_env');
        putenv('KNOXCALL_CLIENT_SECRET=sec');

        $client = new KnoxCall();

        $this->assertSame('envcorp', (new \ReflectionProperty($client, 'tenant'))->getValue($client));
        $credentials = (new \ReflectionProperty($client, 'credentials'))->getValue($client);
        $this->assertInstanceOf(ClientCredentials::class, $credentials);
        $this->assertSame('tk_env', $credentials->clientId);
    }

    // -- Env credential fill ---------------------------------------------------------

    public function testEnvAccessTokenWinsOverEnvApiKey(): void
    {
        putenv('KNOXCALL_ACCESS_TOKEN=kc_env_token');
        putenv('KNOXCALL_API_KEY=kc_env_key');
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = $this->makeClient($t);
        $client->request('GET', '/v1/routes');

        $this->assertSame('Bearer kc_env_token', $t->header(0, 'Authorization'));
    }

    public function testEnvCredentialFillSkippedWhenExplicitCredentialPassed(): void
    {
        putenv('KNOXCALL_ACCESS_TOKEN=kc_env_token');
        $t = new MockTransport();
        $t->queueToken('kc_live_minted');
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's']);
        $client->request('GET', '/v1/routes');

        // The explicit client credentials mint a token; the env token is ignored.
        $this->assertSame('https://api.example.test/oauth/token', $t->requests[0]['url']);
        $this->assertSame('Bearer kc_live_minted', $t->header(1, 'Authorization'));
    }

    public function testCanonicalBaseUrlEnvWinsOverLegacySpelling(): void
    {
        putenv('KNOXCALL_BASE_URL=https://canonical.example.test');
        putenv('KNOXCALL_API_BASE_URL=https://legacy.example.test');
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['tenant' => 'acme', 'access_token' => 'kc_x', 'transport' => $t]);
        $client->request('GET', '/v1/routes');

        $this->assertSame('https://canonical.example.test/v1/routes', $t->lastRequest()['url']);
    }

    public function testLegacyBaseUrlEnvStillAccepted(): void
    {
        putenv('KNOXCALL_API_BASE_URL=https://legacy.example.test');
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['tenant' => 'acme', 'access_token' => 'kc_x', 'transport' => $t]);
        $client->request('GET', '/v1/routes');

        $this->assertSame('https://legacy.example.test/v1/routes', $t->lastRequest()['url']);
    }

    // -- Sandbox / Test mode (PARITY §2) --------------------------------------------

    public function testSandboxDefaultsBaseAndProxyToSandboxHosts(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall([
            'sandbox' => true, 'tenant' => 'acme', 'api_key' => 'kc_test_x', 'transport' => $t,
        ]);
        $client->request('GET', '/v1/routes');
        $this->assertSame('https://sandbox.knoxcall.com/v1/routes', $t->requests[0]['url']);

        // Data plane lives on the sandbox- prefixed per-tenant subdomain.
        $client->call('r_1', ['path' => '/x']);
        $this->assertSame('https://sandbox-acme.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    public function testSandboxIgnoredWhenExplicitBaseUrlProvided(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        // Self-hosted shape: proxy runs on the same host, sandbox flag is moot.
        $client = new KnoxCall([
            'sandbox' => true, 'tenant' => 'acme', 'api_key' => 'kc_test_x',
            'transport' => $t, 'base_url' => 'https://api.example.test',
        ]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://api.example.test/x', $t->lastRequest()['url']);
    }

    public function testEnvBaseUrlWinsOverSandboxDefault(): void
    {
        putenv('KNOXCALL_BASE_URL=https://canonical.example.test');
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall([
            'sandbox' => true, 'tenant' => 'acme', 'api_key' => 'kc_test_x', 'transport' => $t,
        ]);
        $client->request('GET', '/v1/routes');

        $this->assertSame('https://canonical.example.test/v1/routes', $t->lastRequest()['url']);
    }

    public function testSandboxTenantDiscoveryDerivesSandboxProxy(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['slug' => 'fromaccount', 'name' => 'X']));
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall(['sandbox' => true, 'access_token' => 'kc_test_pre', 'transport' => $t]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://sandbox-fromaccount.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    // -- Bound routes ----------------------------------------------------------------

    public function testBoundRouteInjectsRouteAndEnvironment(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's']);
        $printnode = $client->route('r_1', ['environment' => 'production', 'headers' => ['X-A' => 'bound']]);
        $res = $printnode->get('/computers');

        $this->assertSame(200, $res['status']);
        $this->assertSame('r_1', $t->header(1, 'x-knoxcall-route'));
        $this->assertSame('production', $t->header(1, 'x-knoxcall-environment'));
        $this->assertSame('bound', $t->header(1, 'X-A'));
        $this->assertSame('https://acme.example.test/computers', $t->lastRequest()['url']);
    }

    public function testBoundRoutePerCallValuesBeatBoundDefaults(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's']);
        $bound = $client->route('r_1', ['environment' => 'production', 'headers' => ['X-A' => 'bound']]);
        $bound->post('/printjobs', [
            'body' => ['a' => 1],
            'environment' => 'staging',
            'headers' => ['X-A' => 'call'],
        ]);

        $this->assertSame('POST', $t->lastRequest()['method']);
        $this->assertSame('staging', $t->header(1, 'x-knoxcall-environment'));
        $this->assertSame('call', $t->header(1, 'X-A'));
    }

    public function testBoundRouteGenericRequestMethod(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's']);
        $client->route('r_1')->request('DELETE', '/printjobs/42');

        $this->assertSame('DELETE', $t->lastRequest()['method']);
        $this->assertSame('https://acme.example.test/printjobs/42', $t->lastRequest()['url']);
    }

    public function testBoundRouteTimeoutDefaultAndPerCallOverride(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's']);
        $bound = $client->route('r_1', ['timeout_ms' => 9000]);

        $bound->get('/a');
        $this->assertSame(9000, $t->lastRequest()['timeoutMs']);

        $bound->get('/b', ['timeout_ms' => 1234]);
        $this->assertSame(1234, $t->lastRequest()['timeoutMs']);
    }

    // -- Default environment (PARITY §2) -------------------------------------------

    public function testClientDefaultEnvironmentAppliesToCalls(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, [
            'client_id' => 'tk_x', 'client_secret' => 's', 'environment' => 'production',
        ]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('production', $t->header(1, 'x-knoxcall-environment'));
    }

    public function testEnvironmentResolutionPerCallBeatsBoundBeatsClient(): void
    {
        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);
        $t->queueJson(200, ['ok' => true]);
        $t->queueJson(200, ['ok' => true]);

        $client = $this->makeClient($t, [
            'client_id' => 'tk_x', 'client_secret' => 's', 'environment' => 'client-env',
        ]);

        $client->route('r_1')->get('/x');
        $this->assertSame('client-env', $t->header(1, 'x-knoxcall-environment'));

        $client->route('r_1', ['environment' => 'bound-env'])->get('/x');
        $this->assertSame('bound-env', $t->header(2, 'x-knoxcall-environment'));

        $client->route('r_1', ['environment' => 'bound-env'])->get('/x', ['environment' => 'call-env']);
        $this->assertSame('call-env', $t->header(3, 'x-knoxcall-environment'));
    }

    public function testEnvironmentFromEnvVarWithExplicitWinning(): void
    {
        putenv('KNOXCALL_ENVIRONMENT=staging');

        $t = new MockTransport();
        $t->queueToken('kc_live_one');
        $t->queueJson(200, ['ok' => true]);
        $this->makeClient($t, ['client_id' => 'tk_x', 'client_secret' => 's'])->call('r_1', ['path' => '/x']);
        $this->assertSame('staging', $t->header(1, 'x-knoxcall-environment'));

        $t2 = new MockTransport();
        $t2->queueToken('kc_live_one');
        $t2->queueJson(200, ['ok' => true]);
        $this->makeClient($t2, [
            'client_id' => 'tk_x', 'client_secret' => 's', 'environment' => 'production',
        ])->call('r_1', ['path' => '/x']);
        $this->assertSame('production', $t2->header(1, 'x-knoxcall-environment'));
    }

    // -- Tenant slug validation (PARITY §2) -----------------------------------------

    public function testHostileExplicitTenantRejectedBeforeBecomingHost(): void
    {
        // A tenant slug becomes the data-plane host https://<slug>.knoxcall.com;
        // a non-DNS-label slug must be rejected at construction (hosted base
        // → per-tenant subdomain derivation), never interpolated into a host.
        $this->expectException(KnoxCallException::class);
        $this->expectExceptionMessage('invalid tenant slug');
        new KnoxCall(['tenant' => 'evil.com#', 'api_key' => 'kc_x']);
    }

    public function testHostileDiscoveredTenantRejectedBeforeBecomingHost(): void
    {
        // The slug adopted from /v1/account is equally untrusted: reject it
        // before it can misdirect the bearer token to another host.
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['slug' => 'evil.com/x', 'name' => 'X']));

        $client = new KnoxCall(['access_token' => 'kc_live_pre', 'transport' => $t]);

        $this->expectException(KnoxCallException::class);
        $this->expectExceptionMessage('invalid tenant slug');
        $client->call('r_1', ['path' => '/x']);
    }
}
