<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class VaultsResource
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
        return $this->client->request('GET', '/v1/vaults', $params ?: null);
    }

    /**
     * Yield every vault, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    public function get(string $nameOrId): array
    {
        return self::data($this->client->request('GET', '/v1/vaults/' . rawurlencode($nameOrId)));
    }

    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/vaults', null, $input));
    }

    public function update(string $nameOrId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/vaults/' . rawurlencode($nameOrId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $nameOrId): array
    {
        return self::data($this->client->request('DELETE', '/v1/vaults/' . rawurlencode($nameOrId)));
    }

    /** @return array{new_version: int} */
    public function rotate(string $nameOrId): array
    {
        return self::data($this->client->request('POST', '/v1/vaults/' . rawurlencode($nameOrId) . '/rotate', null, []));
    }

    // -- Token operations --

    /**
     * $cardExpMonth / $cardExpYear are the CARD's own expiry, for a `pan` vault
     * only -- not $ttlSeconds, which is how long the TOKEN lives. Both or
     * neither; the year is four digits (2029, never 29). Supplying them
     * subscribes the token to the `vault.token.expiring` webhook, emitted 60 and
     * 30 days before the card expires. Offering them to a non-`pan` vault is a
     * validation_error.
     *
     * The result also carries `card_funding_type` ("credit" / "debit" /
     * "prepaid") and `card_issuing_country` (ISO 3166-1 alpha-2), derived from
     * the card's first six digits. BOTH ARE null ON EVERY TOKEN TODAY and will
     * be until KnoxCall licenses a BIN table -- treat them as optional
     * indefinitely.
     *
     * @return array{id: string, token: string, expires_at: ?string, created_at: string, card_expires_on: ?string, card_funding_type: ?string, card_issuing_country: ?string}
     */
    public function tokenize(string $nameOrId, string $value, array $metadata = [], ?int $ttlSeconds = null, ?int $cardExpMonth = null, ?int $cardExpYear = null): array
    {
        $body = array_filter([
            'value' => $value,
            'metadata' => $metadata ?: null,
            'ttl_seconds' => $ttlSeconds,
            'card_exp_month' => $cardExpMonth,
            'card_exp_year' => $cardExpYear,
        ], fn($v) => $v !== null);
        return self::data($this->client->request('POST', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens', null, $body));
    }

    /**
     * Each item needs a `value`; optional `metadata`, `ttl_seconds`, and -- for a
     * `pan` vault -- `card_exp_month` / `card_exp_year` (both or neither). A
     * refusal names the offending index and rolls the whole batch back.
     *
     * @return array{tokens: list<array<string, mixed>>, count: int}
     */
    public function bulkTokenize(string $nameOrId, array $values): array
    {
        return self::data($this->client->request('POST', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens/bulk', null, ['values' => $values]));
    }

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listTokens(string $nameOrId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens', $params ?: null);
    }

    /**
     * Yield every token in a vault, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateTokens(string $nameOrId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listTokens($nameOrId, $p));
    }

    public function detokenize(string $nameOrId, string $idOrToken): array
    {
        return self::data($this->client->request('GET', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens/' . rawurlencode($idOrToken)));
    }

    /** @return array{updated: bool} */
    public function updateToken(string $nameOrId, string $idOrToken, array $metadata): array
    {
        return self::data($this->client->request('PATCH', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens/' . rawurlencode($idOrToken), null, ['metadata' => $metadata]));
    }

    /** @return array{deleted: bool} */
    public function deleteToken(string $nameOrId, string $idOrToken): array
    {
        return self::data($this->client->request('DELETE', '/v1/vaults/' . rawurlencode($nameOrId) . '/tokens/' . rawurlencode($idOrToken)));
    }
}
