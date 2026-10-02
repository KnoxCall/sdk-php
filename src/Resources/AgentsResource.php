<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class AgentsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function list(): array
    {
        return self::data($this->client->request('GET', '/v1/agents'));
    }

    /**
     * The response's `agent_secret` is shown exactly once — store it now.
     *
     * Requires an explicit `agent:create` policy grant: a wildcard (`*:*`) rule
     * does not satisfy it, including the `legacy_admin` policy every key created
     * before 2026-06-30 still carries. The seeded Key - Infrastructure and Key -
     * Editor roles name the action literally and are unaffected. Without it the
     * call returns 403. Every successful mint also emails the account's owners.
     *
     * @return array{id: string, name: string, agent_id: string, status: string, require_verified_build: bool, created_at: string, agent_secret: string}
     */
    public function create(string $name): array
    {
        return self::data($this->client->request('POST', '/v1/agents', null, ['name' => $name]));
    }

    /** @return array{revoked: bool} */
    public function revoke(string $agentId): array
    {
        return self::data($this->client->request('DELETE', '/v1/agents/' . rawurlencode($agentId)));
    }

    /** @return list<array<string, mixed>> bare array (latest 50) — no pagination */
    public function getTamperEvents(string $agentId): array
    {
        return self::data($this->client->request('GET', '/v1/agents/' . rawurlencode($agentId) . '/tamper-events'));
    }
}
