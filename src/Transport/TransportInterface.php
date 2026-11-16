<?php

declare(strict_types=1);

namespace KnoxCall\Transport;

use KnoxCall\ConnectionException;

/**
 * Minimal HTTP transport abstraction. The default is CurlTransport; tests
 * inject a mock via the client's `transport` option.
 */
interface TransportInterface
{
    /**
     * Send one HTTP request and return the raw response. Never throws on an
     * HTTP error status — only on transport-level failure.
     *
     * @param array<string, string> $headers request headers (name => value)
     * @return array{status: int, headers: array<string, string>, body: string}
     *         response headers are lowercased
     *
     * @throws ConnectionException on transport failure (DNS, connect, TLS, reset)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array;
}
