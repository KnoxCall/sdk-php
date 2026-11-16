<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\PermissionDeniedException;
use KnoxCall\Warn;

/**
 * In-memory aggregation of uncovered-egress observations for ONE pipeline
 * (PARITY §21.3), flushed to POST /v1/wrap/egress-observations. Bounded;
 * never throws into the application's request.
 *
 * PHP has no background thread and most processes live for one request, so
 * the "jittered 60 s timer" of the other SDKs is LAZY here (the first record
 * after the interval flushes — one management call every ~60 s on a busy
 * worker), the 200-key ceiling flushes at once, {@see stop()} flushes once
 * more, and a shutdown function flushes whatever a short-lived process still
 * holds. Every flush is synchronous and costs one management call; it happens
 * at most once per interval, per 200 distinct keys, or at shutdown — never on
 * the ordinary request path.
 */
final class EgressObservationReporter
{
    public const DEFAULT_FLUSH_INTERVAL = 60.0;
    public const DEFAULT_FLUSH_AT_KEYS = 200;
    public const DEFAULT_MAX_KEYS = 1000;
    public const DEFAULT_MAX_PER_REQUEST = 200;

    /** @var \Closure(list<array<string, mixed>>): array<string, mixed> */
    private \Closure $report;
    /** @var \Closure(array{accepted: int, dropped: int}): void|null */
    private ?\Closure $onFlush;
    /** @var \Closure(): float */
    private \Closure $now;
    /** @var \Closure(): float */
    private \Closure $rand;

    /** @var array<string, array{host: string, first_segment: string, method: string, header_name: string, count: int, first_seen: float, last_seen: float}> */
    private array $buffer = [];
    private ?float $dueAt = null;
    private bool $flushing = false;
    private bool $stopped = false;
    private bool $forbidden = false;
    private bool $warnedOverflow = false;
    private bool $warnedFailed = false;

    /** Live reporters, flushed at shutdown (weak: a reporter dies with its pipeline). */
    private static ?\WeakMap $live = null;
    private static bool $shutdownRegistered = false;

