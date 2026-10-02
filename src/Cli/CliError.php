<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

/**
 * Expected CLI failure — printed as a one-line `error: <message>` on stderr
 * (exit 1), never a stack trace. Mirrors knoxcall-python cli._common.CLIError.
 */
class CliError extends \RuntimeException
{
}
