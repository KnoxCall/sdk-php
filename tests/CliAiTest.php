<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Cli\Ai;
use KnoxCall\Cli\ArgParser;
use KnoxCall\Cli\Cli;
use KnoxCall\Cli\UsageError;
use PHPUnit\Framework\TestCase;

/**
 * CLI tests — `knoxcall ai` (RFC 8693 workload federation + the AIGW-162
 * control plane).
 *
 * The python CLI is PARITY §13's reference implementation, so these mirror
 * sdk/knoxcall-python/tests/test_cli_ai.py and the node
 * test/cli-ai.test.ts assertion for assertion: the subject token comes from the
 * environment and never from argv, a host is required rather than guessed,
 * stdout carries the captured value and nothing else, and the exit codes are
 * 0 / 1 / 2.
 *
 * All HTTP goes through the house MockTransport, and credentials files live
 * under sys_get_temp_dir() only (KNOXCALL_CREDENTIALS_FILE pinned in setUp) —
 * a control-plane sub-command reads the same profile `login` writes, and must
 * never reach the developer's real ~/.knoxcall/credentials.json.
 */
final class CliAiTest extends TestCase
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
        Ai::SUBJECT_TOKEN_ENV,
        self::PROVIDER_KEY_ENV,
    ];

    /**
     * The variable `--secret-from-env` is pointed at. A test-only name: the
     * real one would be ANTHROPIC_API_KEY, and a developer running the suite
     * with that exported must not have their key escrowed by a mock.
     */
    private const PROVIDER_KEY_ENV = 'KNOXCALL_TEST_PROVIDER_KEY';

    private const BASE = 'https://api.example.test';

    /** @var list<resource> */
    private array $streams = [];

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->clearKnoxCallEnv();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'knoxcall-php-cli-ai-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'credentials.json';
        putenv('KNOXCALL_CREDENTIALS_FILE=' . $this->path);
    }

    protected function tearDown(): void
    {
        $this->clearKnoxCallEnv();
        foreach ($this->streams as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
        $this->streams = [];
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

    /**
     * A login-shaped profile with a FRESH access token, so the client makes no
     * refresh call and every request a test sees is one the command made.
     */
    private function seedProfile(string $profile = 'default'): void
    {
        CredentialsFile::writeProfile($this->path, $profile, [
            'tenant' => 'acme',
            'base_url' => self::BASE,
            'client_id' => 'kc_cli_real',
            'refresh_token' => 'rt_' . $profile,
            'access_token' => 'kc_stored_fresh',
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + 3600),
            'scope' => 'ai:write',
        ]);
    }

    /** A paginated {data, meta} envelope, the real server shape. */
    private static function page(array $rows): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
                'page' => 1,
                'per_page' => 100,
                'total_pages' => 1,
                'request_id' => 'req-1',
            ],
        ];
    }

    /** @return resource */
    private function stream()
    {
        $s = fopen('php://memory', 'w+');
        $this->streams[] = $s;
        return $s;
    }

    private static function okBody(): array
    {
        return [
            'access_token' => 'kc_live_agt_deadbeef',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ];
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function runCli(array $argv, ?MockTransport $t = null): array
    {
        $out = $this->stream();
        $err = $this->stream();
        $cli = new Cli($t, null, null, $out, $err);
        $code = $cli->run($argv);
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    // -- parsing ---------------------------------------------------------------

    public function testParserRegistersAiExchange(): void
    {
        $args = ArgParser::parse(['ai', 'exchange', '--tenant', 'acme']);
        $this->assertSame('ai', $args->command);
        $this->assertSame('exchange', $args->aiCommand);
        $this->assertSame('acme', $args->tenant);
    }

    public function testThereIsNoSubjectTokenFlag(): void
    {
        // An argv value lands in shell history, ps output and the CI log line,
        // so there must be no way to pass one. This asserts the ABSENCE of a
        // flag — adding `--subject-token` later would fail here.
        $this->expectException(UsageError::class);
        ArgParser::parse(['ai', 'exchange', '--subject-token', 'a.b.c']);
    }

    public function testUnknownAiSubcommandIsAUsageError(): void
    {
        [$code, , $err] = $this->runCli(['ai', 'nope']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString("invalid choice: 'nope'", $err);
        // The choice list is the whole group, not just `exchange` — otherwise a
        // typo'd `knoxcall ai mnit` is answered by a message that hides five of
        // the six sub-commands.
        $this->assertStringContainsString('{exchange,gateways,agents,create-agent,mint,usage}', $err);
    }

    public function testBareAiNamesEverySubcommand(): void
    {
        [$code, $out, $err] = $this->runCli(['ai']);
        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString(
            'the following arguments are required: {exchange,gateways,agents,create-agent,mint,usage}',
            $err,
        );
    }

    public function testAiHelpExitsZero(): void
    {
        // AIGW-162: the group grew from one sub-command to six. The help is the
        // only place a user discovers them, so it is asserted rather than
        // assumed.
        [$code, $out] = $this->runCli(['ai', '--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            'usage: knoxcall ai [-h] {exchange,gateways,agents,create-agent,mint,usage} ...',
            $out,
        );
        foreach (['exchange', 'gateways', 'agents', 'create-agent', 'mint', 'usage'] as $sub) {
            $this->assertStringContainsString($sub, $out);
        }
    }

    public function testEachAiSubcommandHasItsOwnHelp(): void
    {
        // One shared `ai` help page would document one sub-command's flags and
        // silently omit the other five.
        $expected = [
            'exchange' => '--resource RESOURCE',
            'gateways' => 'usage: knoxcall ai gateways',
            'agents' => '--gateway GATEWAY',
            'create-agent' => '--secret-from-env VAR',
            'mint' => '--kind KIND',
            'usage' => '--period PERIOD',
        ];
        foreach ($expected as $sub => $needle) {
            [$code, $out, $err] = $this->runCli(['ai', $sub, '--help']);
            $this->assertSame(0, $code, $sub);
            $this->assertStringContainsString("usage: knoxcall ai {$sub}", $out, $sub);
            $this->assertStringContainsString($needle, $out, $sub);
            $this->assertSame('', $err, $sub);
        }
    }

    // -- behaviour -------------------------------------------------------------

    public function testRefusesWhenTheEnvironmentVariableIsUnset(): void
    {
        [$code, , $err] = $this->runCli(['ai', 'exchange', '--tenant', 'acme']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString(Ai::SUBJECT_TOKEN_ENV, $err);
        $this->assertStringStartsWith('error: ', $err);
    }

    public function testRefusesToGuessAHost(): void
    {
        // api.knoxcall.com answers 401 for this request — the endpoint is not
        // served there — and that 401 reads as "your CI token was rejected".
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        [$code, , $err] = $this->runCli(['ai', 'exchange']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--tenant', $err);
        $this->assertStringContainsString('401', $err);
    }

    public function testPrintsOnlyTheTokenOnStdout(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=header.payload.sig');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        [$code, $out, $err] = $this->runCli(['ai', 'exchange', '--tenant', 'acme'], $t);

        $this->assertSame(0, $code);
        // stdout is captured with $(...), so it must be exactly the token.
        $this->assertSame("kc_live_agt_deadbeef\n", $out);
        $this->assertStringContainsString('agent token', $err);
        $this->assertSame('https://acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testDerivesTheSandboxHost(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        $this->runCli(['ai', 'exchange', '--tenant', 'acme', '--sandbox'], $t);
        $this->assertSame('https://sandbox-acme.knoxcall.com/v1/oauth/token', $t->requests[0]['url']);
    }

    public function testOmitsResourceUnlessGiven(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        $this->runCli(['ai', 'exchange', '--tenant', 'acme'], $t);

        $body = json_decode((string) $t->requests[0]['body'], true);
        $this->assertArrayNotHasKey('resource', $body);
    }

    public function testNarrowsWithResource(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        [$code, , $err] = $this->runCli(
            ['ai', 'exchange', '--tenant', 'acme', '--resource', 'https://acme.knoxcall.com/v1/mcp/gh'],
            $t,
        );

        $this->assertSame(0, $code);
        $body = json_decode((string) $t->requests[0]['body'], true);
        $this->assertSame('https://acme.knoxcall.com/v1/mcp/gh', $body['resource']);
        $this->assertStringContainsString('tool (MCP, resource-bound)', $err);
    }

    public function testExitsOneOnAServerRefusal(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(400, [
            'error' => 'invalid_grant',
            'error_description' => 'No tenant bindings registered',
        ]);

        [$code, , $err] = $this->runCli(['ai', 'exchange', '--tenant', 'acme'], $t);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('error: ', $err);
        $this->assertStringContainsString('No tenant bindings', $err);
    }

    public function testWarnsOnAPlaintextHop(): void
    {
        // The subject token IS a credential, so a plaintext hop leaks it.
        // PARITY 15 already warns when a CLIENT is constructed against plaintext
        // http; the exchange deliberately constructs no client, so the control
        // had to be added on that path too or it would exist on one and be
        // absent on the parallel one.
        \KnoxCall\Warn::resetForTests();
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        $seen = [];
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen[] = $msg;
            return true;
        }, \E_USER_WARNING);
        try {
            $this->runCli(['ai', 'exchange', '--base-url', 'http://evil.example'], $t);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('plaintext HTTP', implode("\n", $seen));
    }

    public function testDoesNotWarnForHttpsOrLoopback(): void
    {
        // The acceptance harness and local dev both use http://127.0.0.1, so a
        // refusal here would be wrong and a warning there would be noise.
        \KnoxCall\Warn::resetForTests();
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');

        $seen = [];
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen[] = $msg;
            return true;
        }, \E_USER_WARNING);
        try {
            foreach (['https://acme.test', 'http://127.0.0.1:3000'] as $base) {
                $t = new MockTransport();
                $t->queueJson(200, self::okBody());
                $this->runCli(['ai', 'exchange', '--base-url', $base], $t);
            }
        } finally {
            restore_error_handler();
        }

        $this->assertStringNotContainsString('plaintext HTTP', implode("\n", $seen));
    }

    public function testNeverPrintsTheTokenToStderr(): void
    {
        putenv(Ai::SUBJECT_TOKEN_ENV . '=a.b.c');
        $t = new MockTransport();
        $t->queueJson(200, self::okBody());

        [, , $err] = $this->runCli(['ai', 'exchange', '--tenant', 'acme'], $t);
        $this->assertStringNotContainsString('kc_live_agt_deadbeef', $err);
    }

    // == AIGW-162 — the control-plane sub-commands ==============================
    //
    // `exchange` above is the data-plane door and needs no login; these act as
    // the signed-in tenant. They exist because there was no CLI golden path at
    // all: the five SDK CLIs shipped `exchange` alone, and the capable
    // standalone `cli/` was unpublished, untested, un-CI'd, and could not create
    // a secret, a gateway or an agent — so it could not reach a first call
    // either.

    // -- parsing ---------------------------------------------------------------

    public function testParsesEverySubcommandWithItsOwnFlags(): void
    {
        $gw = ArgParser::parse(['ai', 'gateways']);
        $this->assertSame('gateways', $gw->aiCommand);

        $agents = ArgParser::parse(['ai', 'agents', '--gateway', 'gw_1']);
        $this->assertSame('agents', $agents->aiCommand);
        $this->assertSame('gw_1', $agents->gateway);

        $created = ArgParser::parse([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret-from-env', 'ANTHROPIC_API_KEY', '--model', 'claude-sonnet-5',
        ]);
        $this->assertSame('create-agent', $created->aiCommand);
        $this->assertSame('copilot', $created->slug);
        $this->assertSame('anthropic', $created->provider);
        $this->assertSame('ANTHROPIC_API_KEY', $created->secretFromEnv);
        $this->assertSame('claude-sonnet-5', $created->model);

        $mint = ArgParser::parse(['ai', 'mint', '--agent', 'ag_1', '--kind', 'read']);
        $this->assertSame('mint', $mint->aiCommand);
        $this->assertSame('ag_1', $mint->agent);
        $this->assertSame('read', $mint->kind);

        $usage = ArgParser::parse(['ai', 'usage', '--period', '7d']);
        $this->assertSame('usage', $usage->aiCommand);
        $this->assertSame('7d', $usage->period);
    }

    /**
     * @return list<array{0: list<string>}>
     */
    public static function foreignFlagProvider(): array
    {
        return [
            [['ai', 'exchange', '--period', '30d']],
            [['ai', 'gateways', '--agent', 'ag_1']],
            [['ai', 'mint', '--provider', 'anthropic']],
            [['ai', 'usage', '--secret-from-env', 'X']],
            [['ai', 'agents', '--resource', 'https://acme.knoxcall.com/v1/mcp/gh']],
        ];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignFlagProvider')]
    public function testTheFlagTableIsPerSubcommandNotSharedAcrossTheAiGroup(array $argv): void
    {
        // One flat table keyed by `ai` would accept `ai exchange --period 30d`
        // and silently ignore it, which is the opposite of what every other
        // command does with an unknown flag. Each argv here uses a flag that
        // exists on a DIFFERENT ai sub-command, so a shared table would let all
        // of them through.
        $this->expectException(UsageError::class);
        ArgParser::parse($argv);
    }

    public function testForeignFlagsAlsoExitTwoThroughTheDispatcher(): void
    {
        [$code, $out, $err] = $this->runCli(['ai', 'exchange', '--period', '30d']);
        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('unrecognized arguments: --period', $err);
    }

    public function testThereIsNoSecretValueFlag(): void
    {
        // Same rule as --subject-token: the key is read from the environment
        // named by --secret-from-env. This asserts the ABSENCE of a flag, so
        // adding --secret-value later fails here rather than in someone's shell
        // history, ps output or CI log.
        $this->expectException(UsageError::class);
        ArgParser::parse(['ai', 'create-agent', '--slug', 'x', '--secret-value', 'sk-ant-live']);
    }

    /** @return list<array{0: list<string>}> */
    public static function positionalProvider(): array
    {
        return [
            [['ai', 'agents', 'gw_1']],
            [['ai', 'mint', 'ag_1']],
            [['ai', 'gateways', 'extra']],
        ];
    }

    /** @param list<string> $argv */
    #[\PHPUnit\Framework\Attributes\DataProvider('positionalProvider')]
    public function testIdsAreFlagsNeverPositionals(array $argv): void
    {
        // Four of the five SDK CLIs hand-roll their parser and reject
        // positionals outright, so a positional id would be a surface that
        // differs by language.
        $this->expectException(UsageError::class);
        ArgParser::parse($argv);
    }

    // -- refusals that happen before any HTTP ----------------------------------

    public function testRefusesToCreateAnAgentWithNoUpstreamCredential(): void
    {
        // The API ACCEPTS this and stores an agent whose first data-plane call
        // 502s (AIGW-161). A command whose whole purpose is reaching a working
        // call must not be able to produce one.
        [$code, $out, $err] = $this->runCli(['ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('one of --secret or --secret-from-env is required', $err);
        $this->assertStringContainsString('502s on its first call', $err);
    }

    public function testRequiresProviderOnCreateAgent(): void
    {
        [$code, , $err] = $this->runCli(['ai', 'create-agent', '--slug', 'copilot', '--secret', 'sec_1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--provider is required', $err);
    }

    public function testRequiresAgentOnMintAndGatewayOnAgents(): void
    {
        [$code, , $err] = $this->runCli(['ai', 'mint']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--agent is required', $err);

        [$code, , $err] = $this->runCli(['ai', 'agents']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--gateway is required', $err);
    }

    public function testRefusesWhenNotLoggedIn(): void
    {
        // KNOXCALL_CREDENTIALS_FILE points at a path that was never written.
        foreach ([['ai', 'gateways'], ['ai', 'usage'], ['ai', 'mint', '--agent', 'ag_1']] as $argv) {
            [$code, , $err] = $this->runCli($argv);
            $this->assertSame(1, $code, implode(' ', $argv));
            $this->assertStringContainsString("not logged in (profile 'default')", $err);
            $this->assertStringContainsString('knoxcall login', $err);
        }
    }

    public function testRefusesWhenTheProviderKeyEnvironmentVariableIsUnset(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default']]));

        [$code, , $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret-from-env', self::PROVIDER_KEY_ENV,
        ], $t);

        $this->assertSame(1, $code);
        $this->assertStringContainsString(self::PROVIDER_KEY_ENV . ' is not set', $err);
        $this->assertStringContainsString('no --secret-value flag', $err);
    }

    // -- behaviour against the signed-in tenant --------------------------------

    public function testGatewaysListsIdSlugName(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([
            ['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default'],
            ['id' => 'gw_2', 'slug' => 'eu', 'name' => 'EU'],
        ]));

        [$code, $out] = $this->runCli(['ai', 'gateways'], $t);
        $this->assertSame(0, $code);
        $this->assertSame("gw_1  default  Default\ngw_2  eu  EU\n", $out);
        $this->assertSame(self::BASE . '/v1/ai-gateway/gateways?per_page=100', $t->requests[0]['url']);
    }

    public function testGatewaysSaysHowToCreateOneWhenThereAreNone(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([]));

        [$code, $out, $err] = $this->runCli(['ai', 'gateways'], $t);
        $this->assertSame(0, $code);
        // Nothing on stdout, so `knoxcall ai gateways | head -1` is empty
        // rather than a sentence a script would try to parse as an id.
        $this->assertSame('', $out);
        $this->assertStringContainsString('knoxcall ai create-agent', $err);
    }

    public function testAgentsPrintsTheAgentUrlAsTheThirdColumn(): void
    {
        // agent_url is on every projection since AIGW-161, so a list is enough
        // to point an SDK at an existing agent — no follow-up GET.
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([
            ['id' => 'ag_1', 'slug' => 'copilot', 'agent_url' => 'https://acme.knoxcall.com/v1/ai/copilot'],
        ]));

        [$code, $out] = $this->runCli(['ai', 'agents', '--gateway', 'gw_1'], $t);
        $this->assertSame(0, $code);
        $this->assertSame("ag_1  copilot  https://acme.knoxcall.com/v1/ai/copilot\n", $out);
        $this->assertSame(
            self::BASE . '/v1/ai-gateway/gateways/gw_1/agents?per_page=100',
            $t->requests[0]['url'],
        );
    }

    public function testCreateAgentEscrowsTheKeyAndPrintsOnlyTheIdOnStdout(): void
    {
        $this->seedProfile();
        putenv(self::PROVIDER_KEY_ENV . '=sk-ant-live-abc');
        $t = new MockTransport();
        $t->queueJson(200, self::page([['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default']]));
        $t->queueJson(200, self::page([]));                                   // secrets list: no reuse
        $t->queueJson(201, ['data' => ['id' => 'sec_new', 'name' => 'ai-gateway-copilot-key']]);
        $t->queueJson(201, ['data' => [
            'id' => 'ag_new',
            'slug' => 'copilot',
            'agent_url' => 'https://acme.knoxcall.com/v1/ai/copilot',
        ]]);

        [$code, $out, $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret-from-env', self::PROVIDER_KEY_ENV,
        ], $t);

        $this->assertSame(0, $code);
        // stdout is captured with $(...), so it must be exactly the agent id.
        $this->assertSame("ag_new\n", $out);
        $this->assertStringContainsString('escrowed secret', $err);
        $this->assertStringContainsString('base_url:  https://acme.knoxcall.com/v1/ai/copilot', $err);
        // The literal next command, so the golden path needs no docs.
        $this->assertStringContainsString('Next:  knoxcall ai mint --agent ag_new', $err);
        // The key is escrowed, and never echoed back at the operator.
        $this->assertStringNotContainsString('sk-ant-live-abc', $out . $err);

        $secretBody = json_decode((string) $t->requests[2]['body'], true);
        $this->assertSame(self::BASE . '/v1/secrets', $t->requests[2]['url']);
        $this->assertSame('ai-gateway-copilot-key', $secretBody['name']);
        $this->assertSame('sk-ant-live-abc', $secretBody['value']);

        $agentBody = json_decode((string) $t->requests[3]['body'], true);
        $this->assertSame(self::BASE . '/v1/ai-gateway/gateways/gw_1/agents', $t->requests[3]['url']);
        $this->assertSame('copilot', $agentBody['slug']);
        $this->assertSame('copilot', $agentBody['name']); // --name defaults to --slug
        $this->assertSame('anthropic', $agentBody['provider']);
        $this->assertSame('sec_new', $agentBody['upstream_secret_id']);
    }

    public function testCreateAgentReusesASecretOfTheSameNameRatherThanDuplicating(): void
    {
        $this->seedProfile();
        putenv(self::PROVIDER_KEY_ENV . '=sk-ant-live-abc');
        $t = new MockTransport();
        $t->queueJson(200, self::page([['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default']]));
        $t->queueJson(200, self::page([['id' => 'sec_old', 'name' => 'ai-gateway-copilot-key']]));
        $t->queueJson(201, ['data' => ['id' => 'ag_new', 'slug' => 'copilot', 'agent_url' => '']]);

        [$code, , $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret-from-env', self::PROVIDER_KEY_ENV,
        ], $t);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("reusing secret 'ai-gateway-copilot-key' (sec_old)", $err);
        // Three requests, not four: no second copy of the same credential.
        $this->assertCount(3, $t->requests);
        $this->assertSame('sec_old', json_decode((string) $t->requests[2]['body'], true)['upstream_secret_id']);
        // agent_url is '' when the tenant slug cannot be resolved — "not
        // available", never printed as if it were a URL.
        $this->assertStringNotContainsString('base_url:', $err);
    }

    public function testCreateAgentCreatesADefaultGatewayOnAFreshTenant(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([]));                                     // no gateways at all
        $t->queueJson(201, ['data' => ['id' => 'gw_made', 'slug' => 'default', 'name' => 'Default']]);
        $t->queueJson(201, ['data' => ['id' => 'ag_new', 'slug' => 'copilot', 'agent_url' => '']]);

        [$code, $out, $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic', '--secret', 'sec_1',
        ], $t);

        $this->assertSame(0, $code);
        $this->assertSame("ag_new\n", $out);
        $this->assertStringContainsString('created gateway default (gw_made)', $err);
        $this->assertSame(self::BASE . '/v1/ai-gateway/gateways', $t->requests[1]['url']);
        $this->assertSame(self::BASE . '/v1/ai-gateway/gateways/gw_made/agents', $t->requests[2]['url']);
    }

    public function testCreateAgentRefusesToPickBetweenSeveralGateways(): void
    {
        // "whichever sorts first" is how the quickstart wizard silently landed
        // a second agent in the wrong gateway.
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([
            ['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default'],
            ['id' => 'gw_2', 'slug' => 'eu', 'name' => 'EU'],
        ]));

        [$code, $out, $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic', '--secret', 'sec_1',
        ], $t);

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('--gateway is required: this tenant has 2 gateways', $err);
        $this->assertStringContainsString('default (gw_1), eu (gw_2)', $err);
        $this->assertCount(1, $t->requests); // nothing was created
    }

    public function testCreateAgentResolvesAGatewaySlug(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([
            ['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default'],
            ['id' => 'gw_2', 'slug' => 'eu', 'name' => 'EU'],
        ]));
        $t->queueJson(201, ['data' => ['id' => 'ag_new', 'slug' => 'copilot', 'agent_url' => '']]);

        [$code] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret', 'sec_1', '--gateway', 'eu',
        ], $t);

        $this->assertSame(0, $code);
        $this->assertSame(self::BASE . '/v1/ai-gateway/gateways/gw_2/agents', $t->requests[1]['url']);
    }

    public function testCreateAgentNamesTheGatewaysWhenTheOneAskedForIsUnknown(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default']]));

        [$code, , $err] = $this->runCli([
            'ai', 'create-agent', '--slug', 'copilot', '--provider', 'anthropic',
            '--secret', 'sec_1', '--gateway', 'nope',
        ], $t);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("no gateway 'nope' — this tenant has: default (gw_1)", $err);
    }

    public function testMintPrintsOnlyTheTokenOnStdout(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(201, ['data' => [
            'id' => 'tok_1',
            'kind' => 'read',
            'prefix' => 'kc_live_agt_dead',
            'token' => 'kc_live_agt_deadbeef',
            'dpop_required' => false,
            'expires_at' => '2026-10-07T00:00:00.000Z',
        ]]);

        [$code, $out, $err] = $this->runCli(['ai', 'mint', '--agent', 'ag_1', '--kind', 'read'], $t);

        $this->assertSame(0, $code);
        // The plaintext is returned ONCE, and `> token.txt` must capture the
        // token and nothing else.
        $this->assertSame("kc_live_agt_deadbeef\n", $out);
        $this->assertStringNotContainsString('kc_live_agt_deadbeef', $err);
        $this->assertStringContainsString('kind:     read', $err);
        // A JSON boolean, rendered the way the other four CLIs render it —
        // PHP would otherwise print `1` for true and nothing at all for false.
        $this->assertStringContainsString('dpop:     false', $err);
        $this->assertStringContainsString('expires:  2026-10-07T00:00:00.000Z', $err);
        $this->assertStringContainsString('Save this token now — it will not be shown again.', $err);

        $this->assertSame(self::BASE . '/v1/ai-gateway/agents/ag_1/tokens', $t->requests[0]['url']);
        $this->assertSame(['kind' => 'read'], json_decode((string) $t->requests[0]['body'], true));
    }

    public function testMintSaysNeverForATokenWithNoExpiry(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(201, ['data' => [
            'id' => 'tok_1', 'kind' => 'agent', 'prefix' => 'kc_live', 'token' => 'kc_live_x',
            'dpop_required' => true, 'expires_at' => null,
        ]]);

        [, , $err] = $this->runCli(['ai', 'mint', '--agent', 'ag_1'], $t);
        $this->assertStringContainsString('expires:  never', $err);
        $this->assertStringContainsString('dpop:     true', $err);
        // No --kind/--name given: an empty body, not `{"kind":null}`.
        $this->assertSame([], json_decode((string) $t->requests[0]['body'], true));
    }

    public function testUsageDefaultsToThirtyDaysAndFormatsCostToFourPlaces(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, ['data' => [
            'period_days' => 30,
            'totals' => [
                'requests' => 12,
                'input_tokens' => 3400,
                'output_tokens' => 900,
                'cost_usd' => 1.5,
                'unpriced_requests' => 2,
            ],
            'by_model' => [
                [
                    'provider' => 'anthropic',
                    'model' => 'claude-sonnet-5',
                    'requests' => 12,
                    'input_tokens' => 3400,
                    'output_tokens' => 900,
                    'cost_usd' => '1.5',
                ],
            ],
        ]]);

        [$code, $out] = $this->runCli(['ai', 'usage'], $t);
        $this->assertSame(0, $code);
        $this->assertSame(self::BASE . '/v1/ai-gateway/usage?period=30d', $t->requests[0]['url']);
        $this->assertStringContainsString('Usage — last 30 days', $out);
        $this->assertStringContainsString('cost (USD):    1.5000', $out);
        $this->assertStringContainsString('unpriced:      2', $out);
        // A numeric STRING from the server formats the same as a float —
        // Postgres numerics arrive as strings over JSON.
        $this->assertStringContainsString('anthropic/claude-sonnet-5  12 req  in 3400  out 900  $1.5000', $out);
    }

    public function testUsageScopesToOneAgentAndSaysSoWhenThereIsNothing(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, ['data' => [
            'period_days' => 7,
            'totals' => [
                'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
                'cost_usd' => 0, 'unpriced_requests' => 0,
            ],
            'by_model' => [],
        ]]);

        [$code, $out] = $this->runCli(['ai', 'usage', '--period', '7d', '--agent', 'ag_1'], $t);
        $this->assertSame(0, $code);
        $this->assertSame(self::BASE . '/v1/ai-gateway/usage?period=7d&agent_id=ag_1', $t->requests[0]['url']);
        $this->assertStringContainsString('Usage — last 7 days (agent ag_1)', $out);
        $this->assertStringContainsString('No usage in this period.', $out);
    }

    public function testControlPlaneUsesTheNamedProfile(): void
    {
        // --profile selects WHICH stored login acts, exactly as whoami's does.
        $this->seedProfile('work');
        $t = new MockTransport();
        $t->queueJson(200, self::page([['id' => 'gw_1', 'slug' => 'default', 'name' => 'Default']]));

        [$code, $out] = $this->runCli(['ai', 'gateways', '--profile', 'work'], $t);
        $this->assertSame(0, $code);
        $this->assertSame("gw_1  default  Default\n", $out);

        [$code, , $err] = $this->runCli(['ai', 'gateways', '--profile', 'missing']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("not logged in (profile 'missing')", $err);
    }

    public function testBaseUrlOverridesTheStoredProfile(): void
    {
        $this->seedProfile();
        $t = new MockTransport();
        $t->queueJson(200, self::page([]));

        [$code] = $this->runCli(['ai', 'gateways', '--base-url', 'https://self.hosted.test'], $t);
        $this->assertSame(0, $code);
        $this->assertSame('https://self.hosted.test/v1/ai-gateway/gateways?per_page=100', $t->requests[0]['url']);
    }
}
