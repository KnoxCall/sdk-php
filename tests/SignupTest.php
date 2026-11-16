<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\ApiException;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\SignupException;
use PHPUnit\Framework\TestCase;

/**
 * KnoxCall::signup() / KnoxCall::claimSignup() — the credential-less
 * headless-signup helpers (PARITY §11). Static: no constructed client,
 * transport injected via opts.
 *
 * Rewritten 2026-08-28 for the F-25 contract (wave-2 row 2-561): signup no
 * longer has a 201 and never returns a credential. All three success shapes
 * are covered — the signup 202, the claim's pending 202 (a SUCCESS, not an
 * error) and the claim's ready 200.
 */
final class SignupTest extends TestCase
{
    private static function acceptedResponse(): array
    {
        return [
            'data' => [
                'status' => 'pending',
                'claim_handle' => 'sck_Yy3n0Rz1qF8mKpX2sVb7dH9tLwQ4eJ6uA1cN5gZ8kT0',
                'claim_path' => '/v1/signup/claim',
                'poll_after_seconds' => 5,
                'expires_at' => '2026-08-29T09:14:22.117Z',
                'message' => 'If this email can be registered, a sign-in link has been sent.',
                'documentation' => 'https://docs.knoxcall.com',
            ],
            'meta' => ['request_id' => 'req-signup'],
        ];
    }

    private static function readyResponse(): array
    {
        return [
            'data' => [
                'status' => 'ready',
                'tenant' => ['id' => 'tn_1', 'slug' => 'acme', 'name' => 'Acme Inc', 'region' => 'us', 'plan' => 'free'],
                'starter' => [
                    'route' => ['id' => 'r_1', 'name' => 'getting-started', 'target_base_url' => 'https://httpbin.org'],
                    'api_key' => ['id' => 'k_1', 'key_id' => 'key_1', 'api_key' => 'tk_test_once', 'key_prefix' => 'tk_test_', 'key_type' => 'test'],
                    'sandbox_host' => 'sandbox-acme.knoxcall.com',
                    'curl' => 'curl …',
                ],
                'sandbox' => ['management_api' => 'https://sandbox.knoxcall.com/v1', 'proxy_host' => 'sandbox-acme.knoxcall.com', 'note' => 'Test mode'],
                'documentation' => 'https://docs.knoxcall.com',
            ],
            'meta' => ['request_id' => 'req-claim'],
        ];
    }

    public function testSignupSuccessUnwrapsData(): void
    {
        $t = new MockTransport();
        $t->queueJson(202, self::acceptedResponse());

        $accepted = KnoxCall::signup(
            ['email' => 'dev@example.com', 'tenant_name' => 'Acme Inc'],
            ['transport' => $t],
        );

        // Credential-less: exactly one request, to the public signup endpoint.
        $this->assertCount(1, $t->requests);
        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('https://api.knoxcall.com/v1/signup', $t->requests[0]['url']);
        $this->assertNull($t->header(0, 'Authorization'));
        $this->assertSame('application/json', $t->header(0, 'Content-Type'));
        $this->assertSame(
            ['email' => 'dev@example.com', 'tenant_name' => 'Acme Inc'],
            json_decode((string) $t->requests[0]['body'], true),
        );

        // Unwrapped, and carrying a handle rather than a credential.
        $this->assertSame('pending', $accepted['status']);
        $this->assertStringStartsWith('sck_', $accepted['claim_handle']);
        $this->assertSame(5, $accepted['poll_after_seconds']);
        $this->assertArrayNotHasKey('data', $accepted);
        // The contract row 2-561 exists to enforce.
        $this->assertArrayNotHasKey('starter', $accepted);
        $this->assertStringNotContainsString('tk_', json_encode($accepted));
    }

    public function testSignupExistingEmailIsIndistinguishable(): void
    {
        $t = new MockTransport();
        $t->queueJson(202, self::acceptedResponse());

        $out = KnoxCall::signup(['email' => 'known@example.com', 'tenant_name' => 'Acme'], ['transport' => $t]);

        $this->assertSame('pending', $out['status']);
        $this->assertArrayNotHasKey('starter', $out);
    }

