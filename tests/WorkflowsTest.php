<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\KnoxCall;
use PHPUnit\Framework\TestCase;

/**
 * Workflows resource (PARITY §11) — mirrors knoxcall-node
 * src/resources/workflows.ts. Every mock is the REAL server envelope
 * (src/client-api/helpers.ts): {data, meta} for single objects, the paginated
 * envelope for lists — never a bare object, never a cursor. Mutating methods
 * carry the ULID X-Idempotency-Key stamped by KnoxCall::request().
 */
final class WorkflowsTest extends TestCase
{
    private MockTransport $t;
    private KnoxCall $client;

    protected function setUp(): void
    {
        $this->t = new MockTransport();
        // A pre-acquired kc_ token: no token-endpoint round trip, so request
        // #N is API call #N.
        $this->client = new KnoxCall([
            'tenant' => 'acme',
            'api_key' => 'kc_live_x',
            'transport' => $this->t,
            'retry_base_delay_ms' => 0,
            'base_url' => 'https://api.example.test',
            'proxy_base_url' => 'https://acme.example.test',
        ]);
    }

    /** {data, meta: {request_id}} */
    private static function envelope(mixed $data): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    /** paginated(rows, total, page, perPage) — meta carries the page math */
    private static function paginated(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'request_id' => 'req-' . bin2hex(random_bytes(4)),
            ],
        ];
    }

    private function lastUrl(): string
    {
        return $this->t->lastRequest()['url'];
    }

    private function lastBody(): array
    {
        return json_decode((string) $this->t->lastRequest()['body'], true);
    }

    // -- Workflows CRUD --------------------------------------------------------------

    public function testListReturnsEnvelopeAndSendsPageParams(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'wf_1'], ['id' => 'wf_2']], 42, 2, 2));

        $page = $this->client->workflows->list(['page' => 2, 'per_page' => 2, 'environment' => 'production']);

        $this->assertSame('https://api.example.test/v1/workflows?page=2&per_page=2&environment=production', $this->lastUrl());
        $this->assertSame([['id' => 'wf_1'], ['id' => 'wf_2']], $page['data']);
        $this->assertSame(42, $page['meta']['total']);
        $this->assertSame(21, $page['meta']['total_pages']);
    }

    public function testGetUnwrapsSingleObject(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'wf_1', 'name' => 'nightly', 'enabled' => true]));

        $workflow = $this->client->workflows->get('wf_1');

        $this->assertSame('https://api.example.test/v1/workflows/wf_1', $this->lastUrl());
        $this->assertSame('nightly', $workflow['name']);
        $this->assertArrayNotHasKey('data', $workflow);
        $this->assertArrayNotHasKey('meta', $workflow);
    }

    public function testCreateUnwrapsAndCarriesUlidIdempotencyKey(): void
    {
        $this->t->queueJson(201, self::envelope(['id' => 'wf_2', 'name' => 'onboarding', 'version' => 1]));

        $created = $this->client->workflows->create([
            'name' => 'onboarding',
            'definition' => ['nodes' => [], 'edges' => []],
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows', $this->lastUrl());
        $this->assertSame('onboarding', $this->lastBody()['name']);
        $this->assertSame('wf_2', $created['id']);
        $this->assertArrayNotHasKey('meta', $created);

        // Mutating requests carry a ULID idempotency key (Crockford base32).
        $key = $this->t->header(0, 'X-Idempotency-Key');
        $this->assertNotNull($key);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $key);
    }

    public function testUpdateSendsPatch(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'wf_1', 'name' => 'renamed']));

        $updated = $this->client->workflows->update('wf_1', ['name' => 'renamed', 'enabled' => false]);

        $this->assertSame('PATCH', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows/wf_1', $this->lastUrl());
        $this->assertSame('renamed', $this->lastBody()['name']);
        $this->assertFalse($this->lastBody()['enabled']);
        $this->assertSame('renamed', $updated['name']);
        $this->assertNotNull($this->t->header(0, 'X-Idempotency-Key'));
    }

    /**
     * PARITY §11 Workflows — "an unpublishable definition may never be the
     * running one". CREATE keeps the caller's data and withholds the switch:
     * the row is created `enabled: false` and the response says so. It is a
     * normal 200, not an error, and it is the one place in /v1 where a boolean
     * you sent comes back different — so the SDK must report the SERVER's
     * value, never echo the requested one back from the request body.
     */
    public function testCreateSurfacesEnabledFalseWhenTheServerWithholdsTheSwitch(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'wf_3', 'name' => 'onboarding', 'enabled' => false]));

        $created = $this->client->workflows->create([
            'name' => 'onboarding',
            'definition' => ['nodes' => [], 'edges' => []],
            'enabled' => true,
        ]);

        $this->assertTrue($this->lastBody()['enabled'], 'the request did ask for enabled');
        $this->assertFalse($created['enabled'], 'the SDK must report what the server stored');
    }

    /**
     * The other half of the same rule: UPDATE refuses where CREATE withholds.
     * `enabled: true` on a workflow whose STORED definition cannot publish is a
     * 422 `invalid_definition`. It is a CLIENT error — retrying it unchanged
     * burns the tenant's rate limit and can never succeed.
     */
    public function testUpdateThrowsTheTyped422AndDoesNotRetryIt(): void
    {
        $this->t->queueJson(422, ['error' => [
            'type' => 'invalid_definition',
            'message' => 'This workflow cannot be enabled: node "n1": HTTP method is required',
            'request_id' => 'req-1',
        ]]);

        try {
            $this->client->workflows->update('wf_1', ['enabled' => true]);
            $this->fail('expected a ValidationException');
        } catch (\KnoxCall\ValidationException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame('invalid_definition', $e->errorCode);
        }

        $this->assertCount(1, $this->t->requests, 'a 422 is a client error and must not be retried');
    }

    public function testDeleteUnwrapsToDeletedFlag(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'wf_1', 'deleted' => true]));

        $deleted = $this->client->workflows->delete('wf_1');

        $this->assertSame('DELETE', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows/wf_1', $this->lastUrl());
        $this->assertSame(['id' => 'wf_1', 'deleted' => true], $deleted);
    }

    public function testIterateWalksAllPagesAndStopsAtTotalPages(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'wf_1'], ['id' => 'wf_2']], 5, 1, 2));
        $this->t->queueJson(200, self::paginated([['id' => 'wf_3'], ['id' => 'wf_4']], 5, 2, 2));
        $this->t->queueJson(200, self::paginated([['id' => 'wf_5']], 5, 3, 2));

        $ids = [];
        foreach ($this->client->workflows->iterate(['per_page' => 2]) as $workflow) {
            $ids[] = $workflow['id'];
        }

        $this->assertSame(['wf_1', 'wf_2', 'wf_3', 'wf_4', 'wf_5'], $ids);
        $this->assertCount(3, $this->t->requests);
        $this->assertSame('https://api.example.test/v1/workflows?page=1&per_page=2', $this->t->requests[0]['url']);
        $this->assertSame('https://api.example.test/v1/workflows?page=3&per_page=2', $this->t->requests[2]['url']);
    }

    // -- Execute ---------------------------------------------------------------------

    public function testExecutePostsInputBodyAndUnwraps(): void
    {
        $this->t->queueJson(202, self::envelope(['id' => 'run_1', 'workflow_id' => 'wf_1', 'status' => 'queued']));

        $run = $this->client->workflows->execute('wf_1', ['order_id' => 42]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows/wf_1/execute', $this->lastUrl());
        $this->assertSame(['input' => ['order_id' => 42]], $this->lastBody());
        $this->assertSame('run_1', $run['id']);
        $this->assertSame('queued', $run['status']);
        $this->assertNotNull($this->t->header(0, 'X-Idempotency-Key'));
    }

    public function testExecuteWithoutInputSendsNullInput(): void
    {
        $this->t->queueJson(202, self::envelope(['id' => 'run_2', 'workflow_id' => 'wf_1', 'status' => 'queued']));

        $this->client->workflows->execute('wf_1');

        $this->assertSame('https://api.example.test/v1/workflows/wf_1/execute', $this->lastUrl());
        $this->assertSame(['input' => null], $this->lastBody());
    }

    // -- Executions ------------------------------------------------------------------

    public function testListExecutionsPaginated(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'ex_1', 'status' => 'succeeded']], 3, 2, 1));

        $page = $this->client->workflows->listExecutions('wf_1', ['page' => 2, 'per_page' => 1]);

        $this->assertSame('https://api.example.test/v1/workflows/wf_1/executions?page=2&per_page=1', $this->lastUrl());
        $this->assertSame('ex_1', $page['data'][0]['id']);
        $this->assertSame(3, $page['meta']['total']);
    }

    public function testIterateExecutionsWalksPages(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'ex_1']], 2, 1, 1));
        $this->t->queueJson(200, self::paginated([['id' => 'ex_2']], 2, 2, 1));

        $ids = array_column(
            iterator_to_array($this->client->workflows->iterateExecutions('wf_1', ['per_page' => 1]), false),
            'id',
        );

        $this->assertSame(['ex_1', 'ex_2'], $ids);
        $this->assertSame('https://api.example.test/v1/workflows/wf_1/executions?page=1&per_page=1', $this->t->requests[0]['url']);
        $this->assertSame('https://api.example.test/v1/workflows/wf_1/executions?page=2&per_page=1', $this->t->requests[1]['url']);
    }

    public function testGetExecutionUsesTopLevelExecutionsPath(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'ex_9', 'workflow_id' => 'wf_1', 'status' => 'running']));

        $execution = $this->client->workflows->getExecution('ex_9');

        // executions are addressed at /v1/workflows/executions/{id}, not nested
        $this->assertSame('GET', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows/executions/ex_9', $this->lastUrl());
        $this->assertSame('running', $execution['status']);
        $this->assertArrayNotHasKey('data', $execution);
    }

    public function testCancelExecutionPostsToCancelPath(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'ex_9', 'status' => 'cancelled']));

        $cancelled = $this->client->workflows->cancelExecution('ex_9');

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/workflows/executions/ex_9/cancel', $this->lastUrl());
        $this->assertSame(['id' => 'ex_9', 'status' => 'cancelled'], $cancelled);
        $this->assertNotNull($this->t->header(0, 'X-Idempotency-Key'));
    }
}
