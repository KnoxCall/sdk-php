<?php

declare(strict_types=1);

namespace KnoxCall\Transport;

use KnoxCall\ConnectionException;
use KnoxCall\ConnectionTimeoutException;

final class CurlTransport implements TransportInterface
{
    /**
     * curl errnos where the connection was never established, so the request
     * never left the machine — always safe to retry, even for mutations.
     */
    private const NEVER_SENT_ERRNOS = [
        CURLE_COULDNT_RESOLVE_PROXY,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
    ];

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $method = strtoupper($method);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders): int {
            $trimmed = trim($line);
            if ($trimmed === '') {
                return strlen($line);
            }
            if (str_starts_with($trimmed, 'HTTP/')) {
                // New status line (redirect / 100-continue): the last block wins.
                $responseHeaders = [];
                return strlen($line);
            }
            $pos = strpos($trimmed, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(substr($trimmed, 0, $pos))] = trim(substr($trimmed, $pos + 1));
            }
            return strlen($line);
        });
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        // No curl_close(): it has had no effect since PHP 8.0 (the handle is freed
        // when it goes out of scope) and is deprecated in 8.5, where the smoke's
        // composer:2 image already runs; the package floor is >=8.1.

        if ($errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                // connect-time 0 means it timed out before the connection
                // existed — the request was never sent.
                throw new ConnectionTimeoutException(
                    "Request to {$url} timed out: {$error}",
                    requestSent: $connectTime > 0.0,
                );
            }
            throw new ConnectionException(
                "Could not reach {$url}: {$error} (curl errno {$errno})",
                requestSent: !in_array($errno, self::NEVER_SENT_ERRNOS, true),
            );
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }
}
