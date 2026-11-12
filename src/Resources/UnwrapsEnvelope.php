<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

/**
 * The server wraps every JSON success response in `{ "data": ..., "meta": ... }`
 * (see sdk/PARITY.md §4). Resource methods unwrap:
 *
 *   - single-object methods return `data` (via {@see self::data()});
 *   - paginated lists return the envelope as-is — `{data: T[], meta: {total,
 *     page, per_page, total_pages, request_id}}` — and take page / per_page
 *     params (server default 20, cap 100);
 *   - bare-array endpoints unwrap `data` to a plain list (no page params);
 *   - iterate() walks pages until page >= meta.total_pages or an empty page.
 *
 * TWO endpoints are keyset/cursor paginated instead, deliberately:
 * `GET /v1/audit-logs/events` and `GET /v1/logs`. Offset pagination over a
 * table that is being written to skips and repeats rows with no way to tell
 * which — fine for a console, wrong for a feed. Use
 * {@see self::iterateCursor()} for those. (Earlier revisions of this comment
 * said there was no cursor pagination anywhere on the API; that stopped being
 * true when the audit event feed shipped.)
 */
trait UnwrapsEnvelope
{
    /**
     * Unwrap `data` from a `{data, meta}` envelope. Tolerates a bare payload
     * (self-hosted / older servers) by passing it through unchanged.
     */
    private static function data(mixed $response): mixed
    {
        return is_array($response) && array_key_exists('data', $response) ? $response['data'] : $response;
    }

    /**
     * Page-based iteration over a paginated list endpoint: starts at
     * $params['page'] (default 1), fetches via $fetchPage(page-merged params),
     * yields each row, and stops when page >= meta.total_pages or a page
     * comes back empty (defensive).
     *
     * @param array<string, mixed> $params page, per_page, plus endpoint filters
     * @param callable(array<string, mixed>): array $fetchPage
     * @return \Generator<int, array<string, mixed>>
     */
    private static function iteratePages(array $params, callable $fetchPage): \Generator
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        while (true) {
            $result = $fetchPage(['page' => $page] + $params);
            $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
            foreach ($rows as $row) {
                yield $row;
            }
            $totalPages = $result['meta']['total_pages'] ?? null;
            if ($rows === [] || !is_numeric($totalPages) || $page >= (int) $totalPages) {
                return;
            }
            $page++;
        }
    }

    /**
     * Cursor iteration over a keyset feed: starts at $params['cursor'], yields
     * each row, and STOPS when the server reports `meta.next_cursor === null`
     * — which means the feed is drained to the watermark, NOT that it has
     * ended. To keep following it, call again later with the last cursor you
     * saw; a generator that blocked forever would be unusable from a batch job.
     *
     * `next_cursor` is OPAQUE: it is passed back verbatim and never parsed.
     * Delivery is at-least-once — dedupe on `meta.dedupe_on`.
     *
     * @param array<string, mixed> $params cursor, limit, plus endpoint filters
     * @param callable(array<string, mixed>): array $fetchPage
     * @return \Generator<int, array<string, mixed>>
     */
    private static function iterateCursor(array $params, callable $fetchPage): \Generator
    {
        $cursor = $params['cursor'] ?? null;
        while (true) {
            $result = $fetchPage($cursor === null ? $params : ['cursor' => $cursor] + $params);
            $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
            foreach ($rows as $row) {
                yield $row;
            }
            $next = $result['meta']['next_cursor'] ?? null;
            if ($next === null) {
                return;
            }
            $cursor = $next;
        }
    }
}
