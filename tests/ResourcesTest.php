<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\KnoxCall;
use PHPUnit\Framework\TestCase;

/**
 * Resource-layer envelope handling (PARITY §4/§11): the server wraps every
 * JSON response in {data, meta}; single-object methods unwrap `data`,
 * paginated lists return the envelope and take page/per_page, bare-array
 * endpoints unwrap to plain arrays, iterate() walks pages. Every mock here
 * is the REAL server shape (src/client-api/helpers.ts) — never a bare object,
 * never a cursor.
 */
final class ResourcesTest extends TestCase
{
    private MockTransport $t;
    private KnoxCall $client;

    protected function setUp(): void
    {
        $this->t = new MockTransport();
        // A pre-acquired kc_ token: no token-endpoint round trip, so request
        // #N is API call #N.
        $this->client = new KnoxCall([
            'tenant' => 'acme',
            'api_key' => 'kc_live_x',
            'transport' => $this->t,
            'retry_base_delay_ms' => 0,
            'base_url' => 'https://api.example.test',
            'proxy_base_url' => 'https://acme.example.test',
        ]);
    }

    /** success(res, data, meta?) — {data, meta: {request_id}} */
    private static function envelope(mixed $data): array
    {
        return ['data' => $data, 'meta' => ['request_id' => 'req-' . bin2hex(random_bytes(4))]];
    }

