<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\KnoxCall;
use KnoxCall\Redacted;
use KnoxCall\StaleAssertionException;
use KnoxCall\Warn;

/**
 * Workload-identity credential provider — WIF plan Phase 4.3.
 *
 * Mirrors `auth/workload-provider.ts` in the Node SDK; `sdk/PARITY.md` is the
 * authoritative contract for every language.
 *
 * {@see KnoxCall::exchangeToken()} is one-shot: it trades one OIDC assertion
 * for one capability token and hands the caller an `expires_in` to manage. That
 * is fine for a script that makes one call and exits, and wrong for anything
 * long-lived — a queue worker, a long CI job, a Swoole/RoadRunner process —
 * where the token silently expires mid-run and the caller discovers it as a 401
 * they then have to interpret.
 *
 * This provider owns that lifecycle: cache the token, refresh it before it
 * dies, and never hand out one that is about to expire.
 *
 * THE PART THAT IS NOT LIKE OTHER REFRESH LOOPS
 *
 * A KnoxCall workload assertion is SINGLE-USE. The exchange spends the whole
 * assertion — the server claims a hash of it before minting (WIF Phase 1.2), so
 * presenting the same bytes twice is refused with "subject_token has already
 * been exchanged". A refresh therefore cannot re-send the assertion it used last
 * time; it needs a FRESH one from the platform every single time.
 *
 * That makes the obvious implementation — capture the assertion once, reuse it
 * on refresh — not merely suboptimal but broken, and broken in a way that only
 * shows up when the first refresh fires, i.e. minutes into production rather
 * than in anyone's smoke test. So the provider takes a SOURCE it calls before
 * every exchange, and refuses to send an assertion whose bytes it has already
 * spent ({@see StaleAssertionException}). It fails loudly at the real cause
 * rather than forwarding a doomed request and surfacing the server's replay
 * refusal, which reads as "my credentials were rejected".
 *
 * THE TWO-TIER SCHEDULE
 *
 * ADVISORY (expiry − 120s): refresh opportunistically. If it fails, the token in
 * hand is still valid, so the caller is served and the failure is a warning, not
 * an exception. A transient blip near a refresh boundary must not take down a
 * worker that has two minutes of perfectly good credential left.
 *
 * MANDATORY (expiry − 30s): refresh or throw. Below this line the token may die
 * in flight — between the provider handing it over and the request reaching the
 * server — and a 401 from an expired capability token is exactly the confusing
 * failure this provider exists to prevent.
 *
 * CONCURRENCY. PHP-FPM serves one request per process, so a provider built
 * per-request caches nothing across requests and the single-flight problem the
 * other SDKs solve with a lock cannot arise. It is worth having anyway for the
 * long-lived PHP runtimes — queue workers, Swoole, RoadRunner, a daemon — which
 * is exactly where a token outliving its usefulness bites. A provider instance
 * is NOT shared between coroutines; give each worker its own.
 *
 *     $provider = new WorkloadCredentialProvider(
 *         fn (): string => file_get_contents($_ENV['AWS_WEB_IDENTITY_TOKEN_FILE']),
 *         [],
 *         ['tenant' => 'acme'],
 *     );
 *     $token = $provider->accessToken();          // Redacted
 *     $header = 'Bearer ' . $token->expose();
 */
final class WorkloadCredentialProvider
{
    /** Refresh opportunistically below this much remaining life; failure is survivable. */
    public const ADVISORY_REFRESH_SECONDS = 120;

    /** Refresh or throw below this much remaining life; the token may die in flight. */
    public const MANDATORY_REFRESH_SECONDS = 30;

    private ?Redacted $cached = null;

    private float $expiresAt = 0.0;

    /** @var array<string, true> sha256 of every assertion spent. Never the assertion. */
    private array $spent = [];

    /** @var callable(): float */
    private $clock;

