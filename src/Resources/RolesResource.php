<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * Read-only role catalog (IaC plan §6 item 1.3).
 *
 * Roles are created and edited on the MFA-gated admin surface; /v1 exposes them
 * so `apiKeys->create(['role_ids' => [...]])` can be written in code instead of
 * by copying a UUID out of a browser URL bar. The rules a role grants are
 * deliberately not exposed.
 */
class RolesResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: subject_kind ('api_key' | 'user'), page, per_page.
     * Only roles whose `applies_to` includes `api_key` may be passed in
     * `role_ids` when creating a key.
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
