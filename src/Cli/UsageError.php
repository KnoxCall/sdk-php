<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

/**
 * Argument-parsing failure. Printed argparse-style (usage line + message) on
 * stderr with exit code 2, matching the python reference's argparse behavior.
 */
final class UsageError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $usage)
    {
        parent::__construct($message);
    }
}
