<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * Promotion opportunities — mirrors src/client-api/opportunities.ts (PR5).
 *
 * "We detected outbound API usage → create a route", surfaced from two sources
 * (agent_monitor + gateway_traffic). {@see self::list()} refreshes gateway
 * detection on read; {@see self::accept()} promotes a suggestion to a durable
 * route + secret binding; {@see self::dismiss()} buries a pending suggestion.
 *
 * Each opportunity row is `{id, source('agent_monitor'|'gateway_traffic'),
 * service, destination_host, status('pending'|'snoozed'|'onboarded'|
 * 'dismissed'), confidence, suggested_route_json, evidence_json,
 * accepted_route_id, created_at, updated_at, acted_at}`.
 */
class OpportunitiesResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /**
     * List promotion opportunities (GET /v1/opportunities). Refreshes gateway
     * detection on read. Paginated. Params: page (default 1), per_page
     * (default 20, max 100), and `status` (one of pending|snoozed|onboarded|
     * dismissed).
     *
     * @param array<string, mixed> $params
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/opportunities', $params ?: null);
    }

    /**
     * Yield every opportunity, walking pages transparently.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    /**
     * Promote a gateway suggestion to a durable route + secret binding
     * (POST /v1/opportunities/:id/accept).
     *
     * Optional input keys:
     *   - `collection_name` — collection to file the route under (else the
     *                          suggestion's, else "Wrapped APIs").
     *   - `environment`      — environment the route config is written into
     *                          (else the tenant default).
     *   - `secret`           — secret name/id to bind (else an escrowed wrap
     *                          credential for the host is auto-bound).
     *   - `header_name`      — injected header name (default Authorization).
     *   - `value_prefix`     — value prefix (default "Bearer ").
     *
     * @param array<string, mixed> $input
     * @return array{opportunity_id: string, route: array{id: string, slug: ?string, name: string}, collection_id: string, environment: string}
     */
    public function accept(string $id, array $input = []): array
    {
        return self::data($this->client->request('POST', '/v1/opportunities/' . rawurlencode($id) . '/accept', null, $input));
    }

    /**
     * Dismiss a pending suggestion (POST /v1/opportunities/:id/dismiss).
     *
     * @return array{opportunity_id: string, status: string}
     */
    public function dismiss(string $id): array
    {
        return self::data($this->client->request('POST', '/v1/opportunities/' . rawurlencode($id) . '/dismiss'));
    }
}
