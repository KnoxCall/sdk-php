<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * The workload assertion source returned bytes that were already spent on a
 * previous exchange, so sending them could only have been refused.
 *
 * This is a caller-side configuration error, not a credential rejection, and it
 * says so: the message names the cause and what to do, because the alternative
 * is a replay refusal from the server that reads like "your CI identity is not
 * trusted". See {@see \KnoxCall\Auth\WorkloadCredentialProvider}.
 */
final class StaleAssertionException extends KnoxCallException
{
}
