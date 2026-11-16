<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\WorkloadCredentialProvider;
use KnoxCall\ConnectionException;
use KnoxCall\Redacted;
use KnoxCall\StaleAssertionException;
use KnoxCall\Warn;
use PHPUnit\Framework\TestCase;

/**
 * WorkloadCredentialProvider — WIF Phase 4.3. Twin of the Node SDK's
 * test/workload-provider.test.ts; sdk/PARITY.md is the shared contract.
 *
 * The contract worth testing is not "it caches a token". It is the two rules
 * that come from KnoxCall assertions being SINGLE-USE:
 *
 *   1. every exchange reads a FRESH assertion from the source, and an assertion
 *      whose bytes were already spent is refused locally with an error that
 *      names the real cause — rather than forwarded to be refused as a replay,
 *      which reads as "your CI identity was rejected";
 *   2. a cached token is served without re-exchanging, because each exchange
 *      spends an assertion.
 *
 * Plus the two-tier boundary: advisory failures are survivable, mandatory ones
 * are not, and the 90 seconds between them is the point of having two tiers.
 */
final class WorkloadCredentialProviderTest extends TestCase
{
    private const LIFETIME = 900; // expires_in, what the gateway mints today

    private float $clock = 1000000.0;

    protected function setUp(): void
    {
        $this->clock = 1000000.0;
        Warn::resetForTests();
    }

    private function queueToken(MockTransport $t, string $token): void
    {
        $t->queueJson(200, [
            'access_token' => $token,
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'token_type' => 'Bearer',
            'expires_in' => self::LIFETIME,
        ]);
    }

    /** A distinct assertion per call, as a real platform token endpoint produces. */
    private function freshSource(): callable
    {
        $n = 0;

        return static function () use (&$n): string {
            $n++;

            return "assertion-{$n}";
        };
    }

    private function provider(MockTransport $t, callable $assertion, array $input = [], array $opts = []): WorkloadCredentialProvider
    {
        return new WorkloadCredentialProvider(
            $assertion,
            $input,
            $opts + [
                'transport' => $t,
                'tenant' => 'acme',
                'clock' => fn (): float => $this->clock,
            ],
        );
    }

    private function sent(MockTransport $t, int $i): array
    {
        return json_decode((string) $t->requests[$i]['body'], true);
    }

