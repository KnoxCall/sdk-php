<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class EnvironmentsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function list(): array
    {
        return self::data($this->client->request('GET', '/v1/environments'));
    }

    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/environments', null, $input));
    }

    public function update(string $envId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/environments/' . rawurlencode($envId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $envId): array
    {
        return self::data($this->client->request('DELETE', '/v1/environments/' . rawurlencode($envId)));
    }
}
