<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

/**
 * Minimal argument parser for the `knoxcall` CLI — same command surface,
 * flags, and help semantics as the python reference (argparse):
 *
 *   knoxcall login [--tenant SLUG] [--base-url URL] [--sandbox]
 *                  [--profile NAME] [--device] [--no-browser]
 *   knoxcall logout [--profile NAME]
 *   knoxcall whoami [--profile NAME]
 *   knoxcall init [--profile NAME] [--base-url URL] [--sandbox]
 *                 [--provider PROVIDER] [--secret-name NAME] [--host HOST]
 *   knoxcall ai {exchange,gateways,agents,create-agent,mint,usage}
 *
 * `--help`/`-h` (top-level, per command, or per `ai` sub-command) prints help
 * and exits 0; usage errors (missing/unknown command, unknown flag, missing
 * flag value, any positional) raise UsageError, which the dispatcher prints
 * argparse-style and exits 2. Both `--flag value` and `--flag=value` spellings
 * are accepted.
 */
final class ArgParser
{
    private const COMMANDS = ['login', 'logout', 'whoami', 'init', 'ai'];

    /**
     * `ai` is the only command with a sub-command of its own. AIGW-162 grew the
     * group from one to six: `exchange` is the data-plane door and needs no
     * login (the CI workload's OIDC token IS the credential), while the other
     * five are the control plane and act as the signed-in tenant. Both live
     * under `ai` because they are one surface to a user, and the golden path
     * crosses between them: create-agent -> mint -> a real call.
     *
     * Every one of them takes FLAGS ONLY, no positionals. Four of the five SDK
     * CLIs hand-roll their parser and reject positionals outright (only python
     * gets them free from argparse), so an id as a positional would be a
     * surface that is the same in all five except in shape.
     */
    private const AI_COMMANDS = ['exchange', 'gateways', 'agents', 'create-agent', 'mint', 'usage'];

