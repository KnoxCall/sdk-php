<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\ApiException;
use KnoxCall\Warn;

/**
 * The SDK-side copy of the intercept manifest — fetched, held, refreshed
 * (route-aware-interception-plan.md §2.5; PARITY §21.1).
 *
 * PHP idiom: a process usually lives for ONE web request, so the manifest is
 * refreshed LAZILY at its TTL (the first request after `ttl_seconds` pays one
 * management call) and, optionally, shared across processes through a PSR-16
 * cache (`['cache' => $simpleCache]` — APCu, Redis, …). With a cache, a warm
 * process needs no management call at all inside the TTL; without one, each
 * new process fetches once, then holds the manifest for the TTL.
 *
 * - stale-but-valid: a failed refresh keeps the last GOOD manifest and backs
 *   off exponentially from the second consecutive failure (cap 8×TTL);
 * - a 401/403/404 from the manifest endpoint — the credential lacks
 *   `routes:read`, or an older server — is NOT a routing failure: the store
 *   warns once, behaves as "no manifest" (every listed host stays on the
 *   ephemeral path exactly as before this feature), and re-checks at 10×TTL;
 * - out-of-cycle refreshes (a route-mode refusal, a promoted-route hint, an
 *   explicit refresh) are rate-limited so a burst costs one call.
 *
 * Discovery failing open is deliberate and bounded: it can only leave a host
 * on the path it was on before the manifest existed. The DATA-PLANE hop is
 * where fail-closed lives (D4), and that is in {@see InterceptPipeline}.
 */
final class InterceptManifestStore
{
    public const DEFAULT_TTL_SECONDS = 60;
    public const MAX_BACKOFF_FACTOR = 8;
    public const PERMISSION_RECHECK_FACTOR = 10;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;
    private ?string $version = null;
    private float $expiresAt = 0.0; // stale until the first refresh
    private float $lastRefreshAt = -1e9;
    private int $failures = 0;
    private bool $permissionDenied = false;
    private ?\Throwable $lastError = null;
    private bool $stopped = false;
    private bool $cacheChecked = false;

    /** @var \Closure(): float */
    private \Closure $now;

    /**
     * Whether `$fetch` takes the held version. Decided once at construction
     * (a zero-parameter test/user seam keeps polling unconditionally).
     */
    private readonly bool $fetchConditional;

    /**
     * @param \Closure(?string=): (array<string, mixed>|null) $fetch performs GET /v1/wrap/intercept-manifest;
     *        receives the version the store holds (null on the first poll) to send as
     *        `If-None-Match: W/"<version>"`, and returns null for the server's 304 — the store
     *        then keeps its manifest, restarts the TTL clock and fires no `$onRefresh`
     *        (PARITY §21.1 "Conditional poll"). A zero-parameter closure polls unconditionally.
     * @param (callable(array{reason: string, version: string, added: list<array<string, mixed>>, removed: list<array<string, mixed>>}): void)|null $onRefresh
     * @param (callable(\Throwable): void)|null $onError
     * @param float $minRefreshGap seconds between out-of-cycle refreshes
     * @param (\Closure(): float)|null $now the clock (monotonic seconds); a test seam
     * @param object|null $cache a PSR-16 `CacheInterface`-shaped object (`get`/`set`), shared across processes
     * @param string $cacheKey the cache key for this client + environment
     */
    public function __construct(
        private readonly \Closure $fetch,
        private $onRefresh = null,
        private $onError = null,
        private float $minRefreshGap = 5.0,
        ?\Closure $now = null,
        private readonly ?object $cache = null,
        private readonly string $cacheKey = '',
    ) {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
        $this->fetchConditional = (new \ReflectionFunction($fetch))->getNumberOfParameters() > 0;
        if ($cache !== null && (!method_exists($cache, 'get') || !method_exists($cache, 'set'))) {
            throw new \InvalidArgumentException('wrap intercept cache must be a PSR-16 CacheInterface (get/set).');
        }
    }

