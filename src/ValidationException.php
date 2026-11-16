<?php

declare(strict_types=1);

namespace KnoxCall;

/** 422 */
class ValidationException extends ApiException
{
    /**
     * @param array<string, list<string>> $fields    per-field validation messages
     * @param array<string, string>       $responseHeaders
     */
    public function __construct(
        string $message,
        int $statusCode = 422,
        public readonly array $fields = [],
        ?string $errorCode = null,
        ?string $requestId = null,
        array $responseHeaders = [],
        mixed $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $requestId, $responseHeaders, $responseBody, $previous);
    }
}
