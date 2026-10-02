<?php

declare(strict_types=1);

namespace KnoxCall;

/** 429 — ->retryAfter (seconds) comes from the Retry-After header when present. */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        string $message,
        int $statusCode = 429,
        public readonly ?int $retryAfter = null,
        ?string $errorCode = null,
        ?string $requestId = null,
        array $responseHeaders = [],
        mixed $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $requestId, $responseHeaders, $responseBody, $previous);
    }
}
