<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class SecretsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100),
     * plus endpoint filters.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/secrets', $params ?: null);
    }

    /**
     * Yield every secret, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    /**
     * Fetch one secret's metadata. The value is never returned.
     *
     * The result carries an `environments` list — one entry per environment
     * holding a value, ordered by environment name, each
     * `{environment_name, value_version, updated_at, expires_at_override}`.
     * `value_version` counts genuine value writes for that environment
     * (starting at 1) and moves ONLY when the stored value changes: rotations,
     * admin value/certificate updates and platform-managed custodial key
     * rotation. It deliberately does not move for OAuth2 token refreshes,
     * expiry-override edits, certificate metadata re-parsing, or a
     * re-encryption of the same plaintext under a new tenant key. Compare it
     * with the version your own last write returned to detect a rotation
     * performed outside your tooling — `updated_at` cannot do that, because
     * non-value writes move it too.
     *
     * @return array<string, mixed>
     */
    public function get(string $secretId): array
    {
        return self::data($this->client->request('GET', '/v1/secrets/' . rawurlencode($secretId)));
    }

    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/secrets', null, $input));
    }

    /**
     * Create an OAuth2-provider secret (the proxy injects the provider's access
     * token into upstream requests). The plain {@see self::create()} cannot
     * carry these fields, so use this typed helper.
     *
     * Required: `name`, `provider`, `client_id`. Optional: `client_secret`
     * (required for most grant types unless `mtls_certificate_id` is set),
     * `mtls_certificate_id`, `scopes` (string[]), `auth_url`, `token_url`,
     * `grant_type`, `username`, `password` (both for the "password"/ROPC
     * grant), `collection_id`.
     *
     * @param array<string, mixed> $input
     */
    public function createOAuth2(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/secrets/oauth2', null, $input));
    }

    /**
     * Create a certificate / mTLS secret. Required: `name`,
     * `certificate_content` (PEM text, or base64 for binary formats).
     * Optional: `private_key`, `passphrase`, `certificate_type` (one of
     * pem|pfx|p12|crt|cer|key|pkcs7|p7b|p7c, defaults to "pem" server-side),
     * `collection_id`.
     *
     * @param array<string, mixed> $input
     */
    public function createCertificate(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/secrets/certificate', null, $input));
    }

    public function update(string $secretId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/secrets/' . rawurlencode($secretId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $secretId): array
    {
        return self::data($this->client->request('DELETE', '/v1/secrets/' . rawurlencode($secretId)));
    }

    /**
     * Rotate a secret's value for one environment.
     *
     * `value_version` in the result is the environment's version AFTER this
     * write — 1 when this call stored the environment's first value, otherwise
     * the previous version plus one. It is the version this call produced, so
     * storing it has no read-after-write race with a concurrent rotation;
     * compare it later against `get()`'s `environments[].value_version` to
     * detect a rotation performed outside your tooling.
     *
     * @return array{id: string, name: string, environment: string, value_version: int}
     */
    public function setValue(string $secretId, mixed $value, ?string $environment = null): array
    {
        $body = ['value' => $value];
        if ($environment !== null) {
            $body['environment'] = $environment;
        }
        return self::data($this->client->request('PUT', '/v1/secrets/' . rawurlencode($secretId) . '/value', null, $body));
    }

    /** @return array{access_token: string, expires_at: ?string, token_type: string, connection_status: string} */
    public function getOAuthToken(string $secretId, ?string $environment = null): array
    {
        $query = $environment ? ['environment' => $environment] : null;
        return self::data($this->client->request('GET', '/v1/secrets/' . rawurlencode($secretId) . '/oauth2/token', $query));
    }
}
