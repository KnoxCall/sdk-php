<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Transport\TransportInterface;

/**
 * Queue-based transport double: queue responses (or throwables) in order;
 * every request the SDK makes is recorded for assertions.
 */
final class MockTransport implements TransportInterface
{
    /** @var list<array{status: int, headers: array<string, string>, body: string}|\Throwable> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}> */
    public array $requests = [];

    public function queueRaw(int $status, string $body, array $headers = []): void
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public function queueJson(int $status, array $body, array $headers = []): void
    {
        $this->queueRaw($status, json_encode($body), $headers);
    }

    public function queueToken(string $token, int|string $expiresIn = 3600): void
    {
        $this->queueJson(200, [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]);
    }

    public function queueThrow(\Throwable $e): void
    {
        $this->queue[] = $e;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutMs' => $timeoutMs,
        ];
        if ($this->queue === []) {
            throw new \LogicException("MockTransport queue exhausted for {$method} {$url}");
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int} */
    public function lastRequest(): array
    {
        return $this->requests[count($this->requests) - 1];
    }

    /** Case-insensitive header lookup on a recorded request. */
    public function header(int $requestIndex, string $name): ?string
    {
        foreach ($this->requests[$requestIndex]['headers'] as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }
}
