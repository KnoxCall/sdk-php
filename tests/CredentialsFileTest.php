<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\AccessToken;
use KnoxCall\Auth\ClientCredentials;
use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\CredentialsFileLock;
use KnoxCall\Auth\StoredCredentials;
use KnoxCall\AuthenticationException;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use PHPUnit\Framework\TestCase;

/**
 * Credentials-file provider tests — StoredCredentials (PARITY §2/§10) —
 * mirrors knoxcall-python tests/test_credentials_file.py.
 *
 * Every test points KNOXCALL_CREDENTIALS_FILE at a sys_get_temp_dir()
 * directory so the real ~/.knoxcall is never touched (or even read).
 *
 * PHP has no threads, so the concurrent-double-refresh half of the PARITY
 * §10 matrix is covered at the lock-protocol level instead: a pre-created
 * FRESH lock file (another process mid-refresh) must time the waiter out,
 * and a pre-created STALE (>60s) lock file must be broken with the refresh
 * proceeding — plus the rotated-write-back test proving the loser of a race
 * would re-read the rotated token under the lock. Ownership-aware
 * break/release (hardened 2026-08, PARITY §2) is covered by the lock-ownership
 * tests: release() never deletes a peer-owned lock, an in-window lock is not
 * broken, and a genuinely stale lock is broken and re-acquired.
 */
