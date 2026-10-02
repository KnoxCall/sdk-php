<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class ApiKeysResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/api-keys', $params ?: null);
    }

    /**
     * Yield every API key, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    /**
     * The response's plaintext `api_key` is shown exactly once — store it now.
     *
     * `role_ids` (list of role UUIDs) attaches permission roles in the SAME
     * transaction as the key. Discover them with
     * `$client->roles->list(['subject_kind' => 'api_key'])`. A key created with
     * no role is default-denied on every policy-gated endpoint.
     *
     * A key can never mint a key more privileged than itself: if a requested
     * role grants something this credential does not hold, the server answers
     * `403 privilege_escalation` (a PermissionDeniedException) and names the
     * offending grant verbatim.
     *
     * @return array{id: string, key_id: string, api_key: string, key_prefix: string, key_type: string, name: string, role_ids: list<string>, message: string}
     */
    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/api-keys', null, $input));
    }

    /** @return array{revoked: bool} */
    public function revoke(string $keyId): array
    {
        return self::data($this->client->request('DELETE', '/v1/api-keys/' . rawurlencode($keyId)));
    }
}
