<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\WebhookSignatureVerificationException;
use PHPUnit\Framework\TestCase;

/**
 * constructEvent — verify-and-parse for incoming webhook deliveries
 * (PARITY §12). Signatures are produced exactly the way the server's
 * src/webhooks/hmac-formats.ts does, then verified round-trip.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_do_not_leak';

    /** A realistic delivery envelope with a fresh ISO-8601 timestamp. */
    private static function body(string $event = 'request.success', ?string $timestamp = null): string
    {
        return json_encode([
            'event' => $event,
            'timestamp' => $timestamp ?? gmdate(DATE_ATOM),
            'webhook_id' => 'wh_123',
            'webhook_name' => 'orders-hook',
            'data' => [
                'route_id' => 'r_1',
                'route_name' => 'orders',
                'environment' => 'production',
                'request' => ['method' => 'POST', 'path' => '/v1/orders', 'ip' => '203.0.113.9'],
                'response' => ['status' => 201, 'latency_ms' => 42],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    private static function legacySign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    // -- legacy ------------------------------------------------------------------

    public function testLegacyEventVerifiesAndParses(): void
    {
        $body = self::body();
        $event = KnoxCall::constructEvent($body, [
            'X-Webhook-Signature' => self::legacySign($body),
            'X-Webhook-ID' => 'wh_123',
            'X-Webhook-Event' => 'request.success',
        ], self::SECRET);

        $this->assertSame('request.success', $event['event']);
        $this->assertSame('wh_123', $event['webhook_id']);
        $this->assertSame('orders-hook', $event['webhook_name']);
        $this->assertSame('orders', $event['data']['route_name']);
        $this->assertSame(201, $event['data']['response']['status']);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $body = self::body();
        $event = KnoxCall::constructEvent($body, [
            'x-webhook-signature' => self::legacySign($body),
        ], self::SECRET);

        $this->assertSame('request.success', $event['event']);
    }

    public function testWrongSecretThrowsTypedError(): void
    {
        $body = self::body();
        try {
            KnoxCall::constructEvent($body, [
                'X-Webhook-Signature' => self::legacySign($body, 'whsec_other'),
            ], self::SECRET);
            $this->fail('expected WebhookSignatureVerificationException');
        } catch (WebhookSignatureVerificationException $e) {
            $this->assertInstanceOf(KnoxCallException::class, $e);
            // The message says what failed without echoing signature or secret.
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(hash_hmac('sha256', $body, 'whsec_other'), $e->getMessage());
        }
    }

    public function testMissingSignatureHeaderThrows(): void
    {
        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('missing signature header');
        KnoxCall::constructEvent(self::body(), ['Content-Type' => 'application/json'], self::SECRET);
    }

    public function testStaleEnvelopeTimestampRejectedForLegacy(): void
    {
        $body = self::body(timestamp: gmdate(DATE_ATOM, time() - 3600));
        try {
            KnoxCall::constructEvent($body, ['X-Webhook-Signature' => self::legacySign($body)], self::SECRET);
            $this->fail('expected WebhookSignatureVerificationException');
        } catch (WebhookSignatureVerificationException $e) {
            $this->assertStringContainsString('tolerance', $e->getMessage());
        }

        // Explicitly disabling the tolerance skips the replay check.
        $event = KnoxCall::constructEvent(
            $body,
            ['X-Webhook-Signature' => self::legacySign($body)],
            self::SECRET,
            ['tolerance_seconds' => null],
        );
        $this->assertSame('request.success', $event['event']);
    }

    // -- stripe ------------------------------------------------------------------

    public function testStripeRoundTrip(): void
    {
        $body = self::body();
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);

        $event = KnoxCall::constructEvent($body, [
            'Stripe-Signature' => "t={$ts},v1={$sig}",
        ], self::SECRET, ['format' => 'stripe']);

        $this->assertSame('request.success', $event['event']);
    }

    public function testStripeStaleTimestampRejected(): void
    {
        $body = self::body();
        $ts = time() - 4000; // outside the default 300s window
        $sig = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);

        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('tolerance');
        KnoxCall::constructEvent($body, [
            'Stripe-Signature' => "t={$ts},v1={$sig}",
        ], self::SECRET, ['format' => 'stripe']);
    }

    public function testStripeMultipleV1EntriesAnyMatchPasses(): void
    {
        // Mirrors Stripe's own behavior during secret rotation: the header
        // carries several v1 entries and any matching one passes.
        $body = self::body();
        $ts = time();
        $good = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);
        $bad = hash_hmac('sha256', "{$ts}.{$body}", 'whsec_rotated_out');

        $event = KnoxCall::constructEvent($body, [
            'Stripe-Signature' => "t={$ts},v1={$bad},v1={$good}",
        ], self::SECRET, ['format' => 'stripe']);
        $this->assertSame('request.success', $event['event']);

        $this->expectException(WebhookSignatureVerificationException::class);
        KnoxCall::constructEvent($body, [
            'Stripe-Signature' => "t={$ts},v1={$bad}",
        ], self::SECRET, ['format' => 'stripe']);
    }

    public function testStripeNonNumericTimestampRejected(): void
    {
        // A non-numeric t= must raise the typed error, never be silently
        // accepted (which would coerce to 0 and pass an "epoch" replay window).
        $body = self::body();
        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('malformed Stripe-Signature header');
        KnoxCall::constructEvent($body, [
            'Stripe-Signature' => 't=notanumber,v1=deadbeef',
        ], self::SECRET, ['format' => 'stripe']);
    }

    // -- slack -------------------------------------------------------------------

    public function testSlackRoundTrip(): void
    {
        $body = self::body();
        $ts = (string) time();
        $sig = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);

        $event = KnoxCall::constructEvent($body, [
            'X-Slack-Signature' => $sig,
            'X-Slack-Request-Timestamp' => $ts,
        ], self::SECRET, ['format' => 'slack']);

        $this->assertSame('request.success', $event['event']);
    }

    public function testSlackStaleTimestampRejected(): void
    {
        $body = self::body();
        $ts = (string) (time() - 4000);
        $sig = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);

        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('tolerance');
        KnoxCall::constructEvent($body, [
            'X-Slack-Signature' => $sig,
            'X-Slack-Request-Timestamp' => $ts,
        ], self::SECRET, ['format' => 'slack']);
    }

    public function testSlackNonNumericTimestampRejected(): void
    {
        // A non-numeric X-Slack-Request-Timestamp must raise the typed error.
        $body = self::body();
        $sig = 'v0=' . hash_hmac('sha256', "v0:notanumber:{$body}", self::SECRET);

        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('malformed X-Slack-Request-Timestamp header');
        KnoxCall::constructEvent($body, [
            'X-Slack-Signature' => $sig,
            'X-Slack-Request-Timestamp' => 'notanumber',
        ], self::SECRET, ['format' => 'slack']);
    }

    // -- github / aws-sns / custom -------------------------------------------------

    public function testGithubFormat(): void
    {
        $body = self::body();
        $event = KnoxCall::constructEvent($body, [
            'X-Hub-Signature-256' => self::legacySign($body),
        ], self::SECRET, ['format' => 'github']);

        $this->assertSame('request.success', $event['event']);
    }

    public function testAwsSnsBase64Format(): void
    {
        $body = self::body();
        $sig = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        $event = KnoxCall::constructEvent($body, [
            'x-amz-sns-signature' => $sig,
        ], self::SECRET, ['format' => 'aws-sns']);

        $this->assertSame('request.success', $event['event']);
    }

    public function testCustomHeaderName(): void
    {
        $body = self::body();
        $event = KnoxCall::constructEvent($body, [
            'X-Acme-Signature' => self::legacySign($body),
        ], self::SECRET, ['format' => 'custom', 'header_name' => 'X-Acme-Signature']);

        $this->assertSame('request.success', $event['event']);
    }

    public function testCustomFormatRequiresHeaderName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('header_name');
        KnoxCall::constructEvent(self::body(), [], self::SECRET, ['format' => 'custom']);
    }

    public function testUnknownFormatRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KnoxCall::constructEvent(self::body(), [], self::SECRET, ['format' => 'md5']);
    }

    // -- parsing -------------------------------------------------------------------

    public function testBodyNotJsonThrowsTypedError(): void
    {
        $body = 'not json at all';
        $this->expectException(WebhookSignatureVerificationException::class);
        $this->expectExceptionMessage('not a JSON object');
        KnoxCall::constructEvent($body, ['X-Webhook-Signature' => self::legacySign($body)], self::SECRET);
    }

    public function testUnknownEventTypeStillParses(): void
    {
        // The event-type list is open for forward compatibility.
        $body = self::body(event: 'lease.expiring_soon');
        $event = KnoxCall::constructEvent($body, ['X-Webhook-Signature' => self::legacySign($body)], self::SECRET);

        $this->assertSame('lease.expiring_soon', $event['event']);
    }

    public function testAuditEventShape(): void
    {
        $body = json_encode([
            'event' => 'audit.event',
            'timestamp' => gmdate(DATE_ATOM),
            'data' => [
                'id' => 'al_1', 'action' => 'route.create', 'resource_type' => 'route',
                'resource_id' => 'r_1', 'details' => ['name' => 'orders'], 'ip_address' => '203.0.113.9',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $event = KnoxCall::constructEvent($body, ['X-Webhook-Signature' => self::legacySign($body)], self::SECRET);

        $this->assertSame('audit.event', $event['event']);
        $this->assertArrayNotHasKey('webhook_id', $event);
        $this->assertSame('route.create', $event['data']['action']);
    }

    // -- instance method + implementation properties --------------------------------

    public function testWebhooksResourceMethodDelegates(): void
    {
        $t = new MockTransport(); // no requests expected — verification is local
        $client = new KnoxCall(['tenant' => 'acme', 'api_key' => 'kc_x', 'transport' => $t]);

        $body = self::body();
        $event = $client->webhooks->constructEvent($body, [
            'X-Webhook-Signature' => self::legacySign($body),
        ], self::SECRET);

        $this->assertSame('request.success', $event['event']);
        $this->assertCount(0, $t->requests);
    }

    public function testConstantTimeComparisonUsed(): void
    {
        // Assert via the implementation (not timing): every signature
        // comparison inside constructEvent goes through hash_equals().
        $method = new \ReflectionMethod(KnoxCall::class, 'constructEvent');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('hash_equals(', $source);
        // No direct equality on a computed HMAC anywhere in the method.
        $this->assertDoesNotMatchRegularExpression('/hash_hmac\([^;]*(===|==)/', $source);
        $this->assertDoesNotMatchRegularExpression('/(===|==)\s*\$signature/', $source);
    }

    public function testVerifySignatureBooleanHelperUnchanged(): void
    {
        // The boolean helper stays for compat (PARITY §12): stripe-shaped
        // header over `<ts>.<body>`.
        $body = self::body();
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);

        $this->assertTrue(KnoxCall::verifySignature($body, "t={$ts},v1={$sig}", self::SECRET, 300, $ts));
        $this->assertFalse(KnoxCall::verifySignature($body, "t={$ts},v1=deadbeef", self::SECRET, 300, $ts));
    }
}