    /** paginated(res, data[], total, page, perPage) — meta carries the page math */
    private static function paginated(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'request_id' => 'req-' . bin2hex(random_bytes(4)),
            ],
        ];
    }

    private function lastUrl(): string
    {
        return $this->t->lastRequest()['url'];
    }

    private function lastBody(): array
    {
        return json_decode((string) $this->t->lastRequest()['body'], true);
    }

    // -- Envelope & pagination -------------------------------------------------------

    public function testPaginatedListReturnsEnvelopeAndSendsPageParams(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'r_1'], ['id' => 'r_2']], 42, 2, 2));

        $page = $this->client->routes->list(['page' => 2, 'per_page' => 2, 'collection_id' => 'c_9']);

        $this->assertSame('https://api.example.test/v1/routes?page=2&per_page=2&collection_id=c_9', $this->lastUrl());
        $this->assertSame([['id' => 'r_1'], ['id' => 'r_2']], $page['data']);
        $this->assertSame(42, $page['meta']['total']);
        $this->assertSame(21, $page['meta']['total_pages']);
    }

    public function testGetUnwrapsSingleObject(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'r_1', 'name' => 'orders', 'slug' => 'default-orders']));

        $route = $this->client->routes->get('r_1');

        $this->assertSame('https://api.example.test/v1/routes/r_1', $this->lastUrl());
        $this->assertSame('orders', $route['name']); // unwrapped — no ['data'] indirection
        $this->assertArrayNotHasKey('data', $route);
        $this->assertArrayNotHasKey('meta', $route);
    }

    public function testDeleteUnwrapsToDeletedFlag(): void
    {
        $this->t->queueJson(200, self::envelope(['deleted' => true]));

        $this->assertSame(['deleted' => true], $this->client->routes->delete('r_1'));
    }

    public function testIterateWalksAllPagesAndStopsAtTotalPages(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'r_1'], ['id' => 'r_2']], 5, 1, 2));
        $this->t->queueJson(200, self::paginated([['id' => 'r_3'], ['id' => 'r_4']], 5, 2, 2));
        $this->t->queueJson(200, self::paginated([['id' => 'r_5']], 5, 3, 2));

        $ids = [];
        foreach ($this->client->routes->iterate(['per_page' => 2]) as $route) {
            $ids[] = $route['id'];
        }

        $this->assertSame(['r_1', 'r_2', 'r_3', 'r_4', 'r_5'], $ids);
        $this->assertCount(3, $this->t->requests); // exactly total_pages fetches
        $this->assertSame('https://api.example.test/v1/routes?page=1&per_page=2', $this->t->requests[0]['url']);
        $this->assertSame('https://api.example.test/v1/routes?page=2&per_page=2', $this->t->requests[1]['url']);
        $this->assertSame('https://api.example.test/v1/routes?page=3&per_page=2', $this->t->requests[2]['url']);
    }

    public function testIterateStopsDefensivelyOnEmptyPage(): void
    {
        // meta claims more pages, but an empty page must stop the walk.
        $this->t->queueJson(200, self::paginated([['id' => 's_1']], 50, 1, 1));
        $this->t->queueJson(200, self::paginated([], 50, 2, 1));

        $rows = iterator_to_array($this->client->secrets->iterate(['per_page' => 1]), false);

        $this->assertCount(1, $rows);
        $this->assertCount(2, $this->t->requests);
    }

    public function testIterateStartsAtRequestedPage(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 'v_3']], 3, 3, 1));

        $rows = iterator_to_array($this->client->vaults->iterate(['page' => 3, 'per_page' => 1]), false);

        $this->assertSame([['id' => 'v_3']], $rows);
        $this->assertSame('https://api.example.test/v1/vaults?page=3&per_page=1', $this->lastUrl());
    }

    public function testBareArrayEndpointsUnwrapToPlainArraysWithoutPageParams(): void
    {
        $calls = [
            [fn () => $this->client->environments->list(), '/v1/environments'],
            [fn () => $this->client->agents->list(), '/v1/agents'],
            [fn () => $this->client->crypto->listKeys(), '/v1/crypto/keys'],
            [fn () => $this->client->routes->listEnvironments('r_1'), '/v1/routes/r_1/environments'],
            [fn () => $this->client->clients->listCredentials('c_1'), '/v1/clients/c_1/credentials'],
            [fn () => $this->client->dynamicDb->list(), '/v1/dyn-db-credentials'],
        ];
        foreach ($calls as [$call, $path]) {
            $this->t->queueJson(200, self::envelope([['id' => 'x_1'], ['id' => 'x_2']]));
            $rows = $call();
            $this->assertSame([['id' => 'x_1'], ['id' => 'x_2']], $rows, $path);
            // bare-array endpoints never send pagination params
            $this->assertSame('https://api.example.test' . $path, $this->lastUrl());
        }
    }

    // -- Route field-actions ------------------------------------------------------

    public function testRouteActionLifecycle(): void
    {
        $this->t->queueJson(200, self::envelope([['id' => 'a_1', 'direction' => 'request', 'action' => 'encrypt']]));
        $actions = $this->client->routes->listActions('r_1');
        $this->assertSame('https://api.example.test/v1/routes/r_1/actions', $this->lastUrl());
        $this->assertSame('a_1', $actions[0]['id']);

        $this->t->queueJson(200, self::envelope(['id' => 'a_2', 'direction' => 'response', 'action' => 'decrypt']));
        $created = $this->client->routes->createAction('r_1', [
            'direction' => 'response', 'action' => 'decrypt', 'selectors' => ['$.card.number'],
        ]);
        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame(['$.card.number'], $this->lastBody()['selectors']);
        $this->assertSame('a_2', $created['id']);

        $this->t->queueJson(200, self::envelope(['deleted' => 'a_2']));
        $deleted = $this->client->routes->deleteAction('r_1', 'a_2');
        $this->assertSame('https://api.example.test/v1/routes/r_1/actions/a_2', $this->lastUrl());
        $this->assertSame(['deleted' => 'a_2'], $deleted);
    }

    // -- Secrets: typed OAuth2 / certificate create helpers --------------------------

    public function testCreateOAuth2PostsToOauth2PathWithProviderFields(): void
    {
        $this->t->queueJson(201, self::envelope([
            'id' => 's_oa', 'name' => 'Google Drive', 'shortcode_name' => 'google-drive',
            'base_environment' => 'production', 'environment_count' => 1, 'secret_type' => 'oauth2',
            'collection_id' => null, 'expires_at' => null, 'strict_expiry_enforcement' => false,
        ]));

        $created = $this->client->secrets->createOAuth2([
            'name' => 'Google Drive',
            'provider' => 'google',
            'client_id' => 'goog-client-123',
            'client_secret' => 'goog-secret',
            'scopes' => ['drive.readonly', 'profile'],
            'token_url' => 'https://oauth2.googleapis.com/token',
            'grant_type' => 'authorization_code',
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/secrets/oauth2', $this->lastUrl());
        $body = $this->lastBody();
        $this->assertSame('google', $body['provider']);
        $this->assertSame('goog-client-123', $body['client_id']);
        $this->assertSame('goog-secret', $body['client_secret']);
        $this->assertSame(['drive.readonly', 'profile'], $body['scopes']);
        $this->assertSame('authorization_code', $body['grant_type']);
        // unwrapped — no envelope indirection leaks to the caller
        $this->assertSame('s_oa', $created['id']);
        $this->assertSame('oauth2', $created['secret_type']);
        $this->assertArrayNotHasKey('data', $created);
        $this->assertArrayNotHasKey('meta', $created);
    }

    public function testCreateCertificatePostsToCertificatePathWithCertFields(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n";
        $this->t->queueJson(201, self::envelope([
            'id' => 's_cert', 'name' => 'mTLS Client', 'shortcode_name' => 'mtls-client',
            'base_environment' => 'production', 'environment_count' => 1, 'secret_type' => 'certificate',
            'collection_id' => 'col_1', 'expires_at' => null, 'strict_expiry_enforcement' => false,
        ]));

        $created = $this->client->secrets->createCertificate([
            'name' => 'mTLS Client',
            'certificate_content' => $pem,
            'private_key' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
            'passphrase' => 'hunter2',
            'certificate_type' => 'pem',
            'collection_id' => 'col_1',
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/secrets/certificate', $this->lastUrl());
        $body = $this->lastBody();
        $this->assertSame($pem, $body['certificate_content']);
        $this->assertSame('hunter2', $body['passphrase']);
        $this->assertSame('pem', $body['certificate_type']);
        $this->assertSame('col_1', $body['collection_id']);
        $this->assertSame('s_cert', $created['id']);
        $this->assertSame('certificate', $created['secret_type']);
        $this->assertArrayNotHasKey('data', $created);
    }

    // -- Wrap: escrow a raw provider credential into custody -------------------------

    public function testWrapEscrowPostsCredentialAndUnwrapsMetadataWithoutEchoingValue(): void
    {
        // The value is write-only: the server stores it and returns metadata
        // only (secret_id/name/provider/allowed_hosts/sandbox) — never the value.
        $this->t->queueJson(201, self::envelope([
            'secret_id' => 's_wrap',
            'name' => 'stripe-live',
            'provider' => 'stripe',
            'allowed_hosts' => ['api.stripe.com'],
            'sandbox' => false,
        ]));

        $escrowed = $this->client->wrap->escrow([
            'provider' => 'stripe',
            'name' => 'stripe-live',
            'value' => 'sk_live_super_secret',
            'hosts' => ['api.stripe.com'],
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/wrap/credentials', $this->lastUrl());

        // The full credential travels in the request body exactly as given.
        $body = $this->lastBody();
        $this->assertSame('stripe', $body['provider']);
        $this->assertSame('stripe-live', $body['name']);
        $this->assertSame('sk_live_super_secret', $body['value']);
        $this->assertSame(['api.stripe.com'], $body['hosts']);

        // The response unwraps to metadata; the value is never echoed back.
        $this->assertSame('s_wrap', $escrowed['secret_id']);
        $this->assertSame('stripe-live', $escrowed['name']);
        $this->assertSame('stripe', $escrowed['provider']);
        $this->assertSame(['api.stripe.com'], $escrowed['allowed_hosts']);
        $this->assertFalse($escrowed['sandbox']);
        $this->assertArrayNotHasKey('value', $escrowed);
        $this->assertArrayNotHasKey('data', $escrowed);
        $this->assertArrayNotHasKey('meta', $escrowed);
    }

    // -- Wrap: base-URL gateway token management (PARITY §11/§18) ---------------------

    public function testWrapGatewayUrlPostsOnlyPresentKeysAndUnwrapsToken(): void
    {
        // The response carries the token embedded in base_url plus metadata.
        $this->t->queueJson(201, self::envelope([
            'id' => 'wt_1',
            'token' => 'wg_secret_bearer',
            'base_url' => 'https://acme.example.test/wg/wg_secret_bearer/api.stripe.com',
            'host' => 'api.stripe.com',
            'secret_id' => 's_wrap',
            'sandbox' => false,
            'expires_at' => '2026-09-01T00:00:00Z',
        ]));

        $minted = $this->client->wrap->gatewayUrl([
            'secret' => 'stripe-live',
            'host' => 'api.stripe.com',
            'ttl_seconds' => 3600,
            'label' => 'checkout worker',
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/wrap/tokens', $this->lastUrl());

        // Only the supplied keys travel, snake_case, exactly as given.
        $body = $this->lastBody();
        $this->assertSame('stripe-live', $body['secret']);
        $this->assertSame('api.stripe.com', $body['host']);
        $this->assertSame(3600, $body['ttl_seconds']);
        $this->assertSame('checkout worker', $body['label']);

        // The response unwraps to the token + metadata.
        $this->assertSame('wt_1', $minted['id']);
        $this->assertSame('wg_secret_bearer', $minted['token']);
        $this->assertSame('https://acme.example.test/wg/wg_secret_bearer/api.stripe.com', $minted['base_url']);
        $this->assertSame('api.stripe.com', $minted['host']);
        $this->assertSame('s_wrap', $minted['secret_id']);
        $this->assertFalse($minted['sandbox']);
        $this->assertSame('2026-09-01T00:00:00Z', $minted['expires_at']);
        $this->assertArrayNotHasKey('data', $minted);
        $this->assertArrayNotHasKey('meta', $minted);
    }

    public function testWrapGatewayUrlOmitsAbsentOptionalKeys(): void
    {
        $this->t->queueJson(201, self::envelope([
            'id' => 'wt_2', 'token' => 'wg_2', 'base_url' => 'https://acme.example.test/wg/wg_2/api.stripe.com',
            'host' => 'api.stripe.com', 'secret_id' => 's_wrap', 'sandbox' => true, 'expires_at' => null,
        ]));

        $this->client->wrap->gatewayUrl(['secret' => 'stripe-live']);

        // Only `secret` was supplied — no host/ttl_seconds/label/style leak into the body.
        $body = $this->lastBody();
        $this->assertSame(['secret' => 'stripe-live'], $body);
        $this->assertArrayNotHasKey('host', $body);
        $this->assertArrayNotHasKey('ttl_seconds', $body);
        $this->assertArrayNotHasKey('label', $body);
        $this->assertArrayNotHasKey('style', $body);
    }

    public function testWrapGatewayUrlForwardsStyleAndSurfacesBaseUrlStyle(): void
    {
        // The server echoes the chosen base_url form back as base_url_style.
        $this->t->queueJson(201, self::envelope([
            'id' => 'wt_3', 'token' => 'wg_3',
            'base_url' => 'https://acme.example.test/wg/wg_3/api.stripe.com',
            'base_url_style' => 'path', 'host' => 'api.stripe.com',
            'secret_id' => 's_wrap', 'sandbox' => false, 'expires_at' => null,
        ]));

        $minted = $this->client->wrap->gatewayUrl([
            'secret' => 'stripe-live',
            'host' => 'api.stripe.com',
            'style' => 'path',
        ]);

        // `style` travels in the POST body exactly as given.
        $body = $this->lastBody();
        $this->assertSame('path', $body['style']);
        $this->assertSame('stripe-live', $body['secret']);

        // …and the chosen form is surfaced back to the caller unchanged.
        $this->assertSame('path', $minted['base_url_style']);
        $this->assertArrayNotHasKey('data', $minted);
        $this->assertArrayNotHasKey('meta', $minted);
    }

    public function testWrapListGatewayTokensSurfacesTokenListMetadataOnly(): void
    {
        // Metadata only — the token value is never in a list row.
        $this->t->queueJson(200, self::envelope(['tokens' => [
            [
                'id' => 'wt_1', 'secret_id' => 's_wrap', 'host' => 'api.stripe.com', 'label' => 'checkout worker',
                'created_at' => '2026-08-14T00:00:00Z', 'expires_at' => '2026-09-01T00:00:00Z',
                'revoked_at' => null, 'last_used_at' => '2026-08-15T12:00:00Z',
            ],
            [
                'id' => 'wt_2', 'secret_id' => 's_wrap', 'host' => 'api.mailgun.net', 'label' => null,
                'created_at' => '2026-08-14T00:00:00Z', 'expires_at' => null,
                'revoked_at' => '2026-08-16T00:00:00Z', 'last_used_at' => null,
            ],
        ]]));

        $tokens = $this->client->wrap->listGatewayTokens();

        $this->assertSame('GET', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/wrap/tokens', $this->lastUrl());

        // The `tokens` array is surfaced directly — no envelope, no wrapping object.
        $this->assertCount(2, $tokens);
        $this->assertSame('wt_1', $tokens[0]['id']);
        $this->assertSame('api.stripe.com', $tokens[0]['host']);
        $this->assertNull($tokens[1]['label']);
        $this->assertSame('2026-08-16T00:00:00Z', $tokens[1]['revoked_at']);
        // No token value is ever present in a list row.
        $this->assertArrayNotHasKey('token', $tokens[0]);
    }

    public function testWrapInterceptManifestGetsTheManifestAndForwardsEnvironment(): void
    {
        $manifest = [
            'version' => 'sha256:abc', 'ttl_seconds' => 60, 'environment' => 'production', 'sandbox' => false,
            'routes' => [[
                'host' => 'api.hubapi.com', 'base_path' => '/crm/v3', 'slug' => 'hubspot', 'route_id' => 'r-1',
                'requires_clients' => false, 'allowed_methods' => null, 'updated_at' => '2026-09-25T00:00:00.000Z',
            ]],
        ];
        $this->t->queueJson(200, self::envelope($manifest));
        $this->t->queueJson(200, self::envelope(['environment' => 'staging'] + $manifest));

        $m = $this->client->wrap->interceptManifest();
        $this->assertSame('GET', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/wrap/intercept-manifest', $this->lastUrl());
        $this->assertSame('sha256:abc', $m['version']);
        $this->assertSame(60, $m['ttl_seconds']);
        $this->assertSame('api.hubapi.com', $m['routes'][0]['host']);
        $this->assertSame('hubspot', $m['routes'][0]['slug']);
        $this->assertSame('/crm/v3', $m['routes'][0]['base_path']);

        $staging = $this->client->wrap->interceptManifest(['environment' => 'staging']);
        $this->assertSame('https://api.example.test/v1/wrap/intercept-manifest?environment=staging', $this->lastUrl());
        $this->assertSame('staging', $staging['environment']);
    }

    public function testWrapRevokeGatewayTokenDeletesEncodedIdAndUnwrapsFlag(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'wt/1', 'revoked' => true]));

        $result = $this->client->wrap->revokeGatewayToken('wt/1');

        $this->assertSame('DELETE', $this->t->lastRequest()['method']);
        // The id is rawurlencode()'d into the path — `/` becomes %2F.
        $this->assertSame('https://api.example.test/v1/wrap/tokens/wt%2F1', $this->lastUrl());
        $this->assertSame(['id' => 'wt/1', 'revoked' => true], $result);
    }

    // -- Opportunities: promote detected outbound usage to a route -------------------

    public function testOpportunitiesListGetsPaginatedWithStatusFilter(): void
    {
        $this->t->queueJson(200, self::paginated([
            ['id' => 'op_1', 'source' => 'gateway_traffic', 'service' => 'stripe', 'status' => 'pending'],
            ['id' => 'op_2', 'source' => 'agent_monitor', 'service' => 'twilio', 'status' => 'pending'],
        ], 2, 1, 20));

        $page = $this->client->opportunities->list(['status' => 'pending', 'page' => 1, 'per_page' => 20]);

        $this->assertSame('GET', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/opportunities?status=pending&page=1&per_page=20', $this->lastUrl());
        $this->assertSame('op_1', $page['data'][0]['id']);
        $this->assertSame(2, $page['meta']['total']);
    }

    public function testOpportunitiesAcceptPostsBodyAndUnwrapsRoute(): void
    {
        $this->t->queueJson(200, self::envelope([
            'opportunity_id' => 'op_1',
            'route' => ['id' => 'r_9', 'slug' => 'wrapped-stripe', 'name' => 'Stripe'],
            'collection_id' => 'c_wrap',
            'environment' => 'production',
        ]));

        $accepted = $this->client->opportunities->accept('op_1', [
            'collection_name' => 'Wrapped APIs',
            'environment' => 'production',
            'secret' => 'stripe-live',
            'header_name' => 'Authorization',
            'value_prefix' => 'Bearer ',
        ]);

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/opportunities/op_1/accept', $this->lastUrl());

        $body = $this->lastBody();
        $this->assertSame('Wrapped APIs', $body['collection_name']);
        $this->assertSame('production', $body['environment']);
        $this->assertSame('stripe-live', $body['secret']);
        $this->assertSame('Authorization', $body['header_name']);
        $this->assertSame('Bearer ', $body['value_prefix']);

        // Response unwraps to the promotion result — no envelope indirection.
        $this->assertSame('op_1', $accepted['opportunity_id']);
        $this->assertSame('r_9', $accepted['route']['id']);
        $this->assertSame('wrapped-stripe', $accepted['route']['slug']);
        $this->assertSame('c_wrap', $accepted['collection_id']);
        $this->assertSame('production', $accepted['environment']);
        $this->assertArrayNotHasKey('data', $accepted);
        $this->assertArrayNotHasKey('meta', $accepted);
    }

    public function testOpportunitiesDismissPostsAndUnwrapsStatus(): void
    {
        $this->t->queueJson(200, self::envelope(['opportunity_id' => 'op_1', 'status' => 'dismissed']));

        $result = $this->client->opportunities->dismiss('op_1');

        $this->assertSame('POST', $this->t->lastRequest()['method']);
        $this->assertSame('https://api.example.test/v1/opportunities/op_1/dismiss', $this->lastUrl());
        $this->assertSame(['opportunity_id' => 'op_1', 'status' => 'dismissed'], $result);
    }

    // -- Portable kc: encryption ----------------------------------------------------

    public function testPortableEncryptionEndpoints(): void
    {
        $this->t->queueJson(200, self::envelope(['ciphertext' => ['ssn' => 'kc:v1:abc'], 'key' => 'app-default', 'key_version' => 3]));
        $out = $this->client->crypto->encryptData(['ssn' => '123-45-6789'], ['key' => 'app-default', 'role' => 'pii']);
        $this->assertSame('https://api.example.test/v1/encrypt', $this->lastUrl());
        $this->assertSame(['data' => ['ssn' => '123-45-6789'], 'key' => 'app-default', 'role' => 'pii'], $this->lastBody());
        $this->assertSame('kc:v1:abc', $out['ciphertext']['ssn']);
        $this->assertSame(3, $out['key_version']);

        $this->t->queueJson(200, self::envelope(['plaintext' => ['ssn' => '123-45-6789']]));
        $out = $this->client->crypto->decryptData(['ssn' => 'kc:v1:abc']);
        $this->assertSame('https://api.example.test/v1/decrypt', $this->lastUrl());
        $this->assertSame('123-45-6789', $out['plaintext']['ssn']);

        $this->t->queueJson(200, self::envelope(['encrypted' => true, 'scheme' => 'ecies-p256', 'version' => 1]));
        $out = $this->client->crypto->inspect('kc:v1:abc');
        $this->assertSame('https://api.example.test/v1/inspect', $this->lastUrl());
        $this->assertTrue($out['encrypted']);

        $this->t->queueJson(200, self::envelope(['token' => 'kct_once', 'expires_at' => '2026-07-02T00:05:00Z', 'action' => 'decrypt']));
        $out = $this->client->crypto->mintClientToken(['action' => 'decrypt', 'data' => 'kc:v1:abc', 'ttl_seconds' => 300]);
        $this->assertSame('https://api.example.test/v1/client-tokens', $this->lastUrl());
        $this->assertSame('kct_once', $out['token']);

        $this->t->queueJson(200, self::envelope(['public_key' => 'BJf…', 'key_ref' => ['appKeyId' => 'k_1']]));
        $out = $this->client->crypto->getSealingBundle('app-default');
        $this->assertSame('https://api.example.test/v1/encrypt/sealing-bundle?key=app-default', $this->lastUrl());
        $this->assertSame('k_1', $out['key_ref']['appKeyId']);
    }

    // -- Crypto path fidelity (server: src/client-api/crypto.ts) ---------------------

    public function testCryptoEndpointPathsMatchServer(): void
    {
        $this->t->queueJson(200, self::envelope(['token' => 'eyJ…', 'key_version' => 1, 'alg' => 'ES256']));
        $this->client->crypto->signJwt('signing-key', ['sub' => 'user_1']);
        $this->assertSame('https://api.example.test/v1/crypto/keys/signing-key/jwt', $this->lastUrl());

        $this->t->queueJson(200, self::envelope(['valid' => true, 'claims' => ['sub' => 'user_1']]));
        $out = $this->client->crypto->verifyJwt('signing-key', 'eyJ…');
        $this->assertSame('https://api.example.test/v1/crypto/keys/signing-key/jwt/verify', $this->lastUrl());
        $this->assertTrue($out['valid']);

        $this->t->queueJson(200, self::envelope(['signature_header' => 't=1,v1=aa', 'timestamp_seconds' => 1, 'key_version' => 1, 'format' => 'stripe']));
        $this->client->crypto->signWebhook('signing-key', ['payload' => '{}']);
        $this->assertSame('https://api.example.test/v1/crypto/keys/signing-key/webhook-sign', $this->lastUrl());

        // format travels as a query param, not in the body
        $this->t->queueJson(200, self::envelope(['plaintext' => 'hi', 'key_version' => 1]));
        $out = $this->client->crypto->decrypt('transit-key', 'vault:v1:abc', 'utf8');
        $this->assertSame('https://api.example.test/v1/crypto/keys/transit-key/decrypt?format=utf8', $this->lastUrl());
        $this->assertSame(['ciphertext' => 'vault:v1:abc'], $this->lastBody());
        $this->assertSame('hi', $out['plaintext']);

        // version travels as a query param on the public-key endpoint
        $this->t->queueJson(200, self::envelope(['pem' => '-----BEGIN PUBLIC KEY-----', 'jwk' => [], 'key_version' => 2]));
        $this->client->crypto->getPublicKey('signing-key', 2);
        $this->assertSame('https://api.example.test/v1/crypto/keys/signing-key/public-key?version=2', $this->lastUrl());
    }

    // -- PKI ---------------------------------------------------------------------

    public function testPkiEndpoints(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n";
        $this->t->queueRaw(200, $pem, ['content-type' => 'text/x-pem-file']);
        $cert = $this->client->pki->getRootCert('internal-ca');
        $this->assertSame('https://api.example.test/v1/pki/roots/internal-ca/cert', $this->lastUrl());
        $this->assertSame($pem, $cert); // raw text, no JSON wrapper

        $this->t->queueJson(200, self::envelope(['intermediate_id' => 'i_2', 'not_after' => '2027-07-02T00:00:00Z']));
        $out = $this->client->pki->rotateIntermediate('internal-ca');
        $this->assertSame('https://api.example.test/v1/pki/roots/internal-ca/rotate-intermediate', $this->lastUrl());
        $this->assertSame('i_2', $out['intermediate_id']);

        $this->t->queueJson(200, self::envelope([
            'serial_hex' => 'ab12', 'cert_pem' => 'CERT', 'private_key_pem' => 'KEY',
            'ca_chain_pem' => 'CHAIN', 'not_before' => 'x', 'not_after' => 'y',
        ]));
        $issued = $this->client->pki->issueCert('internal-ca', 'web-servers', ['common_name' => 'api.internal.test']);
        $this->assertSame('https://api.example.test/v1/pki/roots/internal-ca/issue/web-servers', $this->lastUrl());
        $this->assertSame('api.internal.test', $this->lastBody()['common_name']);
        $this->assertSame('ab12', $issued['serial_hex']);

        $this->t->queueJson(200, self::envelope(['revoked' => true]));
        $this->client->pki->revokeCert('internal-ca', 'ab12', 'compromised');
        $this->assertSame(['serial_hex' => 'ab12', 'reason' => 'compromised'], $this->lastBody());
    }

    // -- OAuth clients (no meta; top-level warning folded in) -----------------------

    public function testOauthClientsUnwrapAndWarning(): void
    {
        // list: {data: rows}, no meta, NO pagination
        $this->t->queueJson(200, ['data' => [['id' => 'oc_1', 'client_id' => 'kc_client_1']]]);
        $rows = $this->client->oauthClients->list();
        $this->assertSame('kc_client_1', $rows[0]['client_id']);

        // create: {data: {...}, warning?: str} — warning folded into the result
        $this->t->queueJson(201, [
            'data' => ['id' => 'oc_2', 'client_id' => 'kc_client_2', 'client_secret' => 'shh'],
            'warning' => 'Store this secret now; it cannot be shown again.',
        ]);
        $created = $this->client->oauthClients->create(['name' => 'ci', 'grant_types' => ['client_credentials']]);
        $this->assertSame('kc_client_2', $created['client_id']);
        $this->assertSame('Store this secret now; it cannot be shown again.', $created['warning']);

        $this->t->queueJson(200, [
            'data' => ['client_id' => 'kc_client_2', 'client_secret' => 'shh2'],
            'warning' => 'Old secret invalidated.',
        ]);
        $rotated = $this->client->oauthClients->rotateSecret('oc_2');
        $this->assertSame('shh2', $rotated['client_secret']);
        $this->assertSame('Old secret invalidated.', $rotated['warning']);

        $this->t->queueJson(200, ['data' => ['revoked' => true]]);
        $this->assertSame(['revoked' => true], $this->client->oauthClients->revoke('oc_2'));
    }

    // -- Agents (hand-rolled 201 with once-only secret) ------------------------------

    public function testAgentsCreateUnwrapsOnceOnlySecret(): void
    {
        $this->t->queueJson(201, [
            'data' => ['id' => 'ag_1', 'name' => 'ci-agent', 'agent_id' => 'agent_abc', 'status' => 'active',
                       'require_verified_build' => false, 'created_at' => 'x', 'agent_secret' => 'as_once'],
            'meta' => ['secret_shown_once' => true],
        ]);

        $agent = $this->client->agents->create('ci-agent');

        $this->assertSame('as_once', $agent['agent_secret']);
        $this->assertArrayNotHasKey('meta', $agent);
    }

    // -- Dynamic DB (leases keep real limit/offset inside data) ----------------------

    public function testDynDbLeasesUnwrapWithLimitOffset(): void
    {
        $this->t->queueJson(200, self::envelope([
            'leases' => [['id' => 7, 'status' => 'active']], 'total' => 1, 'limit' => 10, 'offset' => 0,
        ]));

        $out = $this->client->dynamicDb->listLeases(10, 0, 'analytics-db');

        $this->assertSame('https://api.example.test/v1/dyn-db-credentials/leases?limit=10&offset=0&connection=analytics-db', $this->lastUrl());
        $this->assertSame(7, $out['leases'][0]['id']);
        $this->assertSame(1, $out['total']);

        $this->t->queueJson(200, self::envelope(['revoked' => 7]));
        $this->assertSame(['revoked' => 7], $this->client->dynamicDb->revokeLease(7));
        $this->assertSame('https://api.example.test/v1/dyn-db-credentials/leases/7/revoke', $this->lastUrl());
    }

    // -- Vault token pagination ------------------------------------------------------

    public function testVaultTokensPaginateAndIterate(): void
    {
        $this->t->queueJson(200, self::paginated([['id' => 't_1']], 3, 2, 1));
        $page = $this->client->vaults->listTokens('cards', ['page' => 2, 'per_page' => 1]);
        $this->assertSame('https://api.example.test/v1/vaults/cards/tokens?page=2&per_page=1', $this->lastUrl());
        $this->assertSame('t_1', $page['data'][0]['id']);

        $this->t->queueJson(200, self::paginated([['id' => 't_1']], 2, 1, 1));
        $this->t->queueJson(200, self::paginated([['id' => 't_2']], 2, 2, 1));
        $ids = array_column(iterator_to_array($this->client->vaults->iterateTokens('cards', ['per_page' => 1]), false), 'id');
        $this->assertSame(['t_1', 't_2'], $ids);
    }

    public function testVaultDeleteAndTokenDeleteReturnBooleanFlag(): void
    {
        // The server standardized delete payloads to {data: {deleted: true}}
        // (was the vault NAME / token id string).
        $this->t->queueJson(200, self::envelope(['deleted' => true]));
        $this->assertSame(['deleted' => true], $this->client->vaults->delete('cards'));
        $this->assertSame('https://api.example.test/v1/vaults/cards', $this->lastUrl());

        $this->t->queueJson(200, self::envelope(['deleted' => true]));
        $this->assertSame(['deleted' => true], $this->client->vaults->deleteToken('cards', 'tok_1'));
        $this->assertSame('https://api.example.test/v1/vaults/cards/tokens/tok_1', $this->lastUrl());
    }

    // -- Webhooks management ----------------------------------------------------------

    public function testWebhookEventTypesAndTestDelivery(): void
    {
        $this->t->queueJson(200, self::envelope(['event_types' => [
            ['value' => 'request.success', 'label' => 'Request success', 'description' => '…'],
        ]]));
        $out = $this->client->webhooks->listEventTypes();
        $this->assertSame('request.success', $out['event_types'][0]['value']);

        $this->t->queueJson(200, self::envelope(['success' => true, 'status' => 200, 'response_time_ms' => 31]));
        $out = $this->client->webhooks->test('wh_1');
        $this->assertSame('https://api.example.test/v1/webhooks/wh_1/test', $this->lastUrl());
        $this->assertTrue($out['success']);
    }

    // -- Account -----------------------------------------------------------------------

    public function testAccountUnwraps(): void
    {
        $this->t->queueJson(200, self::envelope(['id' => 'tn_1', 'slug' => 'acme', 'subscription_plan' => 'free']));

        $account = $this->client->account->get();

        $this->assertSame('acme', $account['slug']);
        $this->assertArrayNotHasKey('data', $account);
    }

    // -- role_ids + the role catalog (IaC plan §6 item 1.3) ---------------------

    public function testCreateApiKeySendsRoleIdsAndUnwrapsIdAndRoleIds(): void
    {
        $this->t->queueJson(200, self::envelope([
            'id' => 'f0a1b2c3-d4e5-6f7a-8b9c-0d1e2f3a4b5c',
            'key_id' => 'tk_a1',
            'api_key' => 'tk_a1_secret',
            'key_prefix' => 'tk_a1',
            'key_type' => 'standard',
            'name' => 'tf',
            'role_ids' => ['b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d'],
            'message' => 'save it',
        ]));

        $created = $this->client->apiKeys->create([
            'name' => 'tf',
            'role_ids' => ['b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d'],
        ]);

        $this->assertSame(['name' => 'tf', 'role_ids' => ['b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d']], $this->lastBody());
        $this->assertSame('f0a1b2c3-d4e5-6f7a-8b9c-0d1e2f3a4b5c', $created['id']);
        $this->assertSame(['b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d'], $created['role_ids']);
    }

    public function testRolesListSendsSubjectKindAndReturnsEnvelope(): void
    {
        $this->t->queueJson(200, self::paginated([[
            'id' => 'b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
            'name' => 'Key — Infrastructure',
            'description' => null,
            'applies_to' => ['api_key'],
            'is_default' => false,
            'seeded' => true,
        ]], 1, 1, 20));

        $page = $this->client->roles->list(['subject_kind' => 'api_key']);

        $this->assertSame('https://api.example.test/v1/roles?subject_kind=api_key', $this->lastUrl());
        $this->assertTrue($page['data'][0]['seeded']);
        $this->assertSame(['api_key'], $page['data'][0]['applies_to']);
        $this->assertSame(1, $page['meta']['total']);
    }

    public function testPrivilegeEscalationIsAPermissionDeniedExceptionNamingTheGrant(): void
    {
        $this->t->queueJson(403, ['error' => [
            'type' => 'privilege_escalation',
            'message' => 'This API key cannot grant a permission it does not itself hold. '
                . 'Refused grant from role "Key — Infrastructure": '
                . '{"resource_type":"vault","actions":["create"],"effect":"allow"} '
                . '— no rule in your own policy set grants it.',
            'request_id' => 'req-1',
        ]]);

        $this->expectException(\KnoxCall\PermissionDeniedException::class);
        $this->expectExceptionMessageMatches('/resource_type/');
        $this->client->apiKeys->create(['name' => 'x', 'role_ids' => ['b3f1c2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d']]);
    }
}
