<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Cli\Cli;
use KnoxCall\Cli\CliError;
use KnoxCall\Cli\Common;
use KnoxCall\Cli\Login;
use KnoxCall\Cli\LoopbackServer;
use KnoxCall\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CLI tests — `knoxcall login/logout/whoami` (PARITY §13) — mirrors
 * knoxcall-python tests/test_cli.py.
 *
 * All HTTP goes through the house MockTransport; the loopback listener binds
 * 127.0.0.1:0 and is driven by a REAL HTTP request written over
 * stream_socket_client (PHP is single-threaded, so the test connects before
 * waitForCode() blocks in accept — the kernel backlog holds the request).
 * Credentials files live under sys_get_temp_dir() only
 * (KNOXCALL_CREDENTIALS_FILE pinned in setUp).
 */
final class CliTest extends TestCase
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
        'KNOXCALL_WRAP_SECRET',
    ];

    private const BASE = 'https://api.example.test';

    private string $dir;
    private string $path;

    /** @var list<resource> sockets a fake browser opened — kept alive until tearDown */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->clearKnoxCallEnv();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knoxcall-php-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'credentials.json';
        putenv('KNOXCALL_CREDENTIALS_FILE=' . $this->path);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        $this->sockets = [];
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

    // -- Harness helpers ---------------------------------------------------------

    /** @return resource */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    /** @return array{0: Cli, 1: resource, 2: resource} [cli, out, err] — sleeps and browser are no-ops */
    private function cli(?MockTransport $transport = null): array
    {
        $out = $this->memoryStream();
        $err = $this->memoryStream();
        $cli = new Cli(
            $transport,
            static function (float $seconds): void {
            },
            static function (string $url): void {
            },
            $out,
            $err,
        );
        return [$cli, $out, $err];
    }

    private function login(MockTransport $transport, ?\Closure $sleep = null, ?\Closure $openBrowser = null): array
    {
        $out = $this->memoryStream();
        $command = new Login(
            $transport,
            $sleep ?? static function (float $seconds): void {
            },
            $openBrowser ?? static function (string $url): void {
            },
            $out,
        );
        return [$command, $out];
    }

    /** Write a real HTTP request at the loopback listener (held in the accept backlog). */
    private function hitLoopback(int $port, string $target): void
    {
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to the loopback listener: {$errstr}");
        fwrite($socket, "GET {$target} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        $this->sockets[] = $socket; // keep open until the server has responded
    }

    /** A login-shaped profile record; overrides win. */
    private function seedProfile(array $overrides = [], string $profile = 'default'): void
    {
        CredentialsFile::writeProfile($this->path, $profile, $overrides + [
            'tenant' => 'acme',
            'base_url' => self::BASE,
            'client_id' => 'kc_cli_real',
            'refresh_token' => 'rt_' . $profile,
            'access_token' => 'kc_stored_fresh',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 3600),
            'scope' => 'routes:read',
        ]);
    }

    private static function tokenResponse(array $overrides = []): array
    {
        return $overrides + [
            'access_token' => 'kc_tok',
            'refresh_token' => 'rt_tok',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'routes:read',
            'tenant' => 'acme',
            'client_id' => 'kc_cli_real', // extension member: real per-tenant CLI client
        ];
    }

    // -- PKCE (RFC 7636, S256 only) ------------------------------------------------

    public function testPkcePairIsS256AndUrlsafe(): void
    {
        [$verifier, $challenge] = Login::generatePkcePair();
        $this->assertGreaterThanOrEqual(43, strlen($verifier)); // RFC 7636 §4.1
        $this->assertLessThanOrEqual(128, strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $verifier);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $challenge);
        $this->assertStringNotContainsString('=', $challenge);
        // fresh entropy per call
        $this->assertNotSame($verifier, Login::generatePkcePair()[0]);
    }

    public function testAuthorizeUrlUsesCliAliasAndS256(): void
    {
        $url = Login::buildAuthorizeUrl(
            self::BASE,
            'http://127.0.0.1:51234/callback',
            'st_1',
            'chal',
            'acme',
        );
        $this->assertStringStartsWith(self::BASE . '/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('knoxcall-cli', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('http://127.0.0.1:51234/callback', $query['redirect_uri']);
        $this->assertSame('st_1', $query['state']);
        $this->assertSame('acme', $query['tenant']);
    }

    // -- Loopback callback listener --------------------------------------------------

    public function testLoopbackCallbackSuccess(): void
    {
        $server = new LoopbackServer();
        try {
            $this->assertGreaterThan(0, $server->port);
            $this->hitLoopback($server->port, '/callback?code=abc123&state=st1');
            $code = $server->waitForCode('st1', 5.0);
        } finally {
            $server->close();
        }
        $this->assertSame('abc123', $code);
        // the listener answered the browser with the success page
        $this->assertStringContainsString('Signed in', (string) stream_get_contents($this->sockets[0]));
    }

    public function testLoopbackCallbackErrorParam(): void
    {
        $server = new LoopbackServer();
        try {
            $this->hitLoopback(
                $server->port,
                '/callback?error=access_denied&error_description=nope&state=st1',
            );
            $this->expectException(CliError::class);
            $this->expectExceptionMessage('nope');
            $server->waitForCode('st1', 5.0);
        } finally {
            $server->close();
        }
    }

    public function testLoopbackCallbackStateMismatch(): void
    {
        $server = new LoopbackServer();
        try {
            $this->hitLoopback($server->port, '/callback?code=abc123&state=EVIL');
            $this->expectException(CliError::class);
            $this->expectExceptionMessage('state mismatch');
            $server->waitForCode('st1', 5.0);
        } finally {
            $server->close();
        }
    }

    public function testLoopbackIgnoresOtherPathsUntilCallbackArrives(): void
    {
        $server = new LoopbackServer();
        try {
            $this->hitLoopback($server->port, '/favicon.ico');
            $this->hitLoopback($server->port, '/callback?code=abc123&state=st1');
            $code = $server->waitForCode('st1', 5.0);
        } finally {
            $server->close();
        }
        $this->assertSame('abc123', $code);
        $this->assertStringContainsString('404', (string) stream_get_contents($this->sockets[0]));
    }

    // -- Auth-code flow end-to-end (fake browser, mocked token endpoint) -------------

    public function testAuthCodeFlowExchangesCodeWithVerifier(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::tokenResponse(['access_token' => 'kc_ac', 'refresh_token' => 'rt_ac']));

        $authorize = [];
        $fakeBrowser = function (string $url) use (&$authorize): void {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $authorize = $query;
            $redirect = parse_url((string) $query['redirect_uri']);
            $this->hitLoopback(
                (int) $redirect['port'],
                $redirect['path'] . '?code=authcode1&state=' . rawurlencode((string) $query['state']),
            );
        };
        [$command, $out] = $this->login($transport, openBrowser: \Closure::fromCallable($fakeBrowser));

        $body = $command->authCodeFlow(self::BASE, null, 10.0);
        $this->assertSame('kc_ac', $body['access_token']);

        // the authorize URL used the reserved alias + S256
        $this->assertSame('knoxcall-cli', $authorize['client_id']);
        $this->assertSame('S256', $authorize['code_challenge_method']);

        // the code was exchanged with the matching PKCE verifier
        $request = $transport->lastRequest();
        $this->assertSame(self::BASE . '/oauth/token', $request['url']);
        parse_str((string) $request['body'], $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('authcode1', $form['code']);
        $this->assertSame('knoxcall-cli', $form['client_id']);
        $this->assertSame($authorize['redirect_uri'], $form['redirect_uri']);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', (string) $form['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($authorize['code_challenge'], $expectedChallenge);

        // the URL was printed for the human; tokens never were
        $printed = self::contents($out);
        $this->assertStringContainsString('/oauth/authorize?', $printed);
        $this->assertStringNotContainsString('kc_ac', $printed);
        $this->assertStringNotContainsString('rt_ac', $printed);
    }

    // -- Device flow polling -----------------------------------------------------------

    public function testDevicePollHonorsIntervalAndSlowDown(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(400, ['error' => 'authorization_pending']);
        $transport->queueJson(400, ['error' => 'slow_down']);
        $transport->queueJson(400, ['error' => 'authorization_pending']);
        $transport->queueJson(200, ['access_token' => 'kc_dev', 'refresh_token' => 'rt', 'expires_in' => 3600]);

        $sleeps = [];
        [$command] = $this->login($transport, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $body = $command->pollDeviceToken(self::BASE, 'dev_code_1', interval: 5, expiresIn: 900.0);
        $this->assertSame('kc_dev', $body['access_token']);
        // sleep BEFORE the first poll; 5s until slow_down, then +5 per RFC 8628 §3.5
        $this->assertSame([5.0, 5.0, 10.0, 10.0], $sleeps);
        foreach ($transport->requests as $request) {
            parse_str((string) $request['body'], $form);
            $this->assertSame('dev_code_1', $form['device_code']);
        }
        parse_str((string) $transport->requests[0]['body'], $first);
        $this->assertSame('urn:ietf:params:oauth:grant-type:device_code', $first['grant_type']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function terminalDeviceErrors(): array
    {
        return [
            'access_denied' => ['access_denied', 'denied'],
            'expired_token' => ['expired_token', 'knoxcall login'],
        ];
    }

    #[DataProvider('terminalDeviceErrors')]
    public function testDevicePollTerminalErrors(string $error, string $fragment): void
    {
        $transport = new MockTransport();
        $transport->queueJson(400, ['error' => $error]);
        [$command] = $this->login($transport);

        $this->expectException(CliError::class);
        $this->expectExceptionMessage($fragment);
        $command->pollDeviceToken(self::BASE, 'dev_code_1');
    }

    // -- Profile write / merge / persist (under the lock) -------------------------------

    public function testProfileWriteAndMerge(): void
    {
        Common::persistLogin($this->path, 'default', self::BASE, self::tokenResponse(['refresh_token' => 'rt1']));
        Common::persistLogin($this->path, 'work', self::BASE, self::tokenResponse(['refresh_token' => 'rt2', 'tenant' => 'globex']));

        $doc = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame(1, $doc['version']);
        $this->assertSame(['default', 'work'], array_keys($doc['profiles']));

        // overwriting one profile leaves the other intact
        Common::persistLogin($this->path, 'default', self::BASE, self::tokenResponse(['refresh_token' => 'rt3']));
        $this->assertSame('rt3', CredentialsFile::readProfile($this->path, 'default')['refresh_token']);
        $this->assertSame('rt2', CredentialsFile::readProfile($this->path, 'work')['refresh_token']);
    }

    public function testPersistLoginRecordsExtensionMembersUnderTheLock(): void
    {
        // A STALE (>60s) foreign lock proves persistLogin really participates
        // in the lock protocol: it must break the lock, write, and release.
        touch($this->path . '.lock', time() - 120);

        $record = Common::persistLogin($this->path, 'default', self::BASE, [
            'access_token' => 'kc_a',
            'refresh_token' => 'rt_a',
            'expires_in' => 3600,
            'scope' => 'routes:read',
            'tenant' => 'acme',
            'client_id' => 'kc_cli_real', // extension member: real per-tenant client
        ]);

        $onDisk = CredentialsFile::readProfile($this->path, 'default');
        $this->assertSame('kc_cli_real', $onDisk['client_id']);
        $this->assertSame('acme', $onDisk['tenant']);
        $this->assertSame(self::BASE, $onDisk['base_url']);
        $this->assertSame('rt_a', $onDisk['refresh_token']);
        $this->assertStringEndsWith('Z', $onDisk['access_token_expires_at']);
        $this->assertSame($record['access_token_expires_at'], $onDisk['access_token_expires_at']);
        $this->assertFileDoesNotExist($this->path . '.lock'); // released
    }

    public function testPersistLoginFallsBackToAliasAndTenantHint(): void
    {
        // No extension members on the token response (older server).
        Common::persistLogin($this->path, 'default', self::BASE, [
            'access_token' => 'kc_a',
            'expires_in' => 3600,
        ], 'hinted');
        $onDisk = CredentialsFile::readProfile($this->path, 'default');
        $this->assertSame('knoxcall-cli', $onDisk['client_id']);
        $this->assertSame('hinted', $onDisk['tenant']);
        $this->assertArrayNotHasKey('refresh_token', $onDisk); // nulls dropped
    }

    // -- login command (device path, fully mocked) ----------------------------------------

    /** @return array<string, array{0: string}> */
    public static function deviceFlags(): array
    {
        return ['--device' => ['--device'], '--no-browser' => ['--no-browser']];
    }

    #[DataProvider('deviceFlags')]
    public function testLoginDeviceFlowWritesProfile(string $flag): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, [
            'device_code' => 'dc1',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => self::BASE . '/oauth/activate',
            'verification_uri_complete' => self::BASE . '/oauth/activate?user_code=ABCD-EFGH',
            'expires_in' => 900,
            'interval' => 5,
        ]);
        $transport->queueJson(200, self::tokenResponse(['access_token' => 'kc_dev', 'refresh_token' => 'rt_dev']));

        [$cli, $out, $err] = $this->cli($transport);
        $rc = $cli->run(['login', $flag, '--base-url', self::BASE]);
        $this->assertSame(0, $rc);
        $this->assertSame('', self::contents($err));

        parse_str((string) $transport->requests[0]['body'], $form);
        $this->assertSame(self::BASE . '/oauth/device_authorization', $transport->requests[0]['url']);
        $this->assertSame('knoxcall-cli', $form['client_id']);

        $record = CredentialsFile::readProfile($this->path, 'default');
        $this->assertSame('kc_cli_real', $record['client_id']);
        $this->assertSame('rt_dev', $record['refresh_token']);
        $this->assertSame('acme', $record['tenant']);
        $this->assertSame(self::BASE, $record['base_url']);

        $printed = self::contents($out);
        $this->assertStringContainsString('ABCD-EFGH', $printed); // user code shown prominently
        $this->assertStringContainsString('acme', $printed);
        $this->assertStringContainsString("(profile 'default')", $printed);
        $this->assertStringNotContainsString('kc_dev', $printed); // tokens never printed
        $this->assertStringNotContainsString('rt_dev', $printed);
    }

    public function testLoginRespectsProfileFlag(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, ['device_code' => 'dc1', 'user_code' => 'X', 'verification_uri' => 'u']);
        $transport->queueJson(200, self::tokenResponse(['access_token' => 'kc_p', 'refresh_token' => 'rt_p']));

        [$cli] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['login', '--device', '--base-url', self::BASE, '--profile', 'staging']));
        $this->assertSame('kc_p', CredentialsFile::readProfile($this->path, 'staging')['access_token']);
        $this->assertNull(CredentialsFile::readProfile($this->path, 'default'));
    }

    public function testLoginBaseUrlResolution(): void
    {
        // --sandbox (no --base-url, no env) targets the sandbox host…
        $transport = new MockTransport();
        $transport->queueJson(200, ['device_code' => 'dc1', 'user_code' => 'X', 'verification_uri' => 'u']);
        $transport->queueJson(200, self::tokenResponse());
        [$cli] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['login', '--device', '--sandbox']));
        $this->assertSame(
            'https://sandbox.knoxcall.com/oauth/device_authorization',
            $transport->requests[0]['url'],
        );
        $this->assertSame('https://sandbox.knoxcall.com', CredentialsFile::readProfile($this->path, 'default')['base_url']);

        // …and KNOXCALL_BASE_URL beats the default.
        putenv('KNOXCALL_BASE_URL=https://self-hosted.example.test');
        $transport = new MockTransport();
        $transport->queueJson(200, ['device_code' => 'dc1', 'user_code' => 'X', 'verification_uri' => 'u']);
        $transport->queueJson(200, self::tokenResponse());
        [$cli] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['login', '--device']));
        $this->assertSame(
            'https://self-hosted.example.test/oauth/device_authorization',
            $transport->requests[0]['url'],
        );
    }

    // -- logout ------------------------------------------------------------------------

    public function testLogoutRevokesAndRemovesProfile(): void
    {
        $this->seedProfile();
        $this->seedProfile([], 'work');
        $transport = new MockTransport();
        $transport->queueJson(200, []);

        [$cli, $out] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['logout', '--profile', 'work']));

        $revoke = $transport->lastRequest();
        $this->assertSame(self::BASE . '/oauth/revoke', $revoke['url']);
        parse_str((string) $revoke['body'], $form);
        $this->assertSame('rt_work', $form['token']);
        $this->assertSame('refresh_token', $form['token_type_hint']);
        $this->assertSame('kc_cli_real', $form['client_id']);

        $this->assertNull(CredentialsFile::readProfile($this->path, 'work'));
        $this->assertNotNull(CredentialsFile::readProfile($this->path, 'default')); // other profile kept
        $printed = self::contents($out);
        $this->assertStringContainsString("removed profile 'work'", $printed);
        $this->assertStringNotContainsString('rt_work', $printed); // tokens never printed

        // removing the last profile deletes the file
        $transport->queueJson(200, []);
        $this->assertSame(0, $cli->run(['logout']));
        $this->assertFileDoesNotExist($this->path);
    }

    public function testLogoutRemovesProfileEvenWhenRevokeFails(): void
    {
        $this->seedProfile();
        $this->seedProfile([], 'work');
        $transport = new MockTransport();
        $transport->queueThrow(new ConnectionException('server unreachable', false));

        [$cli] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['logout', '--profile', 'work']));
        $this->assertNull(CredentialsFile::readProfile($this->path, 'work'));
    }

    public function testLogoutWithoutCredentialsIsANoop(): void
    {
        [$cli, $out] = $this->cli(new MockTransport()); // queue stays empty: no revoke call
        $this->assertSame(0, $cli->run(['logout']));
        $this->assertStringContainsString('nothing to do', self::contents($out));
    }

    // -- whoami ------------------------------------------------------------------------

    public function testWhoamiPrintsTenantViaStoredCredentials(): void
    {
        $this->seedProfile();
        $transport = new MockTransport();
        $transport->queueJson(200, [
            'data' => ['slug' => 'acme', 'name' => 'Acme Inc', 'plan' => 'scale'],
            'meta' => ['request_id' => 'req-1'],
        ]);

        [$cli, $out, $err] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['whoami']));
        $this->assertSame('', self::contents($err));

        // stored token was fresh: the ONLY request is GET /v1/account at the
        // profile's base_url, no token mint
        $this->assertCount(1, $transport->requests);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame(self::BASE . '/v1/account', $transport->requests[0]['url']);

        $printed = self::contents($out);
        $this->assertStringContainsString('Tenant: Acme Inc', $printed);
        $this->assertStringContainsString('Slug:   acme', $printed);
        $this->assertStringContainsString('Plan:   scale', $printed);
        $this->assertStringContainsString("Profile: default ({$this->path})", $printed);
        $this->assertStringNotContainsString('kc_stored_fresh', $printed); // tokens never printed
    }

    // -- init --------------------------------------------------------------------------

    /** A {data, meta} account envelope, the real server shape. */
    private static function accountEnvelope(array $data = ['slug' => 'acme', 'name' => 'Acme Inc']): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-acct']];
    }

    public function testInitScaffoldPrintsQuickstartAndMakesNoWrites(): void
    {
        $this->seedProfile();
        $transport = new MockTransport();
        $transport->queueJson(200, self::accountEnvelope());

        [$cli, $out, $err] = $this->cli($transport);
        $this->assertSame(0, $cli->run(['init']));
        $this->assertSame('', self::contents($err));

        // Scaffold makes exactly one call — GET /v1/account — and writes nothing.
        $this->assertCount(1, $transport->requests);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame(self::BASE . '/v1/account', $transport->requests[0]['url']);
        foreach ($transport->requests as $request) {
            $this->assertStringNotContainsString('/v1/wrap/', $request['url']); // scaffold escrows nothing
        }

        $printed = self::contents($out);
        $this->assertStringContainsString('Signed in as Acme Inc', $printed);
        $this->assertStringContainsString('Wrap a provider SDK through KnoxCall', $printed);
        $this->assertStringContainsString('knoxcall init --provider stripe', $printed);
    }

    public function testInitEscrowMovesKeyIntoCustodyPrintsBaseUrlNeverPrintsKey(): void
    {
        $this->seedProfile();
        $transport = new MockTransport();
        $transport->queueJson(200, self::accountEnvelope());
        $transport->queueJson(200, ['data' => [
            'secret_id' => 'sec_1', 'name' => 'wrap-stripe', 'provider' => 'stripe',
            'allowed_hosts' => ['api.stripe.com'], 'sandbox' => false,
        ], 'meta' => []]);
        $transport->queueJson(200, ['data' => [
            'id' => 'tok_1', 'token' => 'wkt_x',
            'base_url' => 'https://acme.example.test/wg/wkt_x/api.stripe.com',
            'base_url_style' => 'path', 'host' => 'api.stripe.com',
            'secret_id' => 'sec_1', 'sandbox' => false, 'expires_at' => null,
        ], 'meta' => []]);

        putenv('KNOXCALL_WRAP_SECRET=sk_live_SECRET');

        [$cli, $out, $err] = $this->cli($transport);
        $rc = $cli->run(['init', '--provider', 'stripe', '--secret-name', 'wrap-stripe', '--host', 'api.stripe.com']);
        $this->assertSame(0, $rc);
        $this->assertSame('', self::contents($err));

        // account probe, then BOTH wrap endpoints, in order.
        $this->assertCount(3, $transport->requests);
        $this->assertSame(self::BASE . '/v1/account', $transport->requests[0]['url']);
        $this->assertSame(self::BASE . '/v1/wrap/credentials', $transport->requests[1]['url']);
        $this->assertSame(self::BASE . '/v1/wrap/tokens', $transport->requests[2]['url']);

        // The escrow body carries the key read from KNOXCALL_WRAP_SECRET (never a flag).
        $escrow = json_decode((string) $transport->requests[1]['body'], true);
        $this->assertSame('stripe', $escrow['provider']);
        $this->assertSame('wrap-stripe', $escrow['name']);
        $this->assertSame('sk_live_SECRET', $escrow['value']);
        $this->assertSame(['api.stripe.com'], $escrow['hosts']);

        $token = json_decode((string) $transport->requests[2]['body'], true);
        $this->assertSame('wrap-stripe', $token['secret']);
        $this->assertSame('api.stripe.com', $token['host']);

        $printed = self::contents($out);
        $this->assertStringContainsString('Signed in as Acme Inc', $printed);
        $this->assertStringContainsString('in KnoxCall custody', $printed);
        $this->assertStringContainsString('https://acme.example.test/wg/wkt_x/api.stripe.com', $printed);
        $this->assertStringNotContainsString('sk_live_SECRET', $printed); // the raw provider key is never printed
    }

    public function testInitEscrowTrimsSecretNameAndLowercasesHost(): void
    {
        $this->seedProfile();
        $transport = new MockTransport();
        $transport->queueJson(200, self::accountEnvelope());
        $transport->queueJson(200, ['data' => [
            'secret_id' => 'sec_1', 'name' => 'wrap-stripe', 'provider' => 'stripe',
            'allowed_hosts' => ['api.stripe.com'], 'sandbox' => false,
        ], 'meta' => []]);
        $transport->queueJson(200, ['data' => [
            'id' => 'tok_1', 'token' => 'wkt_x',
            'base_url' => 'https://acme.example.test/wg/wkt_x/api.stripe.com',
            'host' => 'api.stripe.com', 'secret_id' => 'sec_1', 'sandbox' => false, 'expires_at' => null,
        ], 'meta' => []]);

        putenv('KNOXCALL_WRAP_SECRET=sk_live_SECRET');

        [$cli] = $this->cli($transport);
        $rc = $cli->run(['init', '--provider', 'stripe', '--secret-name', '  wrap-stripe  ', '--host', 'API.Stripe.COM']);
        $this->assertSame(0, $rc);

        // The secret name is trimmed and the host is lowercased before they travel.
        $escrow = json_decode((string) $transport->requests[1]['body'], true);
        $this->assertSame('wrap-stripe', $escrow['name']);
        $this->assertSame(['api.stripe.com'], $escrow['hosts']);

        $token = json_decode((string) $transport->requests[2]['body'], true);
        $this->assertSame('wrap-stripe', $token['secret']);
        $this->assertSame('api.stripe.com', $token['host']);
    }

    public function testInitEscrowRequiresKeyInEnvVarNotAFlag(): void
    {
        $this->seedProfile();
        $transport = new MockTransport();
        $transport->queueJson(200, self::accountEnvelope());
        // KNOXCALL_WRAP_SECRET is cleared in setUp — there is no flag that supplies it.

        [$cli, $out, $err] = $this->cli($transport);
        $rc = $cli->run(['init', '--provider', 'stripe', '--secret-name', 'wrap-stripe', '--host', 'api.stripe.com']);
        $this->assertSame(1, $rc);

        $printed = self::contents($err);
        $this->assertStringStartsWith('error: ', $printed);
        $this->assertStringContainsString('KNOXCALL_WRAP_SECRET', $printed);

        // It never escrowed anything — only the account probe ran.
        foreach ($transport->requests as $request) {
            $this->assertStringNotContainsString('/v1/wrap/', $request['url']);
        }
    }

    public function testInitWithoutCredentialsPrintsHumanErrorAndExits1(): void
    {
        [$cli, $out, $err] = $this->cli();
        $this->assertSame(1, $cli->run(['init']));
        $this->assertSame('', self::contents($out));
        $printed = self::contents($err);
        $this->assertStringStartsWith('error: ', $printed);
        $this->assertStringContainsString('knoxcall login', $printed);
        $this->assertStringNotContainsString('Stack trace', $printed);
    }

    public function testInitAppearsInHelpAndHasItsOwnHelp(): void
    {
        [$cli, $out] = $this->cli();
        $this->assertSame(0, $cli->run(['--help']));
        $this->assertStringContainsString('init', self::contents($out));

        [$cli, $out] = $this->cli();
        $this->assertSame(0, $cli->run(['init', '--help']));
        $printed = self::contents($out);
        $this->assertStringContainsString('usage: knoxcall init', $printed);
        $this->assertStringContainsString('--provider', $printed);
        $this->assertStringContainsString('--secret-name', $printed);
        $this->assertStringContainsString('KNOXCALL_WRAP_SECRET', $printed);
    }

    // -- Error contract / exit codes ------------------------------------------------------

    public function testWhoamiWithoutCredentialsPrintsHumanErrorAndExits1(): void
    {
        [$cli, $out, $err] = $this->cli();
        $this->assertSame(1, $cli->run(['whoami']));
        $this->assertSame('', self::contents($out));
        $printed = self::contents($err);
        $this->assertStringStartsWith('error: ', $printed);
        $this->assertStringContainsString('knoxcall login', $printed);
        $this->assertStringNotContainsString('Stack trace', $printed);
    }

    public function testHelpExitsZero(): void
    {
        foreach ([['--help'], ['-h'], ['login', '--help'], ['logout', '-h'], ['whoami', '--help']] as $argv) {
            [$cli, $out, $err] = $this->cli();
            $this->assertSame(0, $cli->run($argv), implode(' ', $argv));
            $this->assertStringContainsString('usage: knoxcall', self::contents($out));
            $this->assertSame('', self::contents($err));
        }
        // subcommand help documents its flags
        [$cli, $out] = $this->cli();
        $cli->run(['login', '--help']);
        $this->assertStringContainsString('--no-browser', self::contents($out));
    }

    public function testUsageErrorsExit2(): void
    {
        foreach ([[], ['bogus'], ['login', '--bogus'], ['login', '--profile'], ['--tenant', 'x']] as $argv) {
            [$cli, $out, $err] = $this->cli();
            $this->assertSame(2, $cli->run($argv), implode(' ', $argv));
            $printed = self::contents($err);
            $this->assertStringContainsString('usage: knoxcall', $printed);
            $this->assertStringContainsString('knoxcall: error:', $printed);
            $this->assertSame('', self::contents($out));
        }
    }
}
