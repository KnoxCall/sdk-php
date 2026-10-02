<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class DynamicDbResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    // -- Connections --

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function list(): array
    {
        return self::data($this->client->request('GET', '/v1/dyn-db-credentials'));
    }

    public function get(string $name): array
    {
        return self::data($this->client->request('GET', '/v1/dyn-db-credentials/' . rawurlencode($name)));
    }

    /** @return array{id: string, name: string, engine: string, execution_mode: string} */
    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/dyn-db-credentials', null, $input));
    }

    /** @return array{updated: string} the connection name */
    public function update(string $name, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/dyn-db-credentials/' . rawurlencode($name), null, $input));
    }

    /** @return array{deleted: string} the connection name */
    public function delete(string $name): array
    {
        return self::data($this->client->request('DELETE', '/v1/dyn-db-credentials/' . rawurlencode($name)));
    }

    /** @return array{rotated: string, fingerprint_updated: bool} */
    public function rotateSshKey(string $name, string $sshPrivateKey, ?string $sshPassphrase = null, ?string $sshHostFingerprint = null): array
    {
        $body = array_filter([
            'ssh_private_key' => $sshPrivateKey,
            'ssh_passphrase' => $sshPassphrase,
            'ssh_host_fingerprint' => $sshHostFingerprint,
        ], fn($v) => $v !== null);
        return self::data($this->client->request('POST', '/v1/dyn-db-credentials/' . rawurlencode($name) . '/rotate-ssh-key', null, $body));
    }

    // -- Roles --

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function listRoles(string $connectionName): array
    {
        return self::data($this->client->request('GET', '/v1/dyn-db-credentials/' . rawurlencode($connectionName) . '/roles'));
    }

    /** @return array{id: string, name: string, connection: string} */
    public function createRole(string $connectionName, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/dyn-db-credentials/' . rawurlencode($connectionName) . '/roles', null, $input));
    }

    /** @return array{updated: string} the role name */
    public function updateRole(string $connectionName, string $role, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/dyn-db-credentials/' . rawurlencode($connectionName) . '/roles/' . rawurlencode($role), null, $input));
    }

    /** @return array{deleted: string} the role name */
    public function deleteRole(string $connectionName, string $role): array
    {
        return self::data($this->client->request('DELETE', '/v1/dyn-db-credentials/' . rawurlencode($connectionName) . '/roles/' . rawurlencode($role)));
    }

    // -- Credential minting + leases --

    /**
     * The minted `password` is shown exactly once — store it now.
     *
     * @return array{username: string, password: string, expires_at: string, lease_id: int, connection_name: string, role_name: string}
     */
    public function mint(string $connectionName, string $role, ?int $ttlSeconds = null): array
    {
        $body = $ttlSeconds !== null ? ['ttl_seconds' => $ttlSeconds] : [];
        return self::data($this->client->request('POST', '/v1/dyn-db-credentials/' . rawurlencode($connectionName) . '/creds/' . rawurlencode($role), null, $body));
    }

    /**
     * List LIVE leases — those whose `status` is `active`, `renewing` or
     * `errored`, i.e. every lease whose database user may still exist on your
     * server. Expired and revoked leases have had their user dropped and are
     * neither listed nor counted in `total`. `$connection` is an exact match
     * on the connection's name, scoped to the caller's Live/Test space.
     *
     * `errored` is included deliberately: renewal was abandoned after five
     * consecutive failures, so nothing is refreshing or expiring that lease.
     * It is also the set counted by the `409 … has N active credential
     * lease(s)` a connection or role delete returns, so anything blocking a
     * delete is listed here and can be revoked.
     *
     * Lease pagination genuinely is limit/offset INSIDE the data payload —
     * the one list on the API that is neither page/per_page nor a bare array.
     *
     * @return array{leases: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listLeases(?int $limit = null, ?int $offset = null, ?string $connection = null): array
    {
        return self::data($this->client->request('GET', '/v1/dyn-db-credentials/leases', array_filter([
            'limit' => $limit,
            'offset' => $offset,
            'connection' => $connection,
        ], fn($v) => $v !== null) ?: null));
    }

    /** @return array{revoked: int} the lease id */
    public function revokeLease(int|string $leaseId): array
    {
        return self::data($this->client->request('POST', '/v1/dyn-db-credentials/leases/' . rawurlencode((string) $leaseId) . '/revoke', null, []));
    }
}