    /**
     * Run $fn with the SDK's E_USER_WARNING absorbed and returned.
     *
     * The advisory tier warns rather than throwing — that IS the contract — and
     * phpunit.xml.dist sets failOnWarning="true", so a test that merely lets the
     * warning escape would red the suite for doing the right thing. Same shape
     * as WarnTest::captureWarnings.
     *
     * @return list<string> the captured warning messages
     */
    private function captureWarnings(callable $fn): array
    {
        $messages = [];
        set_error_handler(
            static function (int $errno, string $errstr) use (&$messages): bool {
                $messages[] = $errstr;
                return true; // handled — do not propagate to PHPUnit's handler
            },
            E_USER_WARNING,
        );
        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    // -- the single-use rule -------------------------------------------------

    public function testReadsAFreshAssertionForEveryExchange(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $this->queueToken($t, 'kp_live_tok2');
        $p = $this->provider($t, $this->freshSource());

        $p->accessToken();
        $this->clock += self::LIFETIME - WorkloadCredentialProvider::MANDATORY_REFRESH_SECONDS / 2;
        $p->accessToken();

        $this->assertCount(2, $t->requests);
        $this->assertSame('assertion-1', $this->sent($t, 0)['subject_token']);
        $this->assertSame(
            'assertion-2',
            $this->sent($t, 1)['subject_token'],
            'the provider reused the first assertion, which the server refuses as a replay',
        );
    }

    public function testRefusesARepeatedAssertionWithoutSendingIt(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider($t, static fn (): string => 'captured-once-at-startup');

        $p->accessToken();
        $this->assertCount(1, $t->requests);

        $this->clock += self::LIFETIME; // force a mandatory refresh
        try {
            $p->accessToken();
            $this->fail('expected a StaleAssertionException');
        } catch (StaleAssertionException $e) {
            // The refusal explains what to do, not just what happened.
            $this->assertStringContainsString('single-use', $e->getMessage());
            $this->assertStringContainsString('NEWLY minted', $e->getMessage());
        }

        // The doomed request is never made: the whole point is to fail at the
        // real cause instead of surfacing the server's replay refusal.
        $this->assertCount(1, $t->requests, 'a spent assertion was sent to the server');
    }

    public function testRefusesAnEmptyAssertionBeforeAnyExchange(): void
    {
        $t = new MockTransport();
        $p = $this->provider($t, static fn (): string => '');

        $this->expectException(StaleAssertionException::class);
        try {
            $p->accessToken();
        } finally {
            $this->assertCount(0, $t->requests);
        }
    }

    public function testAFailedExchangeDoesNotBurnTheAssertion(): void
    {
        // The server claims the assertion before minting, so only a SUCCESS
        // makes those bytes unusable. Burning the fingerprint on a transport
        // error would strand a caller whose assertion is still perfectly good.
        $t = new MockTransport();
        $t->queueThrow(new ConnectionException('connection reset'));
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider($t, static fn (): string => 'retryable-assertion');

        try {
            $p->accessToken();
            $this->fail('expected the transport failure to surface');
        } catch (ConnectionException $e) {
            // expected
        }

        $this->assertInstanceOf(Redacted::class, $p->accessToken());
        $this->assertCount(2, $t->requests);
        $this->assertSame('retryable-assertion', $this->sent($t, 1)['subject_token']);
    }

    // -- the two-tier schedule -----------------------------------------------

    public function testServesTheCachedTokenWhileComfortablyAlive(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider($t, $this->freshSource());

        $first = $p->accessToken();
        $this->clock += self::LIFETIME - WorkloadCredentialProvider::ADVISORY_REFRESH_SECONDS - 10;

        $this->assertSame($first->expose(), $p->accessToken()->expose());
        $this->assertCount(1, $t->requests);
    }

    public function testRefreshesOpportunisticallyInsideTheAdvisoryWindow(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $this->queueToken($t, 'kp_live_tok2');
        $p = $this->provider($t, $this->freshSource());

        $p->accessToken();
        $this->clock += self::LIFETIME - WorkloadCredentialProvider::ADVISORY_REFRESH_SECONDS + 10;
        $p->accessToken();

        $this->assertCount(2, $t->requests);
    }

    public function testAnAdvisoryWindowFailureIsSurvivable(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $t->queueThrow(new ConnectionException('token endpoint 503'));
        $p = $this->provider($t, $this->freshSource());

        $first = $p->accessToken();
        $this->clock += self::LIFETIME - WorkloadCredentialProvider::ADVISORY_REFRESH_SECONDS + 10;

        $served = null;
        $warnings = $this->captureWarnings(function () use ($p, &$served): void {
            $served = $p->accessToken();
        });

        $this->assertSame(
            $first->expose(),
            $served->expose(),
            'a survivable blip took down a caller with valid credentials',
        );
        // Survivable is not silent: the operator must still learn the token
        // endpoint is failing, or the first symptom is the mandatory-tier throw.
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('advisory token refresh failed', $warnings[0]);
        $this->assertStringContainsString('token endpoint 503', $warnings[0]);
    }

    public function testAMandatoryWindowFailureThrows(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $t->queueThrow(new ConnectionException('token endpoint 503'));
        $p = $this->provider($t, $this->freshSource());

        $p->accessToken();
        $this->clock += self::LIFETIME - WorkloadCredentialProvider::MANDATORY_REFRESH_SECONDS + 10;

        $this->expectException(ConnectionException::class);
        $p->accessToken();
    }

    // -- what reaches the exchange -------------------------------------------

    public function testPassesResourceAndAudienceThroughAndTheTestDataSpace(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider(
            $t,
            $this->freshSource(),
            ['resource' => 'https://mcp.example/servers/s1', 'audience' => 'knoxcall:gateway'],
            ['sandbox' => true],
        );

        $p->accessToken();

        $sent = $this->sent($t, 0);
        $this->assertSame('https://mcp.example/servers/s1', $sent['resource']);
        $this->assertSame('knoxcall:gateway', $sent['audience']);
        $this->assertSame('urn:ietf:params:oauth:token-type:id_token', $sent['subject_token_type']);
        // sandbox must reach the HOST, not just the payload.
        $this->assertSame('https://sandbox-acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testResourceIsOmittedWhenNotAskedFor(): void
    {
        // Sending resource="" would be refused invalid_target; sending nothing
        // mints an unconfined agent token. The provider must not turn one into
        // the other.
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider($t, $this->freshSource());

        $p->accessToken();
        $this->assertArrayNotHasKey('resource', $this->sent($t, 0));
    }

    public function testTheTokenIsRedacted(): void
    {
        $t = new MockTransport();
        $this->queueToken($t, 'kp_live_tok1');
        $p = $this->provider($t, $this->freshSource());

        $token = $p->accessToken();
        $this->assertStringNotContainsString('kp_live', (string) $token);
        $this->assertStringNotContainsString('kp_live', print_r($token, true));
        $this->assertSame('kp_live_tok1', $token->expose());
    }

    public function testANonCallableAssertionSourceIsRefusedAtConstruction(): void
    {
        // The failure belongs at construction, not minutes into a long process.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/single-use/');
        /** @phpstan-ignore-next-line deliberate misuse */
        new WorkloadCredentialProvider('not-a-callable', [], ['tenant' => 'acme']);
    }
}
