<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class AuditLogsResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100),
     * plus endpoint filters.
     *
     * Each row's `details` is sanitised by the server. It is an empty array with
     * `details_redacted` true when the caller may not read audit details (a
     * user-bound token whose holder is not an Admin or Owner); API keys and
     * OAuth clients holding `audit_log:list` always receive sanitised details.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/audit-logs', $params ?: null);
    }

    /**
     * Yield every audit-log row, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    /**
     * One page of the keyset audit event feed — the endpoint a SIEM shipper
     * should use. Params: cursor, limit, action, action_prefix, resource_type.
     *
     * {@see self::list()} is offset-paginated over `created_at DESC`, which is
     * right for a console and wrong for a feed: rows written while you page
     * shift the offsets underneath you, so events are skipped or repeated with
     * no way to tell which. This is ordered by a monotonic sequence and
     * resumes from an opaque cursor.
     *
     * `action` is exact-match; `action_prefix` subscribes to a whole SURFACE —
     * `"ai_gateway."` covers every AI-gateway action INCLUDING names added
     * after your integration was built, which exact-match cannot.
     *
     * Delivery is AT LEAST ONCE — dedupe on `id`. `meta.next_cursor` is
     * OPAQUE; pass it back verbatim.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, limit: int, delivery: string, dedupe_on: string, watermark_seconds: int}}
     */
    public function events(array $params = []): array
    {
        return $this->client->request('GET', '/v1/audit-logs/events', $params ?: null);
    }

    /**
     * Yield every audit event, walking the cursor until the feed is drained to
     * the watermark.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateEvents(array $params = []): \Generator
    {
        return self::iterateCursor($params, fn (array $p): array => $this->events($p));
    }
}
