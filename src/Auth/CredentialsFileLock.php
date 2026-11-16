<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\KnoxCallException;

/**
 * Cross-process lock on the credentials file: a sibling `.lock` file held by
 * exclusive-create (fopen mode 'x' — the O_CREAT|O_EXCL equivalent).
 *
 * Protocol (identical in every SDK, PARITY §2): acquisition retries every
 * 100ms up to 10s; a lock file older than the stale window (60s) is broken and
 * retried once. The server's refresh tokens are SINGLE-USE with family
 * revocation on reuse, so two processes must never race the same refresh token.
 *
 * Ownership-aware break/release (hardened 2026-08): the lock file carries a
 * unique owner tag (`pid time nonce`) written at acquire and remembered on the
 * instance. A stale lock is broken by ATOMIC RENAME — rename() has exactly one
 * winner, so a competitor's freshly-created lock can never be deleted by path —
 * and release only unlinks a lock whose on-disk content still matches what this
 * instance wrote. Together these close the double-acquire → double-refresh race
 * that would replay the single-use refresh token and trip server-side family
 * revocation. The stale window (60s) is kept safely above the bounded refresh
 * HTTP timeout (KnoxCall::REFRESH_TIMEOUT_MS = 30s) so a live-but-slow refresh
 * is never mistaken for a dead holder. (A blind unlink-by-path on break or
 * release is a regression.)
 */
final class CredentialsFileLock
{
    private readonly string $lockPath;
    private bool $held = false;
    /** The exact bytes this instance wrote into the lock at acquire, or null. */
    private ?string $ownContent = null;

    public function __construct(
        string $target,
        private readonly float $timeoutSeconds = 10.0,
        private readonly float $retryIntervalSeconds = 0.1,
        // Must exceed the bounded refresh HTTP timeout
        // (KnoxCall::REFRESH_TIMEOUT_MS) so a legitimately in-flight refresh is
        // never broken as "stale".
        private readonly float $staleAfterSeconds = 60.0,
    ) {
        $this->lockPath = $target . '.lock';
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }

    private function tryAcquire(): bool
    {
        // Unique owner tag: pid + timestamp + nonce. The nonce guarantees no
        // two acquisitions (even same pid, same millisecond) ever collide, so
        // release() can prove the on-disk lock is still ours by exact match.
        $content = getmypid() . ' ' . sprintf('%.3F', microtime(true)) . ' ' . bin2hex(random_bytes(8)) . "\n";
        $fh = @fopen($this->lockPath, 'x'); // fails when the file already exists
        if ($fh === false) {
            return false;
        }
        fwrite($fh, $content);
        fclose($fh);
        $this->ownContent = $content;
        $this->held = true;
        return true;
    }

    /**
     * Break a stale lock via atomic rename, so a competing process's fresh
     * lock is never removed by path. True when a retry is worthwhile now.
     */
    private function breakStale(): bool
    {
        clearstatcache(true, $this->lockPath);
        $mtime = @filemtime($this->lockPath);
        if ($mtime === false) {
            return true; // lock vanished between attempts — retry immediately
        }
        if (microtime(true) - $mtime <= $this->staleAfterSeconds) {
            return false;
        }
        // Claim the break atomically: rename() has exactly one winner, so if a
        // competitor already broke-and-recreated the lock, our rename fails
        // (source gone) and we never touch their live lock.
        $graveyard = $this->lockPath . '.stale-' . getmypid() . '-' . bin2hex(random_bytes(6));
        if (@rename($this->lockPath, $graveyard)) {
            @unlink($graveyard);
        }
        // else: someone else already broke it (or it vanished) — just retry.
        return true;
    }

    public function acquire(): void
    {
        // First-ever login: ~/.knoxcall/ may not exist yet, and fopen('x') on
        // the lock path fails on a missing parent — which tryAcquire treats
        // as contention, spinning until the timeout. Create it up front.
        $parent = \dirname($this->lockPath);
        if ($parent !== '' && !is_dir($parent)) {
            @mkdir($parent, 0700, true);
        }
        $deadline = microtime(true) + $this->timeoutSeconds;
        $staleBroken = false;
        while (true) {
            if ($this->tryAcquire()) {
                return;
            }
            if (!$staleBroken && $this->breakStale()) {
                $staleBroken = true;
                if ($this->tryAcquire()) {
                    return;
                }
            }
            if (microtime(true) >= $deadline) {
                throw new KnoxCallException(
                    "timed out waiting for the credentials file lock ({$this->lockPath})"
                );
            }
            usleep((int) ($this->retryIntervalSeconds * 1_000_000));
        }
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        $own = $this->ownContent;
        $this->ownContent = null;
        if ($own === null) {
            return;
        }
        // Only remove the lock if it is still OURS — if our lock was broken as
        // stale and re-taken by another process while we were suspended, an
        // unlink by path would delete their live lock.
        clearstatcache(true, $this->lockPath);
        $current = @file_get_contents($this->lockPath);
        if ($current === $own) {
            @unlink($this->lockPath);
        }
    }
}