    /**
     * @param \Closure(list<array<string, mixed>>): array<string, mixed> $report performs the POST with at most $maxPerRequest observations
     * @param \Closure(array{accepted: int, dropped: int}): void|null $onFlush after each accepted report (never per observation)
     * @param \Closure(): float|null $now test seam (unix seconds)
     * @param \Closure(): float|null $rand test seam, in [0, 1)
     */
    public function __construct(
        \Closure $report,
        ?\Closure $onFlush = null,
        private readonly float $flushInterval = self::DEFAULT_FLUSH_INTERVAL,
        private readonly int $flushAtKeys = self::DEFAULT_FLUSH_AT_KEYS,
        private readonly int $maxKeys = self::DEFAULT_MAX_KEYS,
        private readonly int $maxPerRequest = self::DEFAULT_MAX_PER_REQUEST,
        ?\Closure $now = null,
        ?\Closure $rand = null,
        private readonly bool $flushOnShutdown = true,
    ) {
        $this->report = $report;
        $this->onFlush = $onFlush;
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->rand = $rand ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    /** Distinct keys currently held. */
    public function size(): int
    {
        return count($this->buffer);
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    /** True once the endpoint answered 403: reporting is off for the life of this reporter. */
    public function forbidden(): bool
    {
        return $this->forbidden;
    }

    /**
     * The observations that would be sent now (a copy, in first-seen order).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return array_values(array_map([self::class, 'wire'], $this->buffer));
    }

    /**
     * Record one uncovered credentialed call. Never throws.
     *
     * @param array{host: string, first_segment: string, method: string, header_name: string} $obs
     */
    public function record(array $obs): void
    {
        try {
            if ($this->stopped || $this->forbidden) {
                return;
            }
            $key = $obs['host'] . "\0" . $obs['first_segment'] . "\0" . $obs['method'] . "\0" . $obs['header_name'];
            $at = ($this->now)();
            if (isset($this->buffer[$key])) {
                $this->buffer[$key]['count']++;
                $this->buffer[$key]['last_seen'] = $at;
                $this->flushIfDue($at);
                return;
            }
            if (count($this->buffer) >= $this->maxKeys) {
                if (!$this->warnedOverflow) {
                    $this->warnedOverflow = true;
                    Warn::warnOnce(
                        'KNOXCALL_EGRESS_OBSERVATIONS_OVERFLOW',
                        "KnoxCall: more than {$this->maxKeys} distinct uncovered-egress observations are pending; "
                        . 'new ones are dropped until the next flush.'
                    );
                }
                $this->flushIfDue($at);
                return;
            }
            if ($this->buffer === []) {
                // The lazy timer: arm it on the first record after an empty buffer.
                $jitter = 1 + (($this->rand)() * 0.2 - 0.1); // ±10 %
                $this->dueAt = $at + max(0.001, $this->flushInterval * $jitter);
                if ($this->flushOnShutdown) {
                    self::registerForShutdown($this);
                }
            }
            $this->buffer[$key] = [
                'host' => $obs['host'], 'first_segment' => $obs['first_segment'], 'method' => $obs['method'],
                'header_name' => $obs['header_name'], 'count' => 1, 'first_seen' => $at, 'last_seen' => $at,
            ];
            if (count($this->buffer) >= $this->flushAtKeys) {
                $this->flush();
                return;
            }
            $this->flushIfDue($at);
        } catch (\Throwable) {
            // best-effort: telemetry must never reach the application's request.
        }
    }

    /**
     * Send what is pending now. A re-entrant flush returns immediately. Never
     * throws: a 403 ends reporting for good (warned once); any other failure
     * drops the batch (warned once) and is never retried in a loop.
     */
    public function flush(): void
    {
        if ($this->flushing || $this->forbidden || $this->buffer === []) {
            return;
        }
        $this->flushing = true;
        $batch = $this->pending();
        $this->buffer = [];
        $this->dueAt = null;
        try {
            foreach (array_chunk($batch, $this->maxPerRequest) as $chunk) {
                try {
                    $res = ($this->report)($chunk);
                } catch (PermissionDeniedException) {
                    // The key lacks routes:read: reporting is off for good.
                    $this->forbidden = true;
                    $this->buffer = [];
                    Warn::warnOnce(
                        'KNOXCALL_EGRESS_OBSERVATIONS_FORBIDDEN',
                        'KnoxCall: the credential cannot report uncovered-egress observations (HTTP 403 — it lacks '
                        . 'routes:read); reporting is off for this client. Grant the scope, or pass '
                        . '["observe_uncovered" => false] to silence this.'
                    );
                    return;
                } catch (\Throwable $e) {
                    // best-effort: the batch is dropped, never retried in a loop,
                    // never surfaced into the application's own request.
                    if (!$this->warnedFailed) {
                        $this->warnedFailed = true;
                        Warn::warnOnce(
                            'KNOXCALL_EGRESS_OBSERVATIONS_FAILED',
                            'KnoxCall: reporting uncovered-egress observations failed (' . $e::class . ': '
                            . $e->getMessage() . '); the batch was dropped.'
                        );
                    }
                    return;
                }
                if ($this->onFlush !== null) {
                    try {
                        ($this->onFlush)([
                            'accepted' => (int) ($res['accepted'] ?? 0),
                            'dropped' => (int) ($res['dropped'] ?? 0),
                        ]);
                    } catch (\Throwable) {
                        // A caller's hook must never break the reporter.
                    }
                }
            }
        } finally {
            $this->flushing = false;
        }
    }

    /** Stop the lazy timer and flush once more. Idempotent. */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->dueAt = null;
        $this->flush();
    }

    private function flushIfDue(float $at): void
    {
        if ($this->dueAt !== null && $at >= $this->dueAt) {
            $this->flush();
        }
    }

    private static function registerForShutdown(self $reporter): void
    {
        self::$live ??= new \WeakMap();
        self::$live[$reporter] = true;
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$live ?? [] as $r => $_) {
                try {
                    if (!$r->stopped) {
                        $r->flush();
                    }
                } catch (\Throwable) {
                    // best-effort: never fail a shutdown.
                }
            }
        });
    }

    /**
     * @param array{host: string, first_segment: string, method: string, header_name: string, count: int, first_seen: float, last_seen: float} $e
     * @return array{host: string, first_segment: string, method: string, header_name: string, count: int, first_seen: string, last_seen: string}
     */
    private static function wire(array $e): array
    {
        return [
            'host' => $e['host'],
            'first_segment' => $e['first_segment'],
            'method' => $e['method'],
            'header_name' => $e['header_name'],
            'count' => $e['count'],
            'first_seen' => self::iso($e['first_seen']),
            'last_seen' => self::iso($e['last_seen']),
        ];
    }

    private static function iso(float $ts): string
    {
        $seconds = (int) floor($ts);
        $millis = (int) floor(($ts - $seconds) * 1000);
        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }
}
