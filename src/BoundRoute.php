<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * A route with bound call defaults — see KnoxCall::route().
 *
 * Holds only the client reference, route id, and defaults (never a token or
 * any pipeline state), so retries and 401 re-mint behave exactly as on
 * call(). Per-call values win over bound defaults; headers merge per-key
 * with per-call winning. A per-call value left unset inherits the bound
 * default — there is no "explicitly clear" mechanism; construct another
 * handle instead.
 */
final class BoundRoute
{
    private readonly ?string $environment;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly ?int $timeoutMs;

    /** @param array{environment?: string, headers?: array<string, string>, timeout_ms?: int} $defaults */
    public function __construct(
        private readonly KnoxCall $client,
        private readonly string $route,
        array $defaults = [],
    ) {
        $this->environment = isset($defaults['environment']) ? (string) $defaults['environment'] : null;
        $this->headers = $defaults['headers'] ?? [];
        $this->timeoutMs = isset($defaults['timeout_ms']) ? (int) $defaults['timeout_ms'] : null;
    }

    /**
     * Make a proxied request through the bound route — same options as
     * KnoxCall::call() (query, body, headers, environment, timeout_ms);
     * everything delegates to it, so retry / re-mint / legacy-key behavior
     * is inherited intact.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $path = '/', array $opts = []): array
    {
        $merged = $opts;
        $merged['method'] = $method;
        $merged['path'] = $path;
        $merged['headers'] = array_merge($this->headers, $opts['headers'] ?? []);
        if (!isset($opts['environment']) && $this->environment !== null) {
            $merged['environment'] = $this->environment;
        }
        if (!isset($opts['timeout_ms']) && $this->timeoutMs !== null) {
            $merged['timeout_ms'] = $this->timeoutMs;
        }
        return $this->client->call($this->route, $merged);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function get(string $path = '/', array $opts = []): array
    {
        return $this->request('GET', $path, $opts);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function post(string $path = '/', array $opts = []): array
    {
        return $this->request('POST', $path, $opts);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function put(string $path = '/', array $opts = []): array
    {
        return $this->request('PUT', $path, $opts);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function patch(string $path = '/', array $opts = []): array
    {
        return $this->request('PATCH', $path, $opts);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function delete(string $path = '/', array $opts = []): array
    {
        return $this->request('DELETE', $path, $opts);
    }
}
