<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use KnoxCall\NotAuthenticatedException;
use PHPUnit\Framework\TestCase;

/**
 * NotAuthenticatedException (PARITY §1) + the opt-in interactive login helpers
 * KnoxCall::login() / ensureLogin() (PARITY §14) — mirrors knoxcall-node
 * test/login.test.ts. The underlying browser/device flow is exercised in
 * CliTest; here we cover the wrappers: the auto-detect give-up type,
 * stored-profile reuse (no prompt, no network), and the interactive guard.
 */
final class LoginTest extends TestCase
{
    private const ENV_VARS = [
        'KNOXCALL_TENANT',
        'KNOXCALL_ENVIRONMENT',
        'KNOXCALL_BASE_URL',
        'KNOXCALL_API_BASE_URL',
        'KNOXCALL_PROXY_BASE_URL',
        'KNOXCALL_ACCESS_TOKEN',
        'KNOXCALL_API_KEY',
        'KNOXCALL_CLIENT_ID',
        'KNOXCALL_CLIENT_SECRET',
        'KNOXCALL_CREDENTIALS_FILE',
        'KNOXCALL_PROFILE',
        'KNOXCALL_NO_INTERACTIVE',
        'CI',
    ];

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->clearKnoxCallEnv();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knoxcall-php-login-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'credentials.json';
        putenv('KNOXCALL_CREDENTIALS_FILE=' . $this->path);
    }

    protected function tearDown(): void
    {
        $this->clearKnoxCallEnv();
        self::removeDir($this->dir);
    }

    private function clearKnoxCallEnv(): void
    {
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
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

    private function seedProfile(array $overrides = [], string $profile = 'default'): void
    {
        CredentialsFile::writeProfile($this->path, $profile, $overrides + [
            'tenant' => 'acme',
            'base_url' => 'https://api.example.test',
            'client_id' => 'kc_cli_real',
            'refresh_token' => 'rt_1',
            'access_token' => 'kc_stored_fresh',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 3600),
            'scope' => '',
        ]);
    }

    private static function tenantOf(KnoxCall $client): ?string
    {
        return (new \ReflectionProperty($client, 'tenant'))->getValue($client);
    }

    private static function baseUrlOf(KnoxCall $client): string
    {
        return (new \ReflectionProperty($client, 'baseUrl'))->getValue($client);
    }

    // -- NotAuthenticatedException (PARITY §1) --------------------------------------

    public function testNotAuthenticatedIsSubclassOfBootstrapError(): void
    {
        $e = new NotAuthenticatedException('nope');
        // subclassing the SDK's generic bootstrap error keeps existing
        // `catch (KnoxCallException)` blocks working.
        $this->assertInstanceOf(KnoxCallException::class, $e);
    }

    public function testAutoDetectGiveUpThrowsNotAuthenticated(): void
    {
        // No explicit credential, no env token/keys, no credentials file, no
        // cloud OIDC (PHP has none): the chain gives up on first token fetch
        // with the distinctly-typed NotAuthenticatedException.
        $client = new KnoxCall(['tenant' => 'acme', 'transport' => new MockTransport()]);

        $this->expectException(NotAuthenticatedException::class);
        $this->expectExceptionMessage('knoxcall login');
        $client->request('GET', '/v1/routes');
    }

    // -- ensureLogin (PARITY §14) ---------------------------------------------------

    public function testEnsureLoginReturnsClientFromStoredProfileWithoutPromptOrNetwork(): void
    {
        $this->seedProfile();

        // No TTY / transport is wired: if this tried to prompt or hit the
        // network it would throw — a stored profile must do neither.
        $client = KnoxCall::ensureLogin();

        $this->assertInstanceOf(KnoxCall::class, $client);
        $this->assertSame('acme', self::tenantOf($client));           // seeded from the file
        $this->assertSame('https://api.example.test', self::baseUrlOf($client));
    }

    public function testEnsureLoginHonorsProfileOption(): void
    {
        $this->seedProfile(['tenant' => 'globex'], 'work');

        $client = KnoxCall::ensureLogin(['profile' => 'work']);

        $this->assertSame('globex', self::tenantOf($client));
    }

    // -- Interactive guard (PARITY §14) ---------------------------------------------

    public function testLoginRefusesWhenNoInteractiveEnvSet(): void
    {
        putenv('KNOXCALL_NO_INTERACTIVE=1');

        $this->expectException(NotAuthenticatedException::class);
        $this->expectExceptionMessage('KNOXCALL_NO_INTERACTIVE or CI');
        KnoxCall::login();
    }

    public function testEnsureLoginWithNoProfileRefusesInCi(): void
    {
        putenv('CI=true'); // no stored profile → ensureLogin falls through to login()

        $this->expectException(NotAuthenticatedException::class);
        KnoxCall::ensureLogin();
    }

    public function testAllowNonInteractiveBypassesGuardAndValidatesMode(): void
    {
        putenv('CI=true');

        // allow_non_interactive clears the guard; an unknown mode is then a
        // plain argument error (no browser/device flow is ever started).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mode must be');
        KnoxCall::login(['allow_non_interactive' => true, 'mode' => 'bogus']);
    }
}
