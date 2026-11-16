<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * One-time, deduplicated warnings for security-relevant misconfigurations
 * (plaintext transport, world-readable credentials file). Mirrors
 * knoxcall-node src/warn.ts and PARITY §15.
 *
 * Warnings are emitted with trigger_error(E_USER_WARNING) so they respect the
 * host's error_reporting / set_error_handler configuration, never fire more
 * than once per distinct code in a process, and never raise into the caller's
 * path (a host handler that promotes warnings to exceptions is swallowed —
 * these are advisory and must never change behavior).
 */
final class Warn
{
    /** @var array<string, true> per-process dedup, keyed by warning code. */
    private static array $warned = [];

    private function __construct()
    {
    }

    /**
     * Emit $message as an E_USER_WARNING at most once per process per $code.
     * $code is the dedup key only (E_USER_WARNING carries no code channel of
     * its own); keep it stable and machine-greppable.
     */
    public static function warnOnce(string $code, string $message): void
    {
        if (isset(self::$warned[$code])) {
            return;
        }
        self::$warned[$code] = true;
        try {
            trigger_error($message, E_USER_WARNING);
        } catch (\Throwable) {
            // A host error handler that turns warnings into exceptions must
            // never break construction / a credentials read — the warning is
            // purely advisory (PARITY §15).
        }
    }

    /**
     * True for a URL whose scheme is plaintext http:// and whose host is NOT
     * loopback. Loopback = localhost, *.localhost, 127.0.0.0/8, ::1, 0.0.0.0
     * (http://localhost is the normal dev case and is treated as safe).
     */
    public static function isInsecureRemoteUrl(string $url): bool
    {
        if (stripos($url, 'http://') !== 0) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        // parse_url wraps IPv6 hosts in brackets (e.g. "[::1]").
        $host = strtolower(trim($host, '[]'));
        $loopback = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '0.0.0.0'
            || $host === '::1'
            || preg_match('/^127\./', $host) === 1;
        return !$loopback;
    }

    /** Test-only: clear the once-per-process dedup so warnings can be re-asserted. */
    public static function resetForTests(): void
    {
        self::$warned = [];
    }
}
