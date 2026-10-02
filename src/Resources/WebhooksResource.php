<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;
use KnoxCall\WebhookSignatureVerificationException;

class WebhooksResource
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
        return $this->client->request('GET', '/v1/webhooks', $params ?: null);
    }

    /**
     * Yield every webhook, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->list($p));
    }

    public function get(string $webhookId): array
    {
        return self::data($this->client->request('GET', '/v1/webhooks/' . rawurlencode($webhookId)));
    }

    /**
     * The response's `secret_key` (the HMAC endpoint secret) is shown exactly
     * once — store it now.
     */
    public function create(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/webhooks', null, $input));
    }

    public function update(string $webhookId, array $input): array
    {
        return self::data($this->client->request('PATCH', '/v1/webhooks/' . rawurlencode($webhookId), null, $input));
    }

    /** @return array{deleted: bool} */
    public function delete(string $webhookId): array
    {
        return self::data($this->client->request('DELETE', '/v1/webhooks/' . rawurlencode($webhookId)));
    }

    /**
     * Paginated delivery logs. Params: page, per_page.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function getLogs(string $webhookId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/webhooks/' . rawurlencode($webhookId) . '/logs', $params ?: null);
    }

    /**
     * List the webhook event types that can be subscribed to.
     *
     * @return array{event_types: list<array{value: string, label: string, description: string}>}
     */
    public function listEventTypes(): array
    {
        return self::data($this->client->request('GET', '/v1/webhooks/event-types'));
    }

    /**
     * Fire a synthetic webhook.test event and return the delivery result.
     *
     * @return array{success: bool, status?: int, response_time_ms: int, error?: string}
     */
    public function test(string $webhookId): array
    {
        return self::data($this->client->request('POST', '/v1/webhooks/' . rawurlencode($webhookId) . '/test'));
    }

    /**
     * Verify an incoming webhook delivery AND parse it in one step — see
     * {@see KnoxCall::constructEvent()} for the full contract (formats,
     * tolerance, returned envelope shape).
     *
     * @param array<string, string|list<string>> $headers request headers, any casing
     * @param array{format?: string, tolerance_seconds?: int|null, header_name?: string} $opts
     * @return array<string, mixed>
     *
     * @throws WebhookSignatureVerificationException on any verification failure
     */
    public function constructEvent(string $rawBody, array $headers, #[\SensitiveParameter] string $secret, array $opts = []): array
    {
        return KnoxCall::constructEvent($rawBody, $headers, $secret, $opts);
    }
}
