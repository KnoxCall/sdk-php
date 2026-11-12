<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\ApiException;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\TokenExchangeException;
use PHPUnit\Framework\TestCase;

/**
 * KnoxCall::exchangeToken() — the credential-less RFC 8693 exchange against
 * POST /v1/oauth/token (AIGW-26). Static: no constructed client, transport
 * injected via opts.
 *
 * Three behaviours a caller gets wrong, all asserted here:
 *   1. The response is a BARE OAuth body, not the {data, meta} envelope.
 *   2. 'resource' is only sent when the KEY is present — sending it empty is a
 *      refusal, not "no resource", because dropping it silently would mint an
 *      UNCONFINED token while the caller believes it is audience-restricted.
 *   3. The path is /v1/oauth/token, NOT the root-host /oauth/token that mints
 *      management tokens.
 *   4. The HOST is the tenant data plane. Verified against a running server
 *      2026-08-25: the same request answers 400 invalid_grant on
 *      acme.knoxcall.com and 401 on api.knoxcall.com, so there is no default.
 */
final class TokenExchangeTest extends TestCase
{
    private static function okBody(): array
    {
        return [
            'access_token' => 'kc_live_agt_deadbeef',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'scope' => '{"providers":["anthropic"]}',
        ];
    }

    public function testPostsTheGrantAndReturnsTheBareBody(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        $res = KnoxCall::exchangeToken(
            ['subject_token' => 'header.payload.sig'],
            ['transport' => $t, 'tenant' => 'acme'],
        );

        $this->assertCount(1, $t->requests);
        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('https://acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
        // The subject token IS the credential — nothing else is sent.
        $this->assertNull($t->header(0, 'Authorization'));

        $sent = json_decode((string) $t->requests[0]['body'], true);
        $this->assertSame(KnoxCall::TOKEN_EXCHANGE_GRANT, $sent['grant_type']);
        $this->assertSame(KnoxCall::ID_TOKEN_TYPE, $sent['subject_token_type']);
        $this->assertSame(KnoxCall::KNOXCALL_AUDIENCE, $sent['audience']);
        $this->assertSame('header.payload.sig', $sent['subject_token']);

        // Bare OAuth body — no envelope to unwrap.
        $this->assertSame('kc_live_agt_deadbeef', $res['access_token']);
        $this->assertSame(900, $res['expires_in']);
        $this->assertArrayNotHasKey('data', $res);
    }

    public function testOmitsResourceUnlessTheKeyIsPresent(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => 'acme']);

        $sent = json_decode((string) $t->requests[0]['body'], true);
        $this->assertArrayNotHasKey('resource', $sent);
    }

    public function testForwardsAnEmptyResourceVerbatim(): void
    {
        // It must reach the server and be refused invalid_target. Treating ''
        // as absent would hand back an UNCONFINED agent token to a caller who
        // asked for a confined one — which is why the code uses
        // array_key_exists, not isset.
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        KnoxCall::exchangeToken(
            ['subject_token' => 'a.b.c', 'resource' => ''],
            ['transport' => $t, 'tenant' => 'acme'],
        );

        $sent = json_decode((string) $t->requests[0]['body'], true);
        $this->assertArrayHasKey('resource', $sent);
        $this->assertSame('', $sent['resource']);
    }

    public function testSurfacesTheRfc6749Code(): void
    {
        $t = new MockTransport();
        $t->queueJson(400, [
            'error' => 'invalid_grant',
            'error_description' => 'No tenant bindings registered for issuer https://x',
        ]);

        try {
            KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => 'acme']);
            $this->fail('expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertInstanceOf(ApiException::class, $e);
            $this->assertInstanceOf(KnoxCallException::class, $e);
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('invalid_grant', $e->errorCode);
            $this->assertStringContainsString('No tenant bindings', $e->getMessage());
        }
    }

    public function testRejectsA200WithNoAccessToken(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, ['token_type' => 'Bearer']);

        try {
            KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => 'acme']);
            $this->fail('expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertSame('token_exchange_failed', $e->errorCode);
        }
    }

    public function testDoesNotMaskANonJsonErrorPage(): void
    {
        $t = new MockTransport();
        $t->queueRaw(502, '<html>502</html>');

        try {
            KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => 'acme']);
            $this->fail('expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertSame(502, $e->statusCode);
        }
    }

    public function testStripsATrailingSlashFromBaseUrl(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        KnoxCall::exchangeToken(
            ['subject_token' => 'a.b.c'],
            ['transport' => $t, 'base_url' => 'https://acme.test/'],
        );

        $this->assertSame('https://acme.test/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testDerivesTheTenantDataPlaneHost(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => 'acme']);

        $this->assertSame('https://acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testDerivesTheSandboxDataPlaneHost(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        KnoxCall::exchangeToken(
            ['subject_token' => 'a.b.c'],
            ['transport' => $t, 'tenant' => 'acme', 'sandbox' => true],
        );

        $this->assertSame('https://sandbox-acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testRefusesToGuessAHost(): void
    {
        // api.knoxcall.com answers 401 for this request — the endpoint is not
        // served there. A default would turn "wrong host" into "your CI token
        // was rejected", the hardest possible thing to debug.
        $t = new MockTransport();

        try {
            KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t]);
            $this->fail('expected KnoxCallException');
        } catch (KnoxCallException $e) {
            $this->assertStringContainsString('tenant', $e->getMessage());
        }
        $this->assertCount(0, $t->requests, 'no request may be sent');
    }

    public function testRefusesATenantSlugThatIsNotADnsLabel(): void
    {
        // The slug becomes the host the workload OIDC token is sent to.
        foreach (['evil.com#', 'a b', '-lead', 'trail-'] as $bad) {
            $t = new MockTransport();
            try {
                KnoxCall::exchangeToken(['subject_token' => 'a.b.c'], ['transport' => $t, 'tenant' => $bad]);
                $this->fail("slug {$bad} was accepted");
            } catch (KnoxCallException $e) {
                $this->assertStringContainsString('DNS label', $e->getMessage());
            }
            $this->assertCount(0, $t->requests, 'no request may be sent');
        }
    }
}
