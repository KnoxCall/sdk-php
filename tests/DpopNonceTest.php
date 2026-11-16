<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\DpopNonce;
use KnoxCall\KnoxCall;
use PHPUnit\Framework\TestCase;

/**
 * The DPoP server nonce (RFC 9449 §8) — PARITY §7.1, client Phase 4.3.
 *
 * Four behaviours, and the fourth is the one that is easy to get wrong:
 *
 *  1. remember the most recent DPoP-Nonce per ORIGIN;
 *  2. send it as the proof's nonce claim on every later request there;
 *  3. on the server's "use a nonce" refusal, re-sign ONCE with the nonce that
 *     refusal carried, with a new jti, and never loop;
 *  4. that refusal is NOT an authentication failure. A 401 normally purges the
 *     cached token and spends the one re-auth; a use_dpop_nonce 401 must do
 *     neither, or every nonce rotation costs a token mint — and the retry would
 *     then succeed anyway, which is what would hide it.
 *
 * Capture is at the transport boundary (MockTransport records every request),
 * so these drive the real signer, the real store and the real retry loops.
 */
final class DpopNonceTest extends TestCase
{
    /** @return array<string, mixed> the proof JWT's claims */
    private static function claims(string $proof): array
    {
        $parts = explode('.', $proof);
        self::assertCount(3, $parts, 'the proof is not a JWT');
        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        self::assertIsString($decoded);
        $claims = json_decode($decoded, true);
        self::assertIsArray($claims);
        return $claims;
    }

    private static function client(MockTransport $t): KnoxCall
    {
        return new KnoxCall([
            'tenant' => 'acme',
            'base_url' => 'https://api.test',
            'proxy_base_url' => 'https://acme.test',
            'client_id' => 'tk_id',
            'client_secret' => 'shh',
            'dpop' => 'always',
            'transport' => $t,
            'retry_base_delay_ms' => 1,
        ]);
    }

    private static function queueDpopToken(MockTransport $t, ?string $nonce = null): void
    {
        $t->queueJson(
            200,
            ['access_token' => 'kc_at_x', 'token_type' => 'DPoP', 'expires_in' => 3600],
            $nonce !== null ? ['dpop-nonce' => $nonce] : [],
        );
    }

    /** @return list<array<string, mixed>> the proofs sent to URLs containing $needle */
    private static function proofs(MockTransport $t, string $needle = ''): array
    {
        $out = [];
        foreach ($t->requests as $r) {
            if ($needle !== '' && !str_contains($r['url'], $needle)) {
                continue;
            }
            // Case-insensitive: the SDK sets 'DPoP' on the management path and
            // through setHeader() on the data plane, and a test that assumed one
            // spelling would pass or fail on which path it happened to exercise.
            $proof = null;
            foreach ($r['headers'] as $name => $value) {
                if (strcasecmp((string) $name, 'DPoP') === 0) {
                    $proof = $value;
                    break;
                }
            }
            if (is_string($proof) && $proof !== '') {
                $out[] = self::claims($proof);
            }
        }
        return $out;
    }

    private static function mintCount(MockTransport $t): int
    {
        $n = 0;
        foreach ($t->requests as $r) {
            if (str_contains($r['url'], '/oauth/token')) {
                $n++;
            }
        }
        return $n;
    }

    // -- the store -------------------------------------------------------------

    public function testOriginDropsPathQueryAndDefaultPorts(): void
    {
        self::assertSame('https://api.knoxcall.com', DpopNonce::originOf('https://api.knoxcall.com/v1/routes'));
        self::assertSame('https://api.knoxcall.com', DpopNonce::originOf('https://api.knoxcall.com/v1/routes?a=1#f'));
        self::assertSame('https://api.knoxcall.com', DpopNonce::originOf('https://API.KnoxCall.com/v1'));
        self::assertSame('http://localhost:3000', DpopNonce::originOf('http://localhost:3000/v1'));
        self::assertSame('https://api.knoxcall.com:8443', DpopNonce::originOf('https://api.knoxcall.com:8443/v1'));
        self::assertNull(DpopNonce::originOf('/just/a/path'));
        self::assertNull(DpopNonce::originOf(''));
    }

