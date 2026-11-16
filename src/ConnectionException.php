<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * A transport-level failure (DNS, connect, TLS, reset) — the HTTP library's
 * raw errors never escape the SDK.
 *
 * ->requestSent === false means the connection was never established, so the
 * request never left the machine and is always safe to retry — even for
 * mutating methods. ->requestSent === true means the request may have reached
 * the upstream; only GET/HEAD are replayed.
 */
class ConnectionException extends KnoxCallException
{
    public function __construct(
        string $message,
        public readonly bool $requestSent = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