    public function testClaimSignupPendingIsASuccess(): void
    {
        $t = new MockTransport();
        $t->queueJson(202, ['data' => [
            'status' => 'pending',
            'message' => 'Not ready yet.',
            'poll_after_seconds' => 5,
            'expires_at' => '2026-08-29T09:14:22.117Z',
        ], 'meta' => []]);

        $out = KnoxCall::claimSignup('sck_handle', ['transport' => $t]);

        $this->assertSame('https://api.knoxcall.com/v1/signup/claim', $t->requests[0]['url']);
        $this->assertNull($t->header(0, 'Authorization'));
        $this->assertSame(
            ['claim_handle' => 'sck_handle'],
            json_decode((string) $t->requests[0]['body'], true),
        );
        $this->assertSame('pending', $out['status']);
        $this->assertArrayNotHasKey('starter', $out);
    }

    public function testClaimSignupReadyCarriesTheOneTimeKey(): void
    {
        $t = new MockTransport();
        $t->queueJson(200, self::readyResponse());

        $out = KnoxCall::claimSignup('sck_handle', ['transport' => $t]);

        $this->assertSame('ready', $out['status']);
        $this->assertSame('acme', $out['tenant']['slug']);
        $this->assertSame('tk_test_once', $out['starter']['api_key']['api_key']);
        $this->assertSame('test', $out['starter']['api_key']['key_type']);
    }

    public function testClaimSignupAlreadyCollectedThrows(): void
    {
        $t = new MockTransport();
        $t->queueJson(409, [
            'error' => ['type' => 'claim_already_collected', 'message' => 'Already collected.', 'request_id' => 'req-z'],
        ]);

        try {
            KnoxCall::claimSignup('sck_handle', ['transport' => $t]);
            $this->fail('expected SignupException');
        } catch (SignupException $e) {
            $this->assertInstanceOf(KnoxCallException::class, $e);
            $this->assertSame(409, $e->statusCode);
            $this->assertSame('claim_already_collected', $e->errorCode);
        }
    }

    public function testSignupCustomBaseUrl(): void
    {
        $t = new MockTransport();
        $t->queueJson(202, self::acceptedResponse());

        KnoxCall::signup(
            ['email' => 'dev@example.com', 'tenant_name' => 'Acme Inc'],
            ['transport' => $t, 'base_url' => 'https://api-staging.knoxcall.com/'],
        );

        $this->assertSame('https://api-staging.knoxcall.com/v1/signup', $t->requests[0]['url']);
    }

    public function testSignupErrorThrowsTypedExceptionInsideHierarchy(): void
    {
        $t = new MockTransport();
        $t->queueJson(409, [
            'error' => ['type' => 'slug_taken', 'message' => "Tenant slug 'acme' is already taken.", 'request_id' => 'req-x'],
        ]);

        try {
            KnoxCall::signup(['email' => 'dev@example.com', 'tenant_name' => 'Acme', 'tenant_slug' => 'acme'], ['transport' => $t]);
            $this->fail('expected SignupException');
        } catch (SignupException $e) {
            // part of the SDK hierarchy (PARITY §1), with the server's error type
            $this->assertInstanceOf(ApiException::class, $e);
            $this->assertInstanceOf(KnoxCallException::class, $e);
            $this->assertSame(409, $e->statusCode);
            $this->assertSame('slug_taken', $e->errorCode);
            $this->assertSame('req-x', $e->requestId);
            $this->assertStringContainsString('already taken', $e->getMessage());
        }
    }

    public function testSignupRateLimitCarriesType(): void
    {
        $t = new MockTransport();
        $t->queueJson(429, [
            'error' => ['type' => 'rate_limited', 'message' => 'Too many signup attempts from this address.', 'request_id' => 'req-y'],
        ]);

        try {
            KnoxCall::signup(['email' => 'dev@example.com', 'tenant_name' => 'Acme'], ['transport' => $t]);
            $this->fail('expected SignupException');
        } catch (SignupException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame('rate_limited', $e->errorCode);
        }
    }

    public function testSignupUnexpectedBodyThrows(): void
    {
        // e.g. an edge-proxy HTML page with a 200 status
        $t = new MockTransport();
        $t->queueRaw(200, '<html>interstitial</html>');

        $this->expectException(SignupException::class);
        $this->expectExceptionMessage('status 200');
        KnoxCall::signup(['email' => 'dev@example.com', 'tenant_name' => 'Acme'], ['transport' => $t]);
    }
}
