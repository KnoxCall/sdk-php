<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\KnoxCallException;
use KnoxCall\Warn;

/**
 * Shared credentials file (`~/.knoxcall/credentials.json`) — path/profile
 * resolution, tolerant reads, atomic writes. Mirrors knoxcall-python
 * auth/credentials_file.py.
 *
 * The file is written by `knoxcall login` and consumed by every SDK through
 * the StoredCredentials bootstrap. Format, lock protocol, and refresh rules
 * are cross-SDK identical (PARITY §2):
 *
 *   {"version": 1, "profiles": {"default": {tenant, base_url, client_id,
 *    refresh_token, access_token, access_token_expires_at, scope}}}
 *
 * Reads are tolerant: a missing/malformed file or profile yields null, never
 * an exception. Writes are ALWAYS temp file in the same directory → fsync →
 * atomic rename over the target (never partial), with the directory created
 * 0700 and the file chmod'd 0600 (best-effort — the mode bits are advisory
 * on Windows).
 */
final class CredentialsFile
{
    public const DEFAULT_PROFILE = 'default';

    /**
     * A stored access token is "fresh" while it has more than this much
     * validity left; below the threshold the provider refreshes under the
     * sibling file lock (see CredentialsFileLock).
     */
    public const FRESH_WINDOW_SECONDS = 60.0;

    public const RELOGIN_MESSAGE = 'stored CLI credentials are no longer valid — run `knoxcall login` again';

    private function __construct()
    {
    }

    // -- Path / profile resolution ---------------------------------------------

    /**
     * Credentials file path: explicit override > KNOXCALL_CREDENTIALS_FILE >
     * ~/.knoxcall/credentials.json. Null when even the home directory cannot
     * be resolved (the provider is then simply unavailable).
     */
    public static function resolvePath(?string $override = null): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        $env = getenv('KNOXCALL_CREDENTIALS_FILE');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $home = self::homeDirectory();
        if ($home === null) {
            return null;
        }
        return $home . DIRECTORY_SEPARATOR . '.knoxcall' . DIRECTORY_SEPARATOR . 'credentials.json';
    }

    /** Profile name: explicit override > KNOXCALL_PROFILE > "default". */
    public static function resolveProfile(?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        $env = getenv('KNOXCALL_PROFILE');
        return is_string($env) && $env !== '' ? $env : self::DEFAULT_PROFILE;
    }

    private static function homeDirectory(): ?string
    {
        // $_SERVER first (FPM setups often strip env vars from getenv),
        // USERPROFILE for Windows.
        foreach (['HOME', 'USERPROFILE'] as $var) {
            $value = $_SERVER[$var] ?? (getenv($var) ?: null);
            if (is_string($value) && $value !== '') {
                return rtrim($value, '/\\');
            }
        }
        return null;
    }

    // -- File primitives (tolerant reads, atomic writes) -------------------------

    /**
     * Parse the whole file; null on missing/unreadable/malformed/wrong shape.
     *
     * @return array<string, mixed>|null
     */
    private static function readDocument(string $path): ?array
    {
        if (!@is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        // The credentials file holds a refresh token; if it is readable by
        // group/other, warn (once) to lock it down. POSIX only — on Windows the
        // mode bits are advisory and confidentiality rests on the %USERPROFILE%
        // ACL, so the check is skipped (PARITY §15). Checked before parsing, to
        // match the node/python/go/ruby placement.
        self::warnIfLoosePermissions($path);
        $doc = json_decode($raw, true);
        if (!is_array($doc) || !isset($doc['profiles']) || !is_array($doc['profiles'])) {
            return null;
        }
        return $doc;
    }

    /**
     * Warn once if the credentials file — which holds a refresh token — is
     * group/other-accessible. No-op on Windows and on any stat failure.
     */
    private static function warnIfLoosePermissions(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $perms = @fileperms($path);
        if ($perms === false || ($perms & 0o077) === 0) {
            return;
        }
        Warn::warnOnce(
            'KNOXCALL_CREDENTIALS_FILE_PERMS',
            "KnoxCall credentials file {$path} is accessible to group/other (mode "
            . decoct($perms & 0o777) . ') and holds a refresh token. '
            . "Restrict it: chmod 600 {$path}",
        );
    }

    /**
     * One profile's record, or null (missing file, malformed JSON, unknown
     * profile) — never an exception.
     *
     * @return array<string, mixed>|null
     */
    public static function readProfile(string $path, string $profile): ?array
    {
        $doc = self::readDocument($path);
        if ($doc === null) {
            return null;
        }
        $record = $doc['profiles'][$profile] ?? null;
        return is_array($record) ? $record : null;
    }

    /** Auto-detect presence check: file exists AND the selected profile parses. */
    public static function profileAvailable(?string $path, string $profile): bool
    {
        return $path !== null && self::readProfile($path, $profile) !== null;
    }

    /**
     * Merge one profile into the file (other profiles untouched), atomically.
     *
     * @param array<string, mixed> $record null values are dropped
     */
    public static function writeProfile(string $path, string $profile, array $record): void
    {
        $doc = self::readDocument($path) ?? ['version' => 1, 'profiles' => []];
        $doc['version'] ??= 1;
        $doc['profiles'][$profile] = array_filter($record, static fn (mixed $v): bool => $v !== null);
        self::writeDocument($path, $doc);
    }

    /**
     * Remove one profile; delete the file when it was the last one. Callers
     * mutating a live file (the CLI's logout) run this under the sibling
     * CredentialsFileLock. Mirrors knoxcall-python remove_profile().
     */
    public static function removeProfile(string $path, string $profile): bool
    {
        $doc = self::readDocument($path);
        if ($doc === null || !array_key_exists($profile, $doc['profiles'])) {
            return false;
        }
        unset($doc['profiles'][$profile]);
        if ($doc['profiles'] !== []) {
            self::writeDocument($path, $doc);
        } else {
            @unlink($path);
        }
        return true;
    }

    /** @param array<string, mixed> $doc */
    private static function writeDocument(string $path, array $doc): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new KnoxCallException("could not create credentials directory {$dir}");
        }
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new KnoxCallException('could not JSON-encode the credentials document: ' . json_last_error_msg());
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.credentials-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $fh = @fopen($tmp, 'x'); // exclusive create — the random name never collides in practice
            if ($fh === false) {
                throw new KnoxCallException("could not create credentials temp file {$tmp}");
            }
            try {
                if (fwrite($fh, $json . "\n") === false) {
                    throw new KnoxCallException("could not write credentials temp file {$tmp}");
                }
                fflush($fh);
                @fsync($fh); // best-effort — some filesystems/wrappers refuse
            } finally {
                fclose($fh);
            }
            @chmod($tmp, 0600); // best-effort; advisory on Windows
            if (!@rename($tmp, $path)) {
                throw new KnoxCallException("could not replace credentials file {$path}");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    // -- Expiry formatting --------------------------------------------------------

    /** Epoch seconds → the file's ISO-8601 UTC shape (2026-07-04T10:00:00Z). */
    public static function formatExpiry(float $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $epoch);
    }

    /** ISO-8601 string → epoch seconds; null on anything unparseable. */
    public static function parseExpiry(mixed $value): ?float
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            // The timezone argument only applies to timezone-naive strings
            // (read as UTC, mirroring the Python reference); a Z suffix or
            // explicit offset in the string wins.
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        return (float) $dt->format('U');
    }
}