    public function testStoreIsKeyedByOriginNotByUrl(): void
    {
        $s = new DpopNonce();
        $s->remember('https://api.knoxcall.com/oauth/token', ['dpop-nonce' => 'A']);
        self::assertSame('A', $s->get('https://api.knoxcall.com/v1/routes?x=1'));
        // A DIFFERENT origin has not answered yet. The management API and a
        // tenant's data plane issue different values; sending one to the other
        // would be refused.
        self::assertNull($s->get('https://acme.knoxcall.com/api/anything'));
        $s->remember('https://acme.knoxcall.com/api/x', ['dpop-nonce' => 'B']);
        self::assertSame('B', $s->get('https://acme.knoxcall.com/api/y'));
        self::assertSame('A', $s->get('https://api.knoxcall.com/v1/routes'));
    }

    public function testLastWriteWinsAndBlanksNeverErase(): void
    {
        $s = new DpopNonce();
        $s->remember('https://api.test/x', ['dpop-nonce' => 'first']);
        $s->remember('https://api.test/x', ['dpop-nonce' => 'second']);
        foreach ([[], ['dpop-nonce' => ''], ['dpop-nonce' => '   '], null] as $headers) {
            self::assertNull($s->remember('https://api.test/x', $headers));
            self::assertSame('second', $s->get('https://api.test/x'));
        }
    }

    public function testHostlessUrlIsNotRemembered(): void
    {
        $s = new DpopNonce();
        self::assertNull($s->remember('/just/a/path', ['dpop-nonce' => 'x']));
        self::assertSame([], $s->snapshot());
    }

    // -- the refusal predicate -------------------------------------------------

    public function testRecognisesBothSurfaces(): void
    {
        // The authorization server's 400 + flat OAuth body.
        self::assertTrue(DpopNonce::isRefusal(400, 'use_dpop_nonce'));
        // A resource server from the challenge header ALONE: the tenant data
        // plane keeps its deliberately opaque body, so that is the only signal.
        self::assertTrue(DpopNonce::isRefusal(401, null, ['www-authenticate' => 'DPoP error="use_dpop_nonce"']));
        self::assertTrue(DpopNonce::isRefusal(401, null, ['www-authenticate' => 'DPoP ERROR="USE_DPOP_NONCE"']));
    }

    public function testNothingElseIsANonceRefusal(): void
    {
        self::assertFalse(DpopNonce::isRefusal(401, 'invalid_token'));
        self::assertFalse(DpopNonce::isRefusal(401, null, ['www-authenticate' => 'DPoP error="invalid_token"']));
        self::assertFalse(DpopNonce::isRefusal(403, 'use_dpop_nonce'));
        self::assertFalse(DpopNonce::isRefusal(500, 'use_dpop_nonce'));
        self::assertFalse(DpopNonce::isRefusal(200));
    }

    // -- end to end ------------------------------------------------------------

    public function testMintNonceIsSentOnTheNextCall(): void
    {
        $t = new MockTransport();
        self::queueDpopToken($t, 'NONCE-1');
        $t->queueJson(200, ['data' => [], 'meta' => []], ['dpop-nonce' => 'NONCE-2']);
        $t->queueJson(200, ['data' => [], 'meta' => []]);

        $client = self::client($t);
        $client->routes->list();
        $client->routes->list();

        $proofs = self::proofs($t);
        self::assertCount(3, $proofs);
        // The very first proof — the mint — has no nonce to send, and the
        // server accepts that.
        self::assertArrayNotHasKey('nonce', $proofs[0]);
        self::assertSame('NONCE-1', $proofs[1]['nonce'] ?? null);
        self::assertSame('NONCE-2', $proofs[2]['nonce'] ?? null);
    }