final class CredentialsFileTest extends TestCase
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
    ];

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->clearKnoxCallEnv();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knoxcall-php-creds-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'credentials.json';
        // Never let the chain or the provider read a real ~/.knoxcall file.
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

    /** Write a login-shaped profile; overrides win over the fresh defaults. */
    private function writeCreds(array $overrides = [], string $profile = 'default', ?string $path = null): string
    {
        $path ??= $this->path;
        CredentialsFile::writeProfile($path, $profile, $overrides + [
            'tenant' => 'acme',
            'base_url' => 'https://api.example.test',
            'client_id' => 'kc_cli_real',
            'refresh_token' => 'rt_1',
            'access_token' => 'kc_stored_fresh',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 3600),
            'scope' => 'routes:read',
        ]);
        return $path;
    }

    /** The real management-API success envelope (src/client-api/helpers.ts). */
    private static function envelope(mixed $data): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    private static function credentialsOf(KnoxCall $client): mixed
    {
        return (new \ReflectionProperty($client, 'credentials'))->getValue($client);
    }

    private static function tenantOf(KnoxCall $client): ?string
    {
        return (new \ReflectionProperty($client, 'tenant'))->getValue($client);
    }

    // -- Chain slot 2: env token > file > env client-credentials ----------------

    public function testZeroConfigConstructionPicksUpCredentialsFile(): void
    {
        $this->writeCreds();

        $client = new KnoxCall(['transport' => new MockTransport()]);

        $credentials = self::credentialsOf($client);
        $this->assertInstanceOf(StoredCredentials::class, $credentials);
        $this->assertSame('stored_credentials', $credentials->type);
    }

    public function testEnvAccessTokenBeatsFile(): void
    {
        $this->writeCreds();
        putenv('KNOXCALL_ACCESS_TOKEN=kc_env_token');

        $client = new KnoxCall(['transport' => new MockTransport()]);

        $this->assertInstanceOf(AccessToken::class, self::credentialsOf($client));
    }

    public function testFileBeatsEnvClientCredentials(): void
    {
        // Both set: the file wins per the chain order (PARITY §2 slot 2).
        $this->writeCreds();
        putenv('KNOXCALL_CLIENT_ID=tk_env');
        putenv('KNOXCALL_CLIENT_SECRET=sec');

        $client = new KnoxCall(['transport' => new MockTransport()]);

        $this->assertInstanceOf(StoredCredentials::class, self::credentialsOf($client));
    }

    public function testMissingFileSkipsProvider(): void
    {
        // KNOXCALL_CREDENTIALS_FILE points at a path that was never written.
        putenv('KNOXCALL_CLIENT_ID=tk_env');
        putenv('KNOXCALL_CLIENT_SECRET=sec');

        $client = new KnoxCall(['transport' => new MockTransport()]);

        $this->assertInstanceOf(ClientCredentials::class, self::credentialsOf($client));
    }

    public function testMissingProfileSkipsProvider(): void
    {
        $this->writeCreds(); // only "default" exists
        putenv('KNOXCALL_PROFILE=work');
        putenv('KNOXCALL_CLIENT_ID=tk_env');
        putenv('KNOXCALL_CLIENT_SECRET=sec');

        $client = new KnoxCall(['transport' => new MockTransport()]);

        $this->assertInstanceOf(ClientCredentials::class, self::credentialsOf($client));
    }

    public function testMalformedFileSkipsProviderNotCrash(): void
    {
        putenv('KNOXCALL_CLIENT_ID=tk_env');
        putenv('KNOXCALL_CLIENT_SECRET=sec');

        $malformed = [
            '{this is not json',                                  // parse error
            json_encode(['version' => 1, 'profiles' => 'nope']),  // wrong shape
            json_encode([['not' => 'an object']]),                // wrong shape (list)
        ];
        foreach ($malformed as $contents) {
            file_put_contents($this->path, $contents);
            @chmod($this->path, 0o600); // this test is about malformed SHAPE, not perms
            $client = new KnoxCall(['transport' => new MockTransport()]);
            $this->assertInstanceOf(
                ClientCredentials::class,
                self::credentialsOf($client),
                "contents: {$contents}",
            );
        }
    }

    // -- Fresh-token fast path ---------------------------------------------------

    public function testFreshAccessTokenUsedWithoutRefresh(): void
    {
        $this->writeCreds();
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping');

        // No token-endpoint round trip — the stored token is the credential.
        $this->assertCount(1, $t->requests);
        $this->assertSame('Bearer kc_stored_fresh', $t->header(0, 'Authorization'));
    }

    // -- Refresh + rotated write-back ---------------------------------------------

    public function testExpiredTokenRefreshesAndWritesBackRotatedToken(): void
    {
        $this->writeCreds([
            'access_token' => 'kc_old',
            'refresh_token' => 'rt_old',
            // inside the 60s freshness window → must refresh
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 10),
        ]);
        $t = new MockTransport();
        $t->queueJson(200, [
            'access_token' => 'kc_new',
            'refresh_token' => 'rt_new',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'routes:read secrets:read',
            'tenant' => 'acme',
            'client_id' => 'kc_cli_real',
        ]);
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping');

        // refresh_token grant against the file's base_url, with the file's
        // REAL client id — a public client, so no secret.
        $this->assertSame('https://api.example.test/oauth/token', $t->requests[0]['url']);
        parse_str((string) $t->requests[0]['body'], $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('rt_old', $form['refresh_token']);
        $this->assertSame('kc_cli_real', $form['client_id']);
        $this->assertArrayNotHasKey('client_secret', $form);
        $this->assertSame('Bearer kc_new', $t->header(1, 'Authorization'));

        // rotation persisted (written back under the lock, before release)
        $onDisk = CredentialsFile::readProfile($this->path, 'default');
        $this->assertSame('rt_new', $onDisk['refresh_token']);
        $this->assertSame('kc_new', $onDisk['access_token']);
        $this->assertSame('routes:read secrets:read', $onDisk['scope']);

        // atomic write hygiene: no temp-file or lock litter left behind
        $this->assertSame(
            ['credentials.json'],
            array_values(array_diff(scandir($this->dir), ['.', '..'])),
        );
    }

    public function testInvalidGrantRaisesTypedErrorWithReloginHint(): void
    {
        $this->writeCreds([
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) - 10),
        ]);
        $t = new MockTransport();
        $t->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'family revoked']);

        $client = new KnoxCall(['transport' => $t]);
        try {
            $client->request('GET', '/v1/ping');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('knoxcall login', $e->getMessage());
            $this->assertSame('invalid_grant', $e->errorCode);
        }
        // the lock was released on the error path
        $this->assertFileDoesNotExist($this->path . '.lock');
    }

    public function testExpiredTokenWithoutRefreshTokenRaisesReloginHint(): void
    {
        CredentialsFile::writeProfile($this->path, 'default', [
            'tenant' => 'acme',
            'base_url' => 'https://api.example.test',
            'client_id' => 'kc_cli_real',
            'access_token' => 'kc_dead',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) - 10),
        ]);

        $client = new KnoxCall(['transport' => new MockTransport()]);
        try {
            $client->request('GET', '/v1/ping');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('knoxcall login', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->path . '.lock');
    }

    // -- Lock protocol: contention + staleness (PHP has no threads — see class
    //    docblock for how this substitutes for the double-refresh race) ---------

    public function testHeldLockTimesOutWaiter(): void
    {
        // A FRESH lock file = another process mid-refresh right now.
        file_put_contents($this->path . '.lock', "123 now\n");

        $lock = new CredentialsFileLock($this->path, timeoutSeconds: 0.3, retryIntervalSeconds: 0.05);
        $this->expectException(KnoxCallException::class);
        $this->expectExceptionMessage('credentials file lock');
        $lock->acquire();
    }

    public function testLockAcquiresWhenParentDirMissing(): void
    {
        // First-ever login: ~/.knoxcall/ does not exist yet. fopen('x') on the
        // lock path used to fail on the missing parent, which tryAcquire
        // swallowed as contention — spinning for the full timeout. Regression:
        // acquire must create the parent and succeed immediately.
        $fresh = $this->path . '.freshdir/nested/credentials.json';
        $lock = new CredentialsFileLock($fresh, timeoutSeconds: 2.0);
        $start = microtime(true);
        $lock->acquire();
        try {
            $this->assertLessThan(1.0, microtime(true) - $start);
            $this->assertFileExists($fresh . '.lock');
        } finally {
            $lock->release();
        }
        $this->assertFileDoesNotExist($fresh . '.lock');
        // The full first-login write path works in the fresh dir too.
        CredentialsFile::writeProfile($fresh, 'default', ['tenant' => 'acme', 'client_id' => 'kc_cli_x']);
        $this->assertSame('acme', CredentialsFile::readProfile($fresh, 'default')['tenant']);
    }

    public function testStaleLockIsBrokenAndAcquireProceeds(): void
    {
        $lockPath = $this->path . '.lock';
        file_put_contents($lockPath, "999 0\n");
        touch($lockPath, time() - 120); // well past the 60s staleness threshold

        $lock = new CredentialsFileLock($this->path);
        $start = microtime(true);
        $lock->acquire();
        try {
            // broke the stale lock and acquired immediately — no 10s timeout
            $this->assertLessThan(5.0, microtime(true) - $start);
        } finally {
            $lock->release();
        }
        $this->assertFileDoesNotExist($lockPath);
    }

    public function testRefreshBreaksStaleLockEndToEnd(): void
    {
        $this->writeCreds([
            'access_token' => 'kc_old',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) - 10),
        ]);
        $lockPath = $this->path . '.lock';
        file_put_contents($lockPath, "999 0\n"); // a crashed process left this behind
        touch($lockPath, time() - 120);

        $t = new MockTransport();
        $t->queueJson(200, ['access_token' => 'kc_new', 'refresh_token' => 'rt_2', 'expires_in' => 3600]);
        $t->queueJson(200, self::envelope(['ok' => true]));

        (new KnoxCall(['transport' => $t]))->request('GET', '/v1/ping');

        $this->assertSame('rt_2', CredentialsFile::readProfile($this->path, 'default')['refresh_token']);
        $this->assertFileDoesNotExist($lockPath);
    }

    // -- Lock ownership (hardened 2026-08, PARITY §2) ---------------------------
    //    The lock carries a unique owner tag; break is by atomic rename and
    //    release only unlinks a lock whose on-disk content still matches what
    //    this instance wrote — so the double-acquire → double-refresh race can
    //    never replay the single-use refresh token.

    public function testReleaseDoesNotDeletePeerOwnedLock(): void
    {
        // This instance acquires; a peer then breaks our lock as stale and
        // re-creates its own (simulated by overwriting the lock's content with
        // a different owner tag). Our release() must NOT delete the peer's live
        // lock — a blind unlink-by-path would.
        $lockPath = $this->path . '.lock';
        $lock = new CredentialsFileLock($this->path);
        $lock->acquire();
        $this->assertFileExists($lockPath);

        $peerContent = "999999 123.456 " . bin2hex(random_bytes(8)) . "\n";
        file_put_contents($lockPath, $peerContent);

        $lock->release();

        // The peer's lock survived, still holding the peer's owner tag.
        $this->assertFileExists($lockPath);
        $this->assertSame($peerContent, file_get_contents($lockPath));
    }

    public function testReleaseRemovesOwnLock(): void
    {
        // The complementary case: when the on-disk lock is still ours, release
        // cleans it up.
        $lockPath = $this->path . '.lock';
        $lock = new CredentialsFileLock($this->path);
        $lock->acquire();
        $this->assertFileExists($lockPath);

        $lock->release();

        $this->assertFileDoesNotExist($lockPath);
    }

    public function testLockWithinStaleWindowIsNotBroken(): void
    {
        // A lock aged 45s is still inside the 60s stale window (raised from
        // 30s): a waiter must time out rather than break a possibly-live
        // holder mid-refresh.
        $lockPath = $this->path . '.lock';
        $peerContent = "123 1.000 " . bin2hex(random_bytes(8)) . "\n";
        file_put_contents($lockPath, $peerContent);
        touch($lockPath, time() - 45);

        $lock = new CredentialsFileLock($this->path, timeoutSeconds: 0.3, retryIntervalSeconds: 0.05);
        try {
            $lock->acquire();
            $this->fail('expected the waiter to time out on a lock inside the stale window');
        } catch (KnoxCallException $e) {
            $this->assertStringContainsString('credentials file lock', $e->getMessage());
        }
        // The in-window lock was left intact (not broken).
        $this->assertFileExists($lockPath);
        $this->assertSame($peerContent, file_get_contents($lockPath));
    }

    public function testGenuinelyStaleLockIsBrokenAndReacquired(): void
    {
        $lockPath = $this->path . '.lock';
        $peerContent = "999 0.000 " . bin2hex(random_bytes(8)) . "\n";
        file_put_contents($lockPath, $peerContent);
        touch($lockPath, time() - 120); // well past the 60s threshold

        $lock = new CredentialsFileLock($this->path);
        $start = microtime(true);
        $lock->acquire();
        try {
            $this->assertLessThan(5.0, microtime(true) - $start);
            // The lock is now OURS: the peer's owner tag was replaced.
            $this->assertNotSame($peerContent, file_get_contents($lockPath));
        } finally {
            $lock->release();
        }
        // Our own release cleaned it up (content still matched what we wrote).
        $this->assertFileDoesNotExist($lockPath);
    }

    // -- KNOXCALL_CREDENTIALS_FILE / KNOXCALL_PROFILE overrides ------------------

    public function testCredentialsFileEnvOverride(): void
    {
        // A nested never-created directory also exercises the 0700 mkdir path.
        $custom = $this->dir . DIRECTORY_SEPARATOR . 'elsewhere' . DIRECTORY_SEPARATOR . 'creds.json';
        $this->writeCreds(['access_token' => 'kc_custom_path'], path: $custom);
        putenv('KNOXCALL_CREDENTIALS_FILE=' . $custom);

        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));
        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping');

        $this->assertInstanceOf(StoredCredentials::class, self::credentialsOf($client));
        $this->assertSame('Bearer kc_custom_path', $t->header(0, 'Authorization'));
    }

    public function testProfileEnvOverrideSelectsProfile(): void
    {
        $this->writeCreds(['access_token' => 'kc_default']);
        $this->writeCreds(['access_token' => 'kc_work', 'tenant' => 'globex'], profile: 'work');
        putenv('KNOXCALL_PROFILE=work');

        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));
        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping');

        $this->assertSame('Bearer kc_work', $t->header(0, 'Authorization'));
        $this->assertSame('globex', self::tenantOf($client)); // seeded from the work profile
    }

    public function testExplicitStoredCredentialsObjectWithPathAndProfile(): void
    {
        // Explicit path/profile on the credential object beat the env vars
        // (which point at the default temp path, never written here).
        $custom = $this->dir . DIRECTORY_SEPARATOR . 'byo.json';
        $this->writeCreds(['access_token' => 'kc_byo'], profile: 'work', path: $custom);

        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));
        $client = new KnoxCall([
            'credentials' => new StoredCredentials($custom, 'work'),
            'transport' => $t,
        ]);
        $client->request('GET', '/v1/ping');

        $this->assertSame('Bearer kc_byo', $t->header(0, 'Authorization'));
    }

    // -- Client seeding: file tenant/base_url, explicit always wins ---------------

    public function testFileSeedsTenantAndBaseUrlWhenNotExplicit(): void
    {
        $this->writeCreds(); // tenant acme, base https://api.example.test
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['transport' => $t]); // zero-config
        $client->request('GET', '/v1/ping');

        $this->assertSame('acme', self::tenantOf($client));
        $this->assertSame('https://api.example.test/v1/ping', $t->lastRequest()['url']);
    }

    public function testFileTenantDerivesDataPlaneHost(): void
    {
        // A hosted base_url in the file re-derives the per-tenant proxy.
        $this->writeCreds(['base_url' => 'https://api.knoxcall.com']);
        $t = new MockTransport();
        $t->queueJson(200, ['ok' => true]);

        $client = new KnoxCall(['transport' => $t]);
        $client->call('r_1', ['path' => '/x']);

        $this->assertSame('https://acme.knoxcall.com/api/x', $t->lastRequest()['url']);
    }

    public function testExplicitTenantAndBaseUrlBeatFile(): void
    {
        $this->writeCreds(); // tenant acme, base https://api.example.test
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall([
            'tenant' => 'zeta',
            'base_url' => 'https://explicit.example.test',
            'transport' => $t,
        ]);
        $client->request('GET', '/v1/ping');

        $this->assertSame('zeta', self::tenantOf($client));
        $this->assertSame('https://explicit.example.test/v1/ping', $t->lastRequest()['url']);
    }

    public function testEnvTenantAndBaseUrlBeatFile(): void
    {
        $this->writeCreds();
        putenv('KNOXCALL_TENANT=envcorp');
        putenv('KNOXCALL_BASE_URL=https://env.example.test');
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping');

        $this->assertSame('envcorp', self::tenantOf($client));
        $this->assertSame('https://env.example.test/v1/ping', $t->lastRequest()['url']);
    }

    // -- Secret hygiene (PARITY §3) ------------------------------------------------

    public function testStoredTokensNeverAppearInDebugOutput(): void
    {
        $this->writeCreds(['access_token' => 'kc_stored_hush', 'refresh_token' => 'rt_hush']);
        $t = new MockTransport();
        $t->queueJson(200, self::envelope(['ok' => true]));

        $client = new KnoxCall(['transport' => $t]);
        $client->request('GET', '/v1/ping'); // populate the token cache from the file

        // The mock transport records raw requests (incl. Authorization) for
        // assertions — a test-only artifact; drop them so the dump checks
        // exercise the client's own state.
        $t->requests = [];

        ob_start();
        var_dump($client);
        $dump = ob_get_clean();
        $printed = print_r($client, true);
        foreach (['kc_stored_hush', 'rt_hush'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
            $this->assertStringNotContainsString($secret, $printed);
        }
    }
}