    /** @var array<string, array<string, string>> flag => ParsedArgs property */
    private const VALUE_FLAGS = [
        'login' => ['--tenant' => 'tenant', '--base-url' => 'baseUrl', '--profile' => 'profile'],
        'logout' => ['--profile' => 'profile'],
        'whoami' => ['--profile' => 'profile'],
        'init' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
            '--provider' => 'provider',
            '--secret-name' => 'secretName',
            '--host' => 'host',
        ],
        // `ai` has no table of its own — see AI_VALUE_FLAGS.
    ];

    /** @var array<string, array<string, string>> flag => ParsedArgs property */
    private const BOOL_FLAGS = [
        'login' => ['--sandbox' => 'sandbox', '--device' => 'device', '--no-browser' => 'noBrowser'],
        'logout' => [],
        'whoami' => [],
        'init' => ['--sandbox' => 'sandbox'],
    ];

    /**
     * Keyed by SUB-command, not by `ai`. A single flat table would accept
     * `ai exchange --period 30d` and silently ignore it, which is the opposite
     * of what every other command here does with an unknown flag (usage error,
     * exit 2). Written out per sub-command rather than composed, so that
     * reading one row tells you exactly what that sub-command accepts.
     *
     * @var array<string, array<string, string>> flag => ParsedArgs property
     */
    private const AI_VALUE_FLAGS = [
        'exchange' => [
            '--tenant' => 'tenant',
            '--base-url' => 'baseUrl',
            '--resource' => 'resource',
            '--audience' => 'audience',
        ],
        'gateways' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
        ],
        'agents' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
            '--gateway' => 'gateway',
        ],
        'create-agent' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
            '--gateway' => 'gateway',
            '--name' => 'name',
            '--slug' => 'slug',
            '--provider' => 'provider',
            // The key itself is NEVER a flag value: `--secret` is the id of a
            // secret KnoxCall already holds, `--secret-from-env` NAMES the
            // environment variable to read it from. There is deliberately no
            // `--secret-value` — an argv value lands in shell history, ps
            // output and the CI log line that echoes the command.
            '--secret' => 'secret',
            '--secret-from-env' => 'secretFromEnv',
            '--upstream' => 'upstream',
            '--model' => 'model',
        ],
        'mint' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
            '--agent' => 'agent',
            '--kind' => 'kind',
            '--name' => 'name',
        ],
        'usage' => [
            '--profile' => 'profile',
            '--base-url' => 'baseUrl',
            '--period' => 'period',
            '--agent' => 'agent',
        ],
    ];

    /** @var array<string, array<string, string>> flag => ParsedArgs property */
    private const AI_BOOL_FLAGS = [
        'exchange' => ['--sandbox' => 'sandbox'],
        'gateways' => ['--sandbox' => 'sandbox'],
        'agents' => ['--sandbox' => 'sandbox'],
        'create-agent' => ['--sandbox' => 'sandbox'],
        'mint' => ['--sandbox' => 'sandbox'],
        'usage' => ['--sandbox' => 'sandbox'],
    ];

    private function __construct()
    {
    }

    /** `exchange,gateways,agents,create-agent,mint,usage` — the argparse choice list. */
    private static function aiChoices(): string
    {
        return implode(',', self::AI_COMMANDS);
    }

    /** @param list<string> $argv arguments WITHOUT the program name */
    public static function parse(array $argv): ParsedArgs
    {
        $args = new ParsedArgs();
        $argv = array_values($argv);
        $n = count($argv);

        if ($n === 0) {
            throw new UsageError(
                'the following arguments are required: {login,logout,whoami,init,ai}',
                self::usage(null),
            );
        }

        $first = $argv[0];
        if ($first === '-h' || $first === '--help') {
            $args->help = true;
            return $args;
        }
        if (str_starts_with($first, '-')) {
            throw new UsageError("unrecognized arguments: {$first}", self::usage(null));
        }
        if (!in_array($first, self::COMMANDS, true)) {
            throw new UsageError(
                "argument {login,logout,whoami,init,ai}: invalid choice: '{$first}'",
                self::usage(null),
            );
        }
        $args->command = $first;

        // The flag table and the usage line are bound to the command here, then
        // one loop parses the rest. For `ai` they are re-bound to the SUB-command
        // below, because each `ai` sub-command has its own flags.
        $valueFlags = self::VALUE_FLAGS[$first] ?? [];
        $boolFlags = self::BOOL_FLAGS[$first] ?? [];
        $usageKey = $first;

        // `ai` carries a sub-command. Consume it here, then fall through to the
        // same flag loop with the cursor advanced past it — one parser, not two.
        $start = 1;
        if ($first === 'ai') {
            $choices = self::aiChoices();
            if ($n < 2) {
                throw new UsageError(
                    "the following arguments are required: {{$choices}}",
                    self::usage('ai'),
                );
            }
            $second = $argv[1];
            if ($second === '-h' || $second === '--help') {
                $args->help = true;
                return $args;
            }
            if (!in_array($second, self::AI_COMMANDS, true)) {
                throw new UsageError(
                    "argument {{$choices}}: invalid choice: '{$second}'",
                    self::usage('ai'),
                );
            }
            $args->aiCommand = $second;
            $valueFlags = self::AI_VALUE_FLAGS[$second];
            $boolFlags = self::AI_BOOL_FLAGS[$second];
            $usageKey = 'ai ' . $second;
            $start = 2;
        }

        for ($i = $start; $i < $n; $i++) {
            $token = $argv[$i];
            if ($token === '-h' || $token === '--help') {
                $args->help = true;
                return $args;
            }

            // Accept --flag=value alongside --flag value.
            $name = $token;
            $inline = null;
            if (str_starts_with($token, '--') && ($eq = strpos($token, '=')) !== false) {
                $name = substr($token, 0, $eq);
                $inline = substr($token, $eq + 1);
            }

            if (isset($valueFlags[$name])) {
                if ($inline === null) {
                    if ($i + 1 >= $n) {
                        throw new UsageError("argument {$name}: expected one argument", self::usage($usageKey));
                    }
                    $inline = $argv[++$i];
                }
                $property = $valueFlags[$name];
                $args->{$property} = $inline;
                continue;
            }
            if ($inline === null && isset($boolFlags[$name])) {
                $property = $boolFlags[$name];
                $args->{$property} = true;
                continue;
            }
            // Also the positional rejection: ids are flags (`--gateway`,
            // `--agent`) in every SDK CLI, so `ai agents gw_1` is an error and
            // not a second way to spell the same thing.
            throw new UsageError("unrecognized arguments: {$token}", self::usage($usageKey));
        }
        return $args;
    }

    public static function usage(?string $command): string
    {
        return match ($command) {
            'login' => 'usage: knoxcall login [-h] [--tenant TENANT] [--base-url BASE_URL] [--sandbox]'
                . "\n                      [--profile PROFILE] [--device] [--no-browser]",
            'logout' => 'usage: knoxcall logout [-h] [--profile PROFILE]',
            'whoami' => 'usage: knoxcall whoami [-h] [--profile PROFILE]',
            'init' => 'usage: knoxcall init [-h] [--profile PROFILE] [--base-url BASE_URL] [--sandbox]'
                . "\n                     [--provider PROVIDER] [--secret-name NAME] [--host HOST]",
            'ai' => 'usage: knoxcall ai [-h] {' . self::aiChoices() . '} ...',
            'ai exchange' => 'usage: knoxcall ai exchange [-h] [--tenant TENANT] [--sandbox]'
                . "\n                            [--base-url BASE_URL] [--resource RESOURCE]"
                . "\n                            [--audience AUDIENCE]",
            'ai gateways' => 'usage: knoxcall ai gateways [-h] [--profile PROFILE] [--base-url BASE_URL] [--sandbox]',
            'ai agents' => 'usage: knoxcall ai agents [-h] --gateway GATEWAY [--profile PROFILE]'
                . "\n                          [--base-url BASE_URL] [--sandbox]",
            'ai create-agent' => 'usage: knoxcall ai create-agent [-h] --slug SLUG --provider PROVIDER'
                . "\n                                (--secret SECRET | --secret-from-env VAR)"
                . "\n                                [--name NAME] [--gateway GATEWAY] [--model MODEL]"
                . "\n                                [--upstream URL] [--profile PROFILE]"
                . "\n                                [--base-url BASE_URL] [--sandbox]",
            'ai mint' => 'usage: knoxcall ai mint [-h] --agent AGENT [--kind KIND] [--name NAME]'
                . "\n                        [--profile PROFILE] [--base-url BASE_URL] [--sandbox]",
            'ai usage' => 'usage: knoxcall ai usage [-h] [--period PERIOD] [--agent AGENT]'
                . "\n                         [--profile PROFILE] [--base-url BASE_URL] [--sandbox]",
            default => 'usage: knoxcall [-h] {login,logout,whoami,init,ai} ...',
        };
    }

    public static function help(?string $command): string
    {
        $usage = self::usage($command) . "\n\n";
        $choices = self::aiChoices();
        return match ($command) {
            'login' => $usage
                . "sign in with your browser and store credentials locally\n\n"
                . "options:\n"
                . "  -h, --help           show this help message and exit\n"
                . "  --tenant TENANT      tenant slug hint for the sign-in page\n"
                . "  --base-url BASE_URL  management API base URL (default\n"
                . "                       https://api.knoxcall.com, or KNOXCALL_BASE_URL)\n"
                . "  --sandbox            log in against the sandbox environment\n"
                . "  --profile PROFILE    credentials profile name (default: KNOXCALL_PROFILE\n"
                . "                       or 'default')\n"
                . "  --device             use the device-code flow (headless/SSH machines)\n"
                . "  --no-browser         never open a browser (implies the device-code flow)\n",
            'logout' => $usage
                . "revoke and remove stored credentials\n\n"
                . "options:\n"
                . "  -h, --help         show this help message and exit\n"
                . "  --profile PROFILE  credentials profile name (default: KNOXCALL_PROFILE\n"
                . "                     or 'default')\n",
            'whoami' => $usage
                . "show the signed-in tenant\n\n"
                . "options:\n"
                . "  -h, --help         show this help message and exit\n"
                . "  --profile PROFILE  credentials profile name (default: KNOXCALL_PROFILE\n"
                . "                     or 'default')\n",
            'init' => $usage
                . "Get started wrapping a provider SDK through KnoxCall. Works against the tenant you\n"
                . "are already signed in to — it does NOT provision a tenant. With no --provider it\n"
                . "prints a quickstart; with --provider it escrows a key (read from the\n"
                . "KNOXCALL_WRAP_SECRET env var, never a flag) and prints the gateway base_url.\n\n"
                . "options:\n"
                . "  -h, --help           show this help message and exit\n"
                . "  --profile PROFILE    credentials profile name (default: KNOXCALL_PROFILE or 'default')\n"
                . "  --base-url BASE_URL  management API base URL (default https://api.knoxcall.com)\n"
                . "  --sandbox            operate against the sandbox environment\n"
                . "  --provider PROVIDER  provider to escrow a key for (e.g. stripe); enables escrow mode\n"
                . "  --secret-name NAME   name for the escrowed credential (required with --provider)\n"
                . "  --host HOST          upstream host to pin the credential to (required with --provider)\n",
            'ai' => $usage
                . "AI-gateway operations.\n\n"
                . "From a tenant with nothing in it to a real streamed call, in two commands:\n\n"
                . "    export ANTHROPIC_API_KEY=sk-ant-...\n"
                . "    knoxcall ai create-agent --name copilot --slug copilot \\\n"
                . "        --provider anthropic --secret-from-env ANTHROPIC_API_KEY\n"
                . "    knoxcall ai mint --agent <id>\n\n"
                . "positional arguments:\n"
                . "  {{$choices}}\n"
                . "    exchange            exchange a CI OIDC token for a capability token (no login needed)\n"
                . "    gateways            list AI gateways\n"
                . "    agents              list a gateway's agents\n"
                . "    create-agent        create an agent with its upstream credential\n"
                . "    mint                mint a capability token (shown once)\n"
                . "    usage               cost + token usage by model\n\n"
                . "options:\n"
                . "  -h, --help            show this help message and exit\n",
            'ai exchange' => $usage
                . "Exchange a CI workload's OIDC id_token for a short-lived AI-gateway capability\n"
                . "token (RFC 8693). Needs no KnoxCall credential and no `knoxcall login`: the\n"
                . "subject token IS the credential.\n\n"
                . "The subject token is read from the KNOXCALL_SUBJECT_TOKEN environment variable,\n"
                . "never a flag — an argv value lands in shell history, ps output and the CI log.\n\n"
                . "Only the token is printed to stdout, so it can be captured:\n"
                . "    export KC_TOKEN=\"\$(knoxcall ai exchange --tenant acme)\"\n\n"
                . "options:\n"
                . "  -h, --help           show this help message and exit\n"
                . "  --tenant TENANT      tenant slug; the data-plane host is\n"
                . "                       https://{tenant}.knoxcall.com\n"
                . "  --sandbox            use the Test data space (sandbox-{tenant}.knoxcall.com)\n"
                . "  --base-url BASE_URL  full data-plane origin; overrides --tenant\n"
                . "  --resource RESOURCE  RFC 8707 resource indicator (an MCP server's\n"
                . "                       `resource`); narrows the token to that one MCP server\n"
                . "  --audience AUDIENCE  defaults to knoxcall:gateway\n",
            'ai gateways' => $usage
                . "List this tenant's AI gateways as `id  slug  name`.\n\n"
                . 'options:' . self::AI_COMMON_HELP,
            'ai agents' => $usage
                . "List a gateway's agents as `id  slug  agent_url`. The third column is the\n"
                . "base_url to point an AI SDK at, so this is enough to wire up an existing\n"
                . "agent without a second call.\n\n"
                . "options:\n"
                . '  --gateway GATEWAY    gateway id' . self::AI_COMMON_HELP,
            'ai create-agent' => $usage
                . "Create an agent wired to a provider credential, and print the command that\n"
                . "follows. Works on a tenant with nothing in it: with no --gateway it uses your\n"
                . "only gateway, or creates one when you have none. With several it refuses and\n"
                . "lists them rather than picking one for you.\n\n"
                . "The provider key is read from the environment named by --secret-from-env,\n"
                . "never from a flag — an argv value lands in shell history, ps output and the CI\n"
                . "log. There is deliberately no --secret-value.\n\n"
                . "--provider and a credential are both required: the API accepts an agent with\n"
                . "neither and stores one whose first data-plane call 502s.\n\n"
                . "Only the agent id goes to stdout, so it can be captured:\n"
                . "    AGENT=\"\$(knoxcall ai create-agent --slug copilot --provider anthropic \\\n"
                . "        --secret-from-env ANTHROPIC_API_KEY)\"\n\n"
                . "options:\n"
                . "  --slug SLUG          url slug; the agent is served at /v1/ai/{slug}\n"
                . "  --provider PROVIDER  provider id (anthropic, openai, groq, bedrock, …). The\n"
                . "                       catalog is server-side; an unknown value is a 400 that\n"
                . "                       names the valid set.\n"
                . "  --secret SECRET      id of an existing KnoxCall secret holding the key\n"
                . "  --secret-from-env VAR  environment variable holding the key; escrows it as a\n"
                . "                       new secret, reusing one of the same name if present\n"
                . "  --name NAME          display name (defaults to --slug)\n"
                . "  --gateway GATEWAY    gateway id or slug to create under\n"
                . "  --model MODEL        default model (required for openai-compatible)\n"
                . "  --upstream URL       upstream base URL; required for azure-openai, ollama,\n"
                . '                       bedrock and openai-compatible' . self::AI_COMMON_HELP,
            'ai mint' => $usage
                . "Mint a capability token for an agent. The plaintext is returned ONCE and is\n"
                . "the only thing on stdout, so it can be captured:\n"
                . "    TOKEN=\"\$(knoxcall ai mint --agent ag_123)\"\n\n"
                . "options:\n"
                . "  --agent AGENT        agent id\n"
                . "  --kind KIND          agent | read | tool | oneshot (default agent)\n"
                . '  --name NAME          label for the token' . self::AI_COMMON_HELP,
            'ai usage' => $usage
                . "Cost and token usage by model.\n\n"
                . "options:\n"
                . "  --period PERIOD      7d | 30d | 90d (default 30d)\n"
                . '  --agent AGENT        scope to one agent' . self::AI_COMMON_HELP,
            default => $usage
                . "KnoxCall command-line interface — sign in once, every SDK on this machine picks it up.\n\n"
                . "commands:\n"
                . "  login   sign in with your browser and store credentials locally\n"
                . "  logout  revoke and remove stored credentials\n"
                . "  whoami  show the signed-in tenant\n"
                . "  init    get started wrapping a provider SDK (escrow a key)\n"
                . "  ai      AI gateway operations\n\n"
                . "options:\n"
                . "  -h, --help  show this help message and exit\n",
        };
    }

    /**
     * Every `ai` control-plane sub-command accepts these; they select WHICH
     * tenant and WHICH stored login is acting. Appended to each sub-command's
     * own option block, so the tail reads the same everywhere.
     */
    private const AI_COMMON_HELP = "\n"
        . "  -h, --help           show this help message and exit\n"
        . "  --profile PROFILE    credentials profile name (default: KNOXCALL_PROFILE or\n"
        . "                       'default')\n"
        . "  --base-url BASE_URL  management API base URL (default https://api.knoxcall.com)\n"
        . "  --sandbox            operate against the Test data space\n";
}
