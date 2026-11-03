<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * Workflows control plane (server: src/client-api/workflows.ts; PARITY §11).
 * `knoxcall-node`'s src/resources/workflows.ts is the reference shape.
 *
 * Wraps `/v1/workflows`:
 *   - workflows: list / iterate / get / create / update / delete / execute
 *   - executions: listExecutions / iterateExecutions / getExecution /
 *                 cancelExecution
 *
 * Mutating methods (create / update / delete / execute / cancelExecution)
 * carry the ULID X-Idempotency-Key stamped by {@see KnoxCall::request()} for
 * every non-GET/HEAD call — stable across retries, like every other resource.
 */
class WorkflowsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    // -- Workflows ----------------------------------------------------------------

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100),
     * plus endpoint filters.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/workflows', $params ?: null);
    }

    /**
     * Yield every workflow, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    public function get(string $workflowId): array
    {
        return self::data($this->client->request('GET', '/v1/workflows/' . rawurlencode($workflowId)));
    }

    /**
     * @param array{name: string, definition: mixed, description?: string, trigger_config?: mixed, environment?: string, enabled?: bool} $input
     */
    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/workflows', null, $input));
    }

    public function update(string $workflowId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/workflows/' . rawurlencode($workflowId), null, $input));
    }

    /** @return array{id: string, deleted: bool} */
    public function delete(string $workflowId): array
    {
        return self::data($this->client->request('DELETE', '/v1/workflows/' . rawurlencode($workflowId)));
    }

    /**
     * Execute a workflow. Queues a run and returns the execution ack. Idempotent:
     * the ULID X-Idempotency-Key makes a replay return the same execution.
     *
     * @return array{id: string, workflow_id: string, status: string}
     */
    public function execute(string $workflowId, mixed $input = null): array
    {
        return self::data($this->client->request(
            'POST',
            '/v1/workflows/' . rawurlencode($workflowId) . '/execute',
            null,
            ['input' => $input],
        ));
    }

    // -- Executions ---------------------------------------------------------------

    /**
     * A workflow's executions (paginated — the polling-trigger source).
     * Params: page (default 1), per_page (default 20, max 100), plus filters.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listExecutions(string $workflowId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/workflows/' . rawurlencode($workflowId) . '/executions', $params ?: null);
    }

    /**
     * Yield every execution of a workflow, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateExecutions(string $workflowId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listExecutions($workflowId, $p));
    }

    /** Fetch one execution (with composed step details). */
    public function getExecution(string $executionId): array
    {
        return self::data($this->client->request('GET', '/v1/workflows/executions/' . rawurlencode($executionId)));
    }

    /** @return array{id: string, status: string} cancels a running execution */
    public function cancelExecution(string $executionId): array
    {
        return self::data($this->client->request('POST', '/v1/workflows/executions/' . rawurlencode($executionId) . '/cancel'));
    }
}
