<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\AIGatewayException;
use KnoxCall\ApiException;
use KnoxCall\KnoxCallException;
use PHPUnit\Framework\TestCase;

/**
 * AIGW-163 — the AI DATA plane's typed refusal.
 *
 * The SDK deliberately does not make the data-plane call for you: you point an
 * existing provider client at the agent's `agent_url`. So what the SDK owes you
 * is the ability to TYPE what that client hands back — a
 * `{error, error_description, code}` body, which is NOT the Management API's
 * `{"error": {"type", "message", "request_id"}}` and must not be mistaken for it.
 */
final class AIGatewayErrorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function refusal(): array
    {
        return [
            'error' => 'budget_exceeded',
            'error_description' => 'Daily budget exceeded: $50.0031 >= $50',
            'code' => 'budget_exceeded',
            'utilization_pct' => 100.006,
        ];
    }

    public function testReturnsTypedClassWithCodeDescriptionAndRetryAfter(): void
    {
        $err = AIGatewayException::fromDataPlaneResponse(429, $this->refusal(), [
            'retry-after' => '3600',
            'x-request-id' => '0d5b2a9e-1f3c-4a7d-8e2b-6c9a1f4d7e35',
        ]);

        self::assertInstanceOf(AIGatewayException::class, $err);
        self::assertSame('budget_exceeded', $err->errorCode);
        self::assertSame(429, $err->statusCode);
        self::assertSame('Daily budget exceeded: $50.0031 >= $50', $err->errorDescription);
        self::assertSame(3600, $err->retryAfter);
        self::assertSame('0d5b2a9e-1f3c-4a7d-8e2b-6c9a1f4d7e35', $err->requestId);
    }

    public function testIsAnApiExceptionSoExistingCatchesStillWork(): void
    {
        // PARITY §1: every new typed error is re-parented into the hierarchy.
        $err = AIGatewayException::fromDataPlaneResponse(403, [
            'error' => 'model_not_allowed',
            'error_description' => 'not on the allowlist',
            'code' => 'model_not_allowed',
        ]);
        self::assertInstanceOf(ApiException::class, $err);
        self::assertInstanceOf(KnoxCallException::class, $err);
    }

    public function testRetryAfterIsNullWhenAbsentOrNotWholeSeconds(): void
    {
        // Absence is meaningful: the gateway sends no header rather than a
        // guess, so null must not become 0 (an immediate retry against a spent
        // cap).
        self::assertNull(AIGatewayException::fromDataPlaneResponse(429, $this->refusal())?->retryAfter);
        self::assertNull(
            AIGatewayException::fromDataPlaneResponse(429, $this->refusal(), ['retry-after' => ''])?->retryAfter,
        );
        // An HTTP-date Retry-After is legal but is not delta-seconds.
        self::assertNull(
            AIGatewayException::fromDataPlaneResponse(
                429,
                $this->refusal(),
                ['retry-after' => 'Wed, 09 Sep 2026 00:00:00 GMT'],
            )?->retryAfter,
        );
    }

    public function testReturnsNullForOtherEnvelopes(): void
    {
        $cases = [
            // The nested shape every other /v1 resource answers.
            'management' => ['error' => ['type' => 'not_found', 'message' => 'Gateway not found.']],
            // RFC 6749 §5.2 — no `code` at all.
            'oauth' => ['error' => 'invalid_grant', 'error_description' => 'bad subject token'],
            // The pre-AIGW-163 auth shape: a `code` that is not the `error`.
            'legacyAuth' => ['error' => 'Unauthorized', 'code' => 'expired', 'reason' => 'Token has expired'],
        ];
        foreach ($cases as $name => $body) {
            self::assertNull(AIGatewayException::fromDataPlaneResponse(400, $body), $name);
            self::assertFalse(AIGatewayException::isDataPlaneBody($body), $name);
        }
        self::assertNull(AIGatewayException::fromDataPlaneResponse(502, '<html>502 Bad Gateway</html>'));
        self::assertNull(AIGatewayException::fromDataPlaneResponse(502, null));
        // Anti-vacuity.
        self::assertTrue(AIGatewayException::isDataPlaneBody($this->refusal()));
    }

    public function testKeepsTheRawBody(): void
    {
        $err = AIGatewayException::fromDataPlaneResponse(429, $this->refusal());
        self::assertIsArray($err?->responseBody);
        self::assertSame(100.006, $err->responseBody['utilization_pct']);
    }
}
