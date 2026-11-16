<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * Request Logs — the per-call proxy log, and Merkle inclusion proofs.
 *
 * Distinct from {@see AuditLogsResource}, which is the CHANGE log (who edited
 * what). This is the record of requests that went THROUGH the proxy, and
 * {@see self::proof()} is the evidence that a given entry existed, unaltered,
 * when it was anchored.
 */
class LogsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * One page of the request log feed. Params: cursor, limit, route_id,
     * status_code.
     *
     * Keyset, not offset: ordering is ascending `cursor` and stable across
     * calls. Delivery is AT LEAST ONCE — dedupe on `request_id`.
     * `meta.next_cursor` is OPAQUE; pass it back verbatim. A null
     * `next_cursor` means the feed is drained to the watermark, NOT that it
     * has ended.
     *
     * Captured request/response BODIES are never returned here at any
     * permission level. The identity fields (`src_ip`, `matched_client_id`,
     * `identification_method`) require `log:read_identity` and are ABSENT
     * rather than null when denied; `meta._redacted` says so.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, limit: int, delivery: string, dedupe_on: string, watermark_seconds: int}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/logs', $params ?: null);
    }

    /**
     * Yield every row, walking the cursor until the feed is drained to the
     * watermark.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iterateCursor($params, fn (array $p): array => $this->list($p));
    }

    /**
     * One request by its `request_id` (the `X-Request-Id` header value).
     *
     * @return array<string, mixed>
     */
    public function get(string $requestId): array
    {
        return self::data($this->client->request('GET', '/v1/logs/' . rawurlencode($requestId)));
    }

    /**
     * Merkle inclusion proof for one request.
     *
     * Returns for every outcome — read `anchored` and `verified` rather than
     * catching. `verified: false` with `reason: "range_incomplete"` is the
     * expected result for an old entry whose anchored range has since been
     * trimmed by retention, and is NOT a sign of tampering; `"root_mismatch"`
     * is. A proof is never returned alongside a failed verification.
     *
     * @return array<string, mixed>
     */
    public function proof(string $requestId): array
    {
        return self::data($this->client->request('GET', '/v1/logs/' . rawurlencode($requestId) . '/proof'));
    }
}
