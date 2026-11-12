<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

/**
 * Parsed command line. Flags default to the same values as the python
 * reference (`knoxcall.cli.build_parser`): null for value flags (so env
 * fallbacks apply downstream), false for booleans.
 */
final class ParsedArgs
{
    public bool $help = false;
    public ?string $command = null;
    public ?string $tenant = null;
    public ?string $baseUrl = null;
    public bool $sandbox = false;
    public ?string $profile = null;
    public bool $device = false;
    public bool $noBrowser = false;
    // `init` only:
    public ?string $provider = null;
    public ?string $secretName = null;
    public ?string $host = null;
    // `ai` only:
    public ?string $aiCommand = null;
    // `ai exchange` only:
    /**
     * NULL means the flag was not given, which is NOT the same as an empty
     * string: `--resource ''` was asked for and the server refuses it, while
     * absent means "no resource" and mints an agent-kind token.
     */
    public ?string $resource = null;
    public ?string $audience = null;
    // `ai` control plane (gateways / agents / create-agent / mint / usage).
    // There is deliberately no `--secret-value` property: a provider key given
    // as an argv value lands in shell history, ps output and the CI log, so
    // `--secret-from-env` names the environment variable instead. Adding one
    // here would be the first half of that regression.
    public ?string $gateway = null;
    public ?string $agent = null;
    public ?string $name = null;
    public ?string $slug = null;
    public ?string $secret = null;
    public ?string $secretFromEnv = null;
    public ?string $upstream = null;
    public ?string $model = null;
    public ?string $kind = null;
    public ?string $period = null;
}
