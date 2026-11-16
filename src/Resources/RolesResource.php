<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * Read-only role catalog (IaC plan §6 item 1.3).
 *
 * /v1 exposes it so `apiKeys->create(['role_ids' => [...]])` can be written in
 * code instead of by copying a UUID out of a browser URL bar. The rules a role
 * grants are deliberately not exposed. Custom roles are being retired: none can
 * be created, and the list still returns any a tenant already has
 * (`seeded: false`).
 */
class RolesResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: subject_kind ('api_key' | 'user'), page, per_page.
     * `role_ids` accepts a role only when its `applies_to` includes `api_key`
     * AND `seeded` is true — filter on `seeded` when creating a key.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/roles', $params ?: null);
    }

    /**
     * Yield every role, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }
}
