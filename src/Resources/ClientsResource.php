<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class ClientsResource
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
        return $this->client->request('GET', '/v1/clients', $params ?: null);
    }

    /**
     * Yield every client, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    public function get(string $clientId): array
    {
        return self::data($this->client->request('GET', '/v1/clients/' . rawurlencode($clientId)));
    }

    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/clients', null, $input));
    }

    public function update(string $clientId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/clients/' . rawurlencode($clientId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $clientId): array
    {
        return self::data($this->client->request('DELETE', '/v1/clients/' . rawurlencode($clientId)));
    }

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function listCredentials(string $clientId): array
    {
        return self::data($this->client->request('GET', '/v1/clients/' . rawurlencode($clientId) . '/credentials'));
    }

    /**
     * For mtls 'issue' mode the response carries a ONE-SHOT `reveal`
     * (certificate_pem / private_key_pem / ca_chain_pem) — store it now.
     */
    public function createCredential(string $clientId, string $kind, string $label, mixed $data = null): array
    {
        return self::data($this->client->request('POST', '/v1/clients/' . rawurlencode($clientId) . '/credentials', null, array_filter([
            'kind' => $kind,
            'label' => $label,
            'data' => $data,
        ], fn($v) => $v !== null)));
    }

    public function updateCredential(string $clientId, string $credentialId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/clients/' . rawurlencode($clientId) . '/credentials/' . rawurlencode($credentialId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function deleteCredential(string $clientId, string $credentialId): array
    {
        return self::data($this->client->request('DELETE', '/v1/clients/' . rawurlencode($clientId) . '/credentials/' . rawurlencode($credentialId)));
    }
}
