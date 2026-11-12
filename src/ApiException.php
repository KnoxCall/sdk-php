<?php

declare(strict_types=1);

namespace KnoxCall;

class ApiException extends KnoxCallException
{
    /**
     * @param array<string, string> $responseHeaders lowercased header names
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly array $responseHeaders = [],
        public readonly mixed $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("KnoxCall API error {$statusCode}: {$message}", $statusCode, $previous);
    }

    /**
     * Map an HTTP status + parsed body to the matching typed exception.
     *
     * @param array<string, string> $headers lowercased header names
     */
    public static function fromResponse(int $status, mixed $body, array $headers = []): self
    {
        $b = is_array($body) ? $body : [];
        // The v1 API nests errors — { error: { type, message, request_id } }
        // (src/client-api/helpers.ts); the OAuth endpoints use the flat RFC
        // 6749 shape (error / error_description). Handle both.
        $nested = isset($b['error']) && is_array($b['error']) ? $b['error'] : [];
        $message = $nested['message'] ?? $b['error_description'] ?? $b['message'] ?? $b['error'] ?? "HTTP {$status}";
        if (!is_string($message) || $message === '') {
            $message = "HTTP {$status}";
        }
        // AIGW-163: the AI data plane sends the code in BOTH `error` and
        // `code`. Prefer the explicit `code` — a future surface could carry one
        // that is not mirrored, and reading the mirror would silently lose it.
        $flatCode = isset($b['code']) && is_string($b['code']) ? $b['code'] : null;
        $code = $nested['type'] ?? $flatCode ?? (isset($b['error']) && is_string($b['error']) ? $b['error'] : null);
        $code = is_string($code) && $code !== '' ? $code : null;
        $requestId = $nested['request_id'] ?? $headers['x-request-id'] ?? null;
        $requestId = is_string($requestId) && $requestId !== '' ? $requestId : null;

        if ($status === 422) {
            $fields = isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : [];
            return new ValidationException($message, $status, $fields, $code, $requestId, $headers, $body);
        }
        if ($status === 429) {
            $ra = $headers['retry-after'] ?? null;
            $retryAfter = is_string($ra) && ctype_digit($ra) ? (int) $ra : null;
            return new RateLimitException($message, $status, $retryAfter, $code, $requestId, $headers, $body);
        }
        if ($status >= 500) {
            // A 503 `dependency_unavailable` carries Retry-After; a plain 5xx
            // does not. Digits only — an HTTP-date is legal but is not
            // delta-seconds. `retryAfter` is ServerException's LAST parameter
            // (its first seven are ApiException's, the 1.0.0 shape), so it is
            // passed by name.
            $ra = $headers['retry-after'] ?? null;
            $retryAfter = is_string($ra) && ctype_digit($ra) ? (int) $ra : null;
            return new ServerException($message, $status, $code, $requestId, $headers, $body, retryAfter: $retryAfter);
        }

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            // 402 is a plan/billing limit (type plan_limit) — distinct from a
            // 403 access denial so callers can prompt an upgrade, not "denied".
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            default => self::class,
        };
        return new $class($message, $status, $code, $requestId, $headers, $body);
    }
}
