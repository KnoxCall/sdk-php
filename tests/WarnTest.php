<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\KnoxCall;
use KnoxCall\Warn;
use PHPUnit\Framework\TestCase;

/**
 * Security misconfiguration warnings (PARITY §15) — mirrors knoxcall-node
 * test/warn.test.ts:
 *   - item 1: plaintext http:// to a NON-loopback base/proxy URL warns once
 *     at construction; http://localhost (normal dev) does not.
 *   - item 2: a group/other-accessible credentials file warns once on read
 *     (POSIX only); a 0600 file does not.
 *
 * Both warn-once and never block. Warnings are E_USER_WARNING; the suite runs
 * with failOnWarning="true", so each check runs the warning-triggering code
 * under a scoped set_error_handler that captures (and thereby absorbs) the
 * warning instead of letting PHPUnit's handler fail the test.
 */
final class WarnTest extends TestCase
{
    private const ENV_VARS = [
        'KNOXCALL_TENANT',
        'KNOXCALL_BASE_URL',
        'KNOXCALL_API_BASE_URL',
        'KNOXCALL_PROXY_BASE_URL',
        'KNOXCALL_ACCESS_TOKEN',
        'KNOXCALL_API_KEY',
        'KNOXCALL_CLIENT_ID',
        'KNOXCALL_CLIENT_SECRET',
        'KNOXCALL_CREDENTIALS_FILE',
        'KNOXCALL_PROFILE',
    ];

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
        Warn::resetForTests();
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
        foreach ($this->tmpDirs as $dir) {
            self::removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * Run $fn with a scoped handler that records every E_USER_WARNING it
     * triggers and marks it handled — so intentional warnings are captured
     * here rather than failing the test via PHPUnit's failOnWarning handler.
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

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knoxcall-php-warn-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($p) ? self::removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // -- isInsecureRemoteUrl helper -------------------------------------------

    public function testIsInsecureRemoteUrlFlagsOnlyNonLoopbackHttp(): void
    {
        $this->assertTrue(Warn::isInsecureRemoteUrl('http://api.example.com'));
        $this->assertTrue(Warn::isInsecureRemoteUrl('http://10.0.0.5:3000'));
        $this->assertTrue(Warn::isInsecureRemoteUrl('HTTP://API.EXAMPLE.COM')); // scheme match is case-insensitive

        $this->assertFalse(Warn::isInsecureRemoteUrl('https://api.example.com'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://localhost:3000'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://127.0.0.1:3000'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://127.5.5.5'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://foo.localhost'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://0.0.0.0:3000'));
        $this->assertFalse(Warn::isInsecureRemoteUrl('http://[::1]:3000'));
        $this->assertFalse(Warn::isInsecureRemoteUrl(''));
    }

    // -- Item 1: plaintext transport warning at construction ------------------

    public function testConstructionWarnsOnceForInsecureBaseUrl(): void
    {
        $messages = $this->captureWarnings(function (): void {
            new KnoxCall([
                'access_token' => 'kc_live_x',
                'tenant' => 'acme',
                'base_url' => 'http://api.example.com',
                'proxy_base_url' => 'https://acme.example.test',
                'transport' => new MockTransport(),
            ]);
        });

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('base URL', $joined);
        $this->assertStringContainsString('http://api.example.com', $joined);
        $this->assertStringContainsString('unencrypted', $joined);
        // The proxy URL is https:// — only the base-URL warning fired.
        $this->assertStringNotContainsString('proxy base URL', $joined);
    }

    public function testConstructionWarnsForInsecureProxyUrl(): void
    {
        $messages = $this->captureWarnings(function (): void {
            new KnoxCall([
                'access_token' => 'kc_live_x',
                'tenant' => 'acme',
                'base_url' => 'https://api.example.test',
                'proxy_base_url' => 'http://proxy.example.com',
                'transport' => new MockTransport(),
            ]);
        });

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('proxy base URL', $joined);
        $this->assertStringContainsString('http://proxy.example.com', $joined);
    }

    public function testConstructionDoesNotWarnForLocalhost(): void
    {
        $messages = $this->captureWarnings(function (): void {
            new KnoxCall([
                'access_token' => 'kc_live_x',
                'tenant' => 'acme',
                'base_url' => 'http://localhost:3000',
                'proxy_base_url' => 'http://localhost:3000',
                'transport' => new MockTransport(),
            ]);
        });

        $this->assertSame([], $messages);
    }

    public function testInsecureUrlWarningFiresOnlyOncePerProcess(): void
    {
        // warnOnce dedups per code: a second insecure-base construction (no
        // reset in between) must stay silent.
        $make = fn (): KnoxCall => new KnoxCall([
            'access_token' => 'kc_live_x',
            'tenant' => 'acme',
            'base_url' => 'http://api.example.com',
            'proxy_base_url' => 'https://acme.example.test',
            'transport' => new MockTransport(),
        ]);

        $first = $this->captureWarnings($make);
        $second = $this->captureWarnings($make);

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
    }

    // -- Item 2: world-readable credentials file warning ----------------------

    public function testWorldReadableCredentialsFileWarns(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX mode bits are advisory on Windows (PARITY §15).');
        }

        $path = $this->tempDir() . DIRECTORY_SEPARATOR . 'credentials.json';
        $this->writeValidProfile($path);
        chmod($path, 0o644); // group/other readable

        $messages = $this->captureWarnings(static function () use ($path): void {
            CredentialsFile::readProfile($path, 'default');
        });

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('group/other', $joined);
        $this->assertStringContainsString('chmod 600', $joined);
        $this->assertStringContainsString($path, $joined);
    }

    public function testModeSixHundredCredentialsFileDoesNotWarn(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX mode bits are advisory on Windows (PARITY §15).');
        }

        $path = $this->tempDir() . DIRECTORY_SEPARATOR . 'credentials.json';
        $this->writeValidProfile($path);
        chmod($path, 0o600);

        $messages = $this->captureWarnings(static function () use ($path): void {
            CredentialsFile::readProfile($path, 'default');
        });

        $this->assertSame([], $messages);
    }

    /** Write a valid login-shaped profile (CredentialsFile chmods it 0600). */
    private function writeValidProfile(string $path): void
    {
        CredentialsFile::writeProfile($path, 'default', [
            'tenant' => 'acme',
            'base_url' => 'https://api.example.test',
            'client_id' => 'kc_cli_real',
            'refresh_token' => 'rt_1',
            'access_token' => 'kc_stored_fresh',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 3600),
            'scope' => 'routes:read',
        ]);
    }
}
