<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * A refusal from the AI **data plane** (`POST {agent_url}/…`), typed.
 *
 * WHY IT IS ITS OWN CLASS. The data plane is not the Management API: it answers
 * `{error, error_description, code}` with the machine-readable code in BOTH
 * `error` and `code` (AIGW-163), while the management plane answers the nested
 * `{"error": {"type", "message", "request_id"}}`. An `$errorCode` of
 * `budget_exceeded` and one of `not_found` come from different contracts, and
 * the status alone cannot tell them apart.
 *
 * The SDK does not make the data-plane call for you — that is the design: you
 * point an existing provider client at the agent's `agent_url` and it works
 * unchanged. So this class is paired with {@see self::fromDataPlaneResponse()},
 * which types whatever that client hands back.
 *
 * Extends {@see ApiException}, so an existing `catch (ApiException $e)` still
 * catches it (sdk/PARITY.md §1).
 */
class AIGatewayException extends ApiException
{
    /**
     * @param array<string, string> $responseHeaders lowercased header names
     */
    public function __construct(
        string $message,
        int $statusCode,
        /** The human sentence. Branch on {@see $errorCode}, never on this. */
        public readonly string $errorDescription = '',
        /**
         * Whole seconds from `Retry-After`, or null.
         *
         * Absence is meaningful: the gateway sends no header rather than a
         * guess, so null means "back off on your own schedule", never "retry
         * now".
         */
        public readonly ?int $retryAfter = null,
        ?string $errorCode = null,
        ?string $requestId = null,
        array $responseHeaders = [],
        mixed $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $statusCode,
            $errorCode,
            $requestId,
            $responseHeaders,
            $responseBody,
            $previous,
        );
    }

    /**
     * Does this parsed body look like the AI data plane's envelope?
     *
     * `error` and `code` must both be present AND equal: the pre-AIGW-163 auth
     * shape had a `code` that was not the `error`, and accepting it would make
     * `$errorCode` mean two things again.
     */
    public static function isDataPlaneBody(mixed $body): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $error = $body['error'] ?? null;
        $code = $body['code'] ?? null;
        $description = $body['error_description'] ?? null;

        return is_string($error) && $error !== ''
            && is_string($code) && $code !== ''
            && is_string($description) && $description !== ''
            && $error === $code;
    }

    /**
     * Type a refusal a provider client received from an agent's data-plane URL.
     *
     * Returns null when `$body` is not the data plane's envelope, so a caller
     * falls through to its own handling rather than being handed a mislabelled
     * exception.
     *
     * @param array<string, string> $headers lowercased header names
     */
    public static function fromDataPlaneResponse(int $status, mixed $body, array $headers = []): ?self
    {
        if (!self::isDataPlaneBody($body)) {
            return null;
        }
        /** @var array<string, mixed> $b */
        $b = $body;

        $raw = trim((string) ($headers['retry-after'] ?? ''));
        // An HTTP-date Retry-After is legal but is not delta-seconds; null is
        // the right reading of one we cannot use.
        $retryAfter = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;

        $requestId = $headers['x-request-id'] ?? ($b['request_id'] ?? null);
        $requestId = is_string($requestId) && $requestId !== '' ? $requestId : null;

        return new self(
            (string) $b['error_description'],
            $status,
            (string) $b['error_description'],
            $retryAfter,
            (string) $b['code'],
            $requestId,
            $headers,
            $body,
        );
    }
}
