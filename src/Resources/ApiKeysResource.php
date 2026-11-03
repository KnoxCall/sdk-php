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
     * Every row carries `expires_at` — read it rather than assuming: a key
     * created without an explicit expiry choice is on a 90-day clock, and
     * `null` is the only value that means "never expires".
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
     * transaction as the key. Only the SEEDED key roles are accepted
     * (`seeded: true` — Key — Invoke, Key — Read-only, Key — Editor,
     * Key — Infrastructure); any other role is refused `403 forbidden`, because
     * custom roles are being retired. Discover them with
     * `$client->roles->list(['subject_kind' => 'api_key'])` and filter on
     * `seeded`. A key created with no role is default-denied on every
     * policy-gated endpoint.
     *
     * A key can never mint a key more privileged than itself: if a requested
     * role grants something this credential does not hold, the server answers
     * `403 privilege_escalation` (a PermissionDeniedException) and names the
     * offending grant verbatim.
     *
     * Expiry: pass AT MOST ONE of `expires_in_days` (1-365), `expires_at`
     * (ISO 8601, future, at most 365 days out) and `never_expires => true`.
     * Two or more is `400 conflicting_expiry_fields`; out of range or in the
     * past is `400 invalid_expiry`. Both are the existing 400 exception,
     * discriminated by its error code — no new exception class.
     *
     * **Passing none of them does NOT mean "no expiry" — the key gets 90
     * days.** Read `expires_at` off the result for the instant that was
     * applied; it is `null` only when `never_expires` was sent. `$input` is
     * forwarded verbatim, so a key you do not set is ABSENT from the body
     * rather than sent as null — which is the difference between "apply the
     * default" and "never expires".
     *
     * @return array{id: string, key_id: string, api_key: string, key_prefix: string, key_type: string, name: string, role_ids: list<string>, expires_at: ?string, message: string}
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