    /**
     * @param callable(): string $assertion Called before EVERY exchange; must
     *        return a FRESH assertion each time. On GitHub Actions a fetch of
     *        ACTIONS_ID_TOKEN_REQUEST_URL; on EKS a read of the projected token
     *        file. Returning a value captured once at startup is the failure
     *        this provider detects rather than tolerates.
     * @param array{resource?: string, audience?: string} $input Forwarded to
     *        {@see KnoxCall::exchangeToken()}. Omit the 'resource' KEY entirely
     *        for an agent-kind token: an empty string is sent through and
     *        refused invalid_target, because dropping it silently would mint an
     *        UNCONFINED token while the caller believes it is confined.
     * @param array{tenant?: string, sandbox?: bool, base_url?: string, transport?: mixed, timeout_ms?: int, clock?: callable(): float} $opts
     *        Forwarded verbatim to {@see KnoxCall::exchangeToken()} — 'sandbox'
     *        in particular, because dropping it would send a Test-mode
     *        workload's assertion to the Live host, where it matches no binding.
     *        'clock' is a test seam and is never forwarded.
     */
    public function __construct(
        private $assertion,
        private array $input = [],
        private array $opts = [],
    ) {
        if (!is_callable($assertion)) {
            throw new \InvalidArgumentException(
                'WorkloadCredentialProvider needs a callable assertion source returning the '
                . "workload's CURRENT OIDC id_token. KnoxCall assertions are single-use, so it "
                . 'is called before every exchange.'
            );
        }
        $clock = $this->opts['clock'] ?? null;
        unset($this->opts['clock']);
        $this->clock = is_callable($clock) ? $clock : static fn (): float => microtime(true);
    }

    /**
     * A capability token with more than MANDATORY_REFRESH_SECONDS of life left.
     *
     * Returns {@see Redacted} so the token cannot reach a log through string
     * interpolation or var_dump — the same treatment every other credential in
     * this SDK gets.
     *
     * @throws StaleAssertionException when the source returns spent or empty bytes
     * @throws \KnoxCall\TokenExchangeException on a refusal in the mandatory window
     * @throws \KnoxCall\ConnectionException on a transport failure in the mandatory window
     */
    public function accessToken(): Redacted
    {
        $remaining = $this->cached !== null ? $this->expiresAt - ($this->clock)() : -1.0;

        if ($this->cached !== null && $remaining > self::ADVISORY_REFRESH_SECONDS) {
            return $this->cached;
        }

        if ($this->cached !== null && $remaining > self::MANDATORY_REFRESH_SECONDS) {
            // ADVISORY tier: try, but the token in hand is still good.
            try {
                return $this->refresh();
            } catch (\Throwable $e) {
                // best-effort: the caller still has a valid credential, and
                // throwing here would convert a survivable blip into an outage.
                // The MANDATORY tier throws for real if the condition persists.
                Warn::warnOnce(
                    'KNOXCALL_WORKLOAD_ADVISORY_REFRESH',
                    'KnoxCall: advisory token refresh failed (' . $e->getMessage()
                    . '); continuing with the current token, which expires in '
                    . (int) round($remaining) . 's'
                );

                return $this->cached;
            }
        }

        // MANDATORY tier, or nothing cached at all.
        return $this->refresh();
    }

    /** Exchange a fresh assertion and cache the result. */
    private function refresh(): Redacted
    {
        $assertion = ($this->assertion)();
        if (!is_string($assertion) || $assertion === '') {
            throw new StaleAssertionException(
                'the workload assertion source returned nothing. It must return the '
                . "workload's current OIDC id_token on every call."
            );
        }

        $fingerprint = hash('sha256', $assertion);
        if (isset($this->spent[$fingerprint])) {
            throw new StaleAssertionException(
                'the workload assertion source returned an assertion that has already been '
                . 'exchanged. KnoxCall assertions are single-use, so each refresh needs a '
                . "NEWLY minted one — call the platform's token endpoint inside the source "
                . '(for example re-fetch ACTIONS_ID_TOKEN_REQUEST_URL, or re-read the '
                . 'projected service-account token file) rather than capturing one value at '
                . 'startup.'
            );
        }

        $res = KnoxCall::exchangeToken(
            ['subject_token' => $assertion] + $this->input,
            $this->opts,
        );

        // Recorded only after the exchange returns, so a network failure does
        // not burn a fingerprint the caller could legitimately retry with. The
        // server claims the assertion before it mints, so a SUCCESS is what
        // makes those bytes unusable.
        $this->spent[$fingerprint] = true;

        $this->cached = new Redacted((string) $res['access_token']);
        $this->expiresAt = ($this->clock)() + (float) ($res['expires_in'] ?? 0);

        return $this->cached;
    }
}