    /** @return array<string, mixed>|null the last good manifest, or null before the first success / after a refusal */
    public function manifest(): ?array
    {
        return $this->manifest;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function permissionDenied(): bool
    {
        return $this->permissionDenied;
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    /** Seconds between out-of-cycle refreshes; a burst inside the gap costs one call. */
    public function setMinRefreshGap(float $seconds): void
    {
        $this->minRefreshGap = $seconds;
    }

    /** Whether the next request should refresh before deciding. */
    public function stale(): bool
    {
        return !$this->stopped && ($this->now)() >= $this->expiresAt;
    }

    /** A promoted-route hint arrived: make the NEXT request refresh (rate-limited). */
    public function hint(): void
    {
        if (($this->now)() - $this->lastRefreshAt >= $this->minRefreshGap) {
            $this->expiresAt = ($this->now)();
        }
    }

    /** Drop the manifest and refuse further refreshes. */
    public function stop(): void
    {
        $this->stopped = true;
        $this->manifest = null;
        $this->version = null;
    }

    /**
     * Refresh if stale (first load, TTL expiry, a hint), then return the
     * manifest. A shared cache is consulted before the first fetch.
     *
     * @return array<string, mixed>|null
     */
    public function ensure(): ?array
    {
        if ($this->stopped) {
            return null;
        }
        if ($this->manifest === null && !$this->cacheChecked) {
            $this->cacheChecked = true;
            $this->adoptFromCache();
        }
        if ($this->stale()) {
            $this->refresh('ttl', true);
        }
        return $this->manifest;
    }

    /**
     * Refresh now. Rate-limited unless `$force`. Never throws for a fetch
     * failure — the store has already applied stale-keep / backoff; read
     * {@see lastError()}.
     *
     * @return array<string, mixed>|null
     */
    public function refresh(string $reason, bool $force = false): ?array
    {
        if ($this->stopped) {
            return null;
        }
        if (!$force && ($this->now)() - $this->lastRefreshAt < $this->minRefreshGap) {
            return $this->manifest;
        }
        $this->lastRefreshAt = ($this->now)();
        try {
            // Every poll after the first is conditional on the held version; the
            // server answers 304 (→ null) when nothing changed, and that is a
            // success: keep the manifest, restart the TTL clock, fire no hook.
            $next = $this->fetchConditional && $this->version !== null
                ? ($this->fetch)($this->version)
                : ($this->fetch)();
        } catch (\Throwable $e) {
            $this->lastError = $e;
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
            $status = $e instanceof ApiException ? $e->statusCode : null;
            if ($status === 401 || $status === 403 || $status === 404) {
                // Not a routing failure: the credential cannot read routes, or the
                // server predates the manifest. Every listed host stays ephemeral,
                // as it was before this feature existed. Warn once, re-check slowly.
                $this->permissionDenied = true;
                $this->manifest = null;
                $this->version = null;
                Warn::warnOnce(
                    'KNOXCALL_INTERCEPT_MANIFEST_UNAVAILABLE',
                    "KnoxCall intercept manifest unavailable (HTTP {$status}): route-aware interception is off for this "
                    . 'client — listed hosts use the ephemeral proxy. Grant the credential `routes:read` (or upgrade the '
                    . 'server) to enable it.'
                );
                $this->expiresAt = ($this->now)() + self::DEFAULT_TTL_SECONDS * self::PERMISSION_RECHECK_FACTOR;
            } else {
                // Transport or server fault: keep the last good manifest, back off
                // from the second consecutive failure.
                $this->failures = min($this->failures + 1, 30);
                $factor = min(2 ** ($this->failures - 1), self::MAX_BACKOFF_FACTOR);
                $base = self::ttlOf($this->manifest);
                $this->expiresAt = ($this->now)() + min($base * $factor, $base * self::MAX_BACKOFF_FACTOR);
            }
            return $this->manifest;
        }

        if ($next === null) {
            // Not modified: the held manifest stands for another TTL — in this
            // process, and in the shared cache's record for the next one.
            $this->failures = 0;
            $this->permissionDenied = false;
            $this->lastError = null;
            $this->expiresAt = ($this->now)() + self::ttlOf($this->manifest);
            if ($this->manifest !== null) {
                $this->writeCache($this->manifest);
            }
            return $this->manifest;
        }

        $this->adopt($next, $reason);
        $this->expiresAt = ($this->now)() + self::ttlOf($next);
        $this->writeCache($next);
        return $this->manifest;
    }

    /**
     * Take a freshly fetched (or cached) manifest, reporting the diff.
     *
     * @param array<string, mixed> $next
     */
    private function adopt(array $next, string $reason): void
    {
        $prev = $this->manifest;
        $this->failures = 0;
        $this->permissionDenied = false;
        $this->lastError = null;
        $version = (string) ($next['version'] ?? '');
        if ($prev === null || $this->version !== $version) {
            $before = self::keyed($prev['routes'] ?? []);
            $after = self::keyed($next['routes'] ?? []);
            $added = array_values(array_diff_key($after, $before));
            $removed = array_values(array_diff_key($before, $after));
            $this->manifest = $next;
            $this->version = $version;
            if ($this->onRefresh !== null && ($added !== [] || $removed !== [] || $prev === null)) {
                ($this->onRefresh)(['reason' => $reason, 'version' => $version, 'added' => $added, 'removed' => $removed]);
            }
        }
    }

    /** A cached copy from another process, still inside its TTL, needs no call. */
    private function adoptFromCache(): void
    {
        if ($this->cache === null || $this->cacheKey === '') {
            return;
        }
        try {
            $record = $this->cache->get($this->cacheKey);
        } catch (\Throwable) {
            return; // best-effort: a cache fault is never a routing failure
        }
        if (!is_array($record) || !is_array($record['manifest'] ?? null) || !is_numeric($record['fetched_at'] ?? null)) {
            return;
        }
        $age = microtime(true) - (float) $record['fetched_at'];
        $ttl = self::ttlOf($record['manifest']);
        if ($age < 0 || $age >= $ttl) {
            return;
        }
        $this->adopt($record['manifest'], 'cache');
        $this->lastRefreshAt = ($this->now)() - $age;
        $this->expiresAt = ($this->now)() + ($ttl - $age);
    }

    /** @param array<string, mixed> $manifest */
    private function writeCache(array $manifest): void
    {
        if ($this->cache === null || $this->cacheKey === '') {
            return;
        }
        try {
            $this->cache->set($this->cacheKey, ['manifest' => $manifest, 'fetched_at' => microtime(true)], (int) self::ttlOf($manifest));
        } catch (\Throwable) {
            // best-effort: the in-process copy is authoritative; a cache fault only costs the next process one call
        }
    }

    /** @param array<string, mixed>|null $manifest */
    private static function ttlOf(?array $manifest): float
    {
        $ttl = $manifest['ttl_seconds'] ?? null;
        return is_numeric($ttl) && (float) $ttl > 0 ? (float) $ttl : (float) self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @param mixed $routes
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(mixed $routes): array
    {
        $out = [];
        foreach (is_array($routes) ? $routes : [] as $e) {
            if (is_array($e)) {
                $out[(string) ($e['host'] ?? '') . "\t" . (string) ($e['base_path'] ?? '') . "\t" . (string) ($e['slug'] ?? '')] = $e;
            }
        }
        return $out;
    }
}
