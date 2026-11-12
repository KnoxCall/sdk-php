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

    /**
     * A route that carries a standing `decrypt` or `detokenize` field action is
     * destination-locked: storing a destination URL it does not already store
     * (`target_base_url`, or a `method_configs[].target_url`), or switching on
     * one it stores but does not send to (a disabled environment, an idle
     * method target), or sending it through an `egress_server_id` /
     * `data_plane_node_id` it does not already send through, answers 403 unless
     * the credential holds the action's own permission (`transit:decrypt` /
     * `vault:detokenize`). A token issued to a person is always refused
     * (`forbidden`); a narrowed OAuth token without the scope gets
     * `insufficient_scope`. Nothing is written; do not retry it unchanged.
     * A body that sets `data_plane_node_id` can also answer 409
     * `data_plane_node_changed`: that VPN connection was re-pointed while the
     * request was being checked, and nothing was written; re-read it and retry.
     * See sdk/PARITY.md ("Moving a route that decrypts or detokenizes").
     */
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

    /**
     * A route that carries a standing `decrypt` or `detokenize` field action is
     * destination-locked: storing a destination URL it does not already store
     * (`target_base_url`, or a `method_configs[].target_url`), or switching on
     * one it stores but does not send to (a disabled environment, an idle
     * method target), answers 403 unless
     * the credential holds the action's own permission (`transit:decrypt` /
     * `vault:detokenize`). A token issued to a person is always refused
     * (`forbidden`); a narrowed OAuth token without the scope gets
     * `insufficient_scope`. Nothing is written; do not retry it unchanged.
     * See sdk/PARITY.md ("Moving a route that decrypts or detokenizes").
     */
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
