<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class RoutesResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100),
     * plus endpoint filters.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/routes', $params ?: null);
    }

    /**
     * Yield every route, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    public function get(string $routeId): array
    {
        return self::data($this->client->request('GET', '/v1/routes/' . rawurlencode($routeId)));
    }

    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/routes', null, $input));
    }

    public function update(string $routeId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/routes/' . rawurlencode($routeId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $routeId): array
    {
        return self::data($this->client->request('DELETE', '/v1/routes/' . rawurlencode($routeId)));
    }

    /**
     * Paginated request logs. Params: page, per_page, plus filters.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function getLogs(string $routeId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/routes/' . rawurlencode($routeId) . '/logs', $params ?: null);
    }

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function listEnvironments(string $routeId): array
    {
        return self::data($this->client->request('GET', '/v1/routes/' . rawurlencode($routeId) . '/environments'));
    }

    public function upsertEnvironment(string $routeId, string $envName, array $input): array
    {
        return self::data($this->client->request('PUT', '/v1/routes/' . rawurlencode($routeId) . '/environments/' . rawurlencode($envName), null, $input));
    }

    /** @return array{deleted: bool} */
    public function deleteEnvironment(string $routeId, string $envName): array
    {
        return self::data($this->client->request('DELETE', '/v1/routes/' . rawurlencode($routeId) . '/environments/' . rawurlencode($envName)));
    }

    // -- Relay field-actions (declarative field-level encrypt/decrypt/tokenize) --

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function listActions(string $routeId): array
    {
        return self::data($this->client->request('GET', '/v1/routes/' . rawurlencode($routeId) . '/actions'));
    }

    /**
     * @param array{direction: string, action: string, selectors: array<string>,
     *              key_name?: string, data_role?: string, content_type?: string,
     *              sort_order?: int} $input direction: request|response;
     *              action: encrypt|decrypt|tokenize|detokenize
     */
    public function createAction(string $routeId, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/routes/' . rawurlencode($routeId) . '/actions', null, $input));
    }

    /** @return array{deleted: string} the deleted action id */
    public function deleteAction(string $routeId, string $actionId): array
    {
        return self::data($this->client->request('DELETE', '/v1/routes/' . rawurlencode($routeId) . '/actions/' . rawurlencode($actionId)));
    }
}