    public function testResourceServerRefusalReSignsOnceAndDoesNotReMint(): void
    {
        $t = new MockTransport();
        self::queueDpopToken($t, 'STALE');
        $t->queueJson(
            401,
            ['error' => ['type' => 'use_dpop_nonce', 'message' => 'nonce required']],
            ['www-authenticate' => 'DPoP error="use_dpop_nonce"', 'dpop-nonce' => 'FRESH'],
        );
        $t->queueJson(200, ['data' => [], 'meta' => []]);

        self::client($t)->routes->list();

        $calls = self::proofs($t, '/v1/routes');
        self::assertCount(2, $calls, 'exactly one retry');
        self::assertSame('STALE', $calls[0]['nonce'] ?? null);
        self::assertSame('FRESH', $calls[1]['nonce'] ?? null, 'the retry must use the nonce the refusal carried');
        self::assertNotSame($calls[0]['jti'] ?? null, $calls[1]['jti'] ?? null, 'a new jti is required');
        // Behaviour 4.
        self::assertSame(1, self::mintCount($t), 'the nonce refusal spent a token mint');
    }

    public function testRealUnauthorizedStillReMints(): void
    {
        $t = new MockTransport();
        self::queueDpopToken($t);
        $t->queueJson(401, ['error' => ['type' => 'invalid_token', 'message' => 'nope']]);
        self::queueDpopToken($t);
        $t->queueJson(200, ['data' => [], 'meta' => []]);

        self::client($t)->routes->list();
        self::assertSame(2, self::mintCount($t), 'a real 401 must still purge and re-mint once');
    }

    public function testTheMintIsReSignedOnTheAuthorizationServers400(): void
    {
        // WHAT THIS DOES AND DOES NOT PIN. It pins that the mint is re-signed
        // with the nonce and succeeds. It does NOT pin WHICH layer did it:
        // unlike python, node, go and ruby -- which each expose a token-only
        // entry point (`authenticate` / `token` / `getToken`) and therefore have
        // a test that isolates the mint-level retry -- PHP exposes no such
        // method, so the request loop's own nonce branch (which also matches a
        // 400) could satisfy this test on its own. The two are indistinguishable
        // here even by request sequence, because the refusal happens during
        // getToken, before any API request is made. Stated rather than left as
        // an implied equivalence; if PHP ever gains an `authenticate`, this
        // should become the isolating test the other four have.
        $t = new MockTransport();
        $t->queueJson(
            400,
            ['error' => 'use_dpop_nonce', 'error_description' => 'nonce required'],
            ['dpop-nonce' => 'FRESH'],
        );
        self::queueDpopToken($t, 'FRESH');
        $t->queueJson(200, ['data' => [], 'meta' => []]);

        self::client($t)->routes->list();

        $mints = self::proofs($t, '/oauth/token');
        self::assertCount(2, $mints, 'exactly one re-sign of the mint');
        self::assertArrayNotHasKey('nonce', $mints[0]);
        self::assertSame('FRESH', $mints[1]['nonce'] ?? null);
        self::assertNotSame($mints[0]['jti'] ?? null, $mints[1]['jti'] ?? null);
    }

    public function testDataPlaneRetriesFromTheHeaderAlone(): void
    {
        $t = new MockTransport();
        self::queueDpopToken($t, 'STALE');
        $t->queueRaw(
            401,
            'Unauthorized',
            ['www-authenticate' => 'DPoP error="use_dpop_nonce"', 'dpop-nonce' => 'FRESH'],
        );
        $t->queueJson(200, ['ok' => true]);

        $response = self::client($t)->call('my-route', ['path' => '/v1/things']);
        self::assertSame(200, $response['status']);

        $calls = self::proofs($t, 'acme.test');
        self::assertCount(2, $calls);
        // The data plane is a DIFFERENT ORIGIN from the management API, so the
        // nonce the mint returned is correctly NOT sent here: this origin has
        // not answered yet. The retry uses the one its own refusal carried.
        self::assertArrayNotHasKey('nonce', $calls[0]);
        self::assertSame('FRESH', $calls[1]['nonce'] ?? null);
        self::assertSame(1, self::mintCount($t));
    }

    public function testUpstreamAnsweredIsNotANonceRefusal(): void
    {
        $t = new MockTransport();
        self::queueDpopToken($t);
        $t->queueRaw(
            401,
            'upstream says no',
            ['x-knox-upstream-status' => '401', 'www-authenticate' => 'DPoP error="use_dpop_nonce"'],
        );

        $response = self::client($t)->call('my-route', ['path' => '/v1/things']);
        self::assertSame(401, $response['status']);
        self::assertCount(1, self::proofs($t, 'acme.test'), 'no retry on an upstream answer');
    }
}
