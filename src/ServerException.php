<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * 5xx. ->retryAfter (seconds) comes from the Retry-After header when present:
 * a 503 `dependency_unavailable` — KnoxCall could not reach one of its own
 * dependencies in time and did not serve the request — sends one, and the
 * retry loop honours it exactly as a 429's (PARITY §4). Null when absent: a
 * plain 5xx keeps the jittered backoff.
 *
 * The constructor is ApiException's, parameter for parameter, with
 * $retryAfter LAST. In 1.0.0 this class had no constructor of its own, so
 * `new ServerException($message, $status, $errorCode, $requestId, …)` is a
 * published call shape; a parameter inserted anywhere earlier shifts every
 * positional argument after it. Pass it by name: `retryAfter: 5`.
 * (Pinned by tests/ExceptionConstructorCompatTest.php.)
 */
class ServerException extends ApiException
{
    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        string $message,
        int $statusCode = 500,
        ?string $errorCode = null,
        ?string $requestId = null,
        array $responseHeaders = [],
        mixed $responseBody = null,
        ?\Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $requestId, $responseHeaders, $responseBody, $previous);
    }
}
