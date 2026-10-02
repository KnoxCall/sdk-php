<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * OAuth client registry. These endpoints bypass the standard success()
 * wrapper server-side: `data` has no accompanying meta, the list is NOT
 * paginated, and create/rotate-secret carry a top-level `warning` string —
 * surfaced here as an optional `warning` key on the returned array.
 */
class OAuthClientsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function list(): array
    {
        return self::data($this->client->request('GET', '/v1/oauth-clients'));
    }

    public function get(string $id): array
    {
        return self::data($this->client->request('GET', '/v1/oauth-clients/' . rawurlencode($id)));
    }

    /**
     * The response's `client_secret` (null for public clients) is shown
     * exactly once — store it now. A server-side `warning`, when present,
     * is attached as the `warning` key.
     */
    public function create(array $input): array
    {
        return self::dataWithWarning($this->client->request('POST', '/v1/oauth-clients', null, $input));
    }

    /** @return array{id: string} */
    public function update(string $id, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/oauth-clients/' . rawurlencode($id), null, $input));
    }

    /**
     * The new `client_secret` is shown exactly once. The server's `warning`
     * is attached as the `warning` key.
     *
     * Rotation is containment: every access and refresh token the OLD secret
     * minted is revoked in the same transaction as the re-key, so they stop
     * working immediately rather than at their TTL. A rotation whose revocation
     * cannot complete is refused and the old secret keeps working — you never
     * hold a new secret for a client whose old tokens are still live.
     *
     * @return array{client_id: string, client_secret: string, warning?: string}
     */
    public function rotateSecret(string $id): array
    {
        return self::dataWithWarning($this->client->request('POST', '/v1/oauth-clients/' . rawurlencode($id) . '/rotate-secret', null, []));
    }

    /** @return array{revoked: bool} */
    public function revoke(string $id): array
    {
        return self::data($this->client->request('DELETE', '/v1/oauth-clients/' . rawurlencode($id)));
    }

    /** Unwrap `data` and fold the response's top-level `warning` into it. */
    private static function dataWithWarning(mixed $response): array
    {
        $data = self::data($response);
        $data = is_array($data) ? $data : [];
        if (is_array($response) && isset($response['warning']) && is_string($response['warning'])) {
            $data['warning'] = $response['warning'];
        }
        return $data;
    }
}
