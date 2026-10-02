<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

/**
 * AI Gateway control plane (server: src/client-api/ai-gateway.ts).
 *
 * A gateway owns agents; an agent owns phantom tokens. Following this SDK's
 * flat-method convention for resources with sub-collections (see
 * {@see VaultsResource} / {@see DynamicDbResource}), the three collections are
 * exposed as name-prefixed methods on one resource:
 *
 *   - gateways: listGateways / iterateGateways / createGateway / getGateway /
 *               updateGateway / deleteGateway
 *   - agents:   listAgents / iterateAgents / createAgent / getAgent /
 *               updateAgent / deleteAgent
 *   - mcp:      a server also carries the two governance bindings an agent has
 *               (AIGW-151/150): `pii_redact_policy_id` (whose recognizers apply
 *               to tool arguments and results) and the five
 *               `guardrail_webhook_*` fields (the tenant's own scanner, offered
 *               both directions — no streaming carve-out on this plane).
 *               An MCP server is a Live or a Test object (AIGW-152): the row
 *               carries the mode of the API key that created it, every read and
 *               write is confined to that key's own space, and `sandbox` on the
 *               response is READ-ONLY — sending it is ignored.
 *               listMcpServers / iterateMcpServers / createMcpServer /
 *               getMcpServer / updateMcpServer / deleteMcpServer, and the tool
 *               rows listMcpTools / iterateMcpTools / upsertMcpTool /
 *               updateMcpTool / deleteMcpTool
 *   - mcp grants: listMcpGrants / iterateMcpGrants / revokeMcpGrant /
 *                 revokeAllMcpGrants (AIGW-190 delegated OAuth; CREATING a
 *                 connection is not an API operation -- see the section comment)
 *   - tokens:   listTokens / iterateTokens / mintToken / revokeToken
 *   - pii policies:    listPiiPolicies / iteratePiiPolicies / createPiiPolicy /
 *                      getPiiPolicy / updatePiiPolicy / deletePiiPolicy
 *   - pii recognizers: listPiiRecognizers / iteratePiiRecognizers /
 *                      createPiiRecognizer / testPiiRecognizer /
 *                      getPiiRecognizer / updatePiiRecognizer /
 *                      deletePiiRecognizer
 *   - usage()
 *
 * Env/sandbox is NOT a method parameter: a sandbox client mints test-env
 * tokens (kc_test_…) server-side, a live client mints live-env tokens — the
 * env lives in the credential, never in the call.
 */
class AiGatewayResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    // -- Gateways -----------------------------------------------------------------

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listGateways(array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/gateways', $params ?: null);
    }

    /**
     * Yield every gateway, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateGateways(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listGateways($p));
    }

    /**
     * @param array{name: string, slug: string, description?: string, budget_daily_usd?: float, budget_monthly_usd?: float, budget_overage_action?: 'block'|'warn'} $input
     */
    public function createGateway(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/gateways', null, $input));
    }

    public function getGateway(string $gatewayId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId)));
    }

    /**
     * @param array{name?: string, description?: string, budget_daily_usd?: float, budget_monthly_usd?: float, budget_overage_action?: 'block'|'warn'} $patch
     */
    public function updateGateway(string $gatewayId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId), null, $patch));
    }

    /** @return array{id: string, status: string} archives (soft-deletes) the gateway */
    public function deleteGateway(string $gatewayId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId)));
    }

    // -- Agents -------------------------------------------------------------------

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listAgents(string $gatewayId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/agents', $params ?: null);
    }

    /**
     * Yield every agent in a gateway, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAgents(string $gatewayId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listAgents($gatewayId, $p));
    }

    /**
     * `provider` composes the upstream route for you. It is a plain string and
     * the catalog is SERVER-side (fourteen ids at the time of writing, from
     * anthropic and openai through groq, bedrock and openai-compatible); a bad
     * value returns a 400 naming the valid set, so do not mirror the list here.
     * Supplying it composes the upstream route for you, INSTEAD of
     * `primary_route_id`: KnoxCall creates an `ai-gateway-<slug>` route
     * pointing at the provider, injecting `upstream_secret_id` through the
     * envelope store, and sets `default_model` from its pricebook default.
     *
     * `upstream` is required for the four providers whose endpoint is yours
     * rather than the vendor's: azure-openai, ollama, bedrock and
     * openai-compatible. It is not defaulted: a bedrock or openai-compatible
     * agent created without `upstream` is refused with a 400 at create time.
     *
     * Supply `provider` or `primary_route_id`, NEVER BOTH (400). Supplying
     * neither creates an agent with no upstream and no credential template,
     * whose first data-plane call 502s.
     *
     * @param array{name: string, slug: string, description?: string, primary_route_id?: string,
     *              provider?: string, upstream_secret_id?: string, upstream?: string,
     *              default_model?: string, model_allowlist?: list<string>, model_denylist?: list<string>,
     *              budget_daily_usd?: float, budget_monthly_usd?: float, streaming_enabled?: bool,
     *              firewall_policy_id?: string, pii_redact_policy_id?: string,
     *              pii_request_mode?: 'off'|'tokenize', pii_response_mode?: 'redact'|'detokenize'} $input
     *
     * AIGW-100: pii_request_mode decides what happens to the PROMPT before it
     * leaves KnoxCall (default 'tokenize'); pii_response_mode decides what
     * happens to the answer (default 'detokenize'). 'off' on the request side is
     * the only configuration on which the provider receives the real value.
     *
     * AIGW-161: the returned agent carries `agent_url` -- the data-plane base
     * URL, `https://{tenant}.knoxcall.com/v1/ai/{slug}`. Point an AI SDK's
     * base_url there with a capability token as the API key. It is
     * server-computed, not stored, so it MOVES when the slug changes, and is an
     * empty string when the tenant slug cannot be resolved: treat '' as "not
     * available", not as a URL.
     *
     * @return array<string,mixed>
     */
    public function createAgent(string $gatewayId, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/agents', null, $input));
    }

    /** @return array<string,mixed> Includes `agent_url`, as every agent projection does. */
    public function getAgent(string $agentId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/agents/' . rawurlencode($agentId)));
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed> Includes `agent_url`, which MOVES if the patch renames the slug.
     */
    public function updateAgent(string $agentId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/agents/' . rawurlencode($agentId), null, $patch));
    }

    /** @return array{id: string, status: string} archives (soft-deletes) the agent */
    public function deleteAgent(string $agentId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/agents/' . rawurlencode($agentId)));
    }

    // -- MCP servers --------------------------------------------------------------

    /**
     * Paginated. Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listMcpServers(string $gatewayId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/mcp-servers', $params ?: null);
    }

    /**
     * Yield every MCP server in a gateway, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateMcpServers(string $gatewayId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listMcpServers($gatewayId, $p));
    }

    /**
     * Register an upstream MCP server. KnoxCall proxies it at the returned
     * `connect_url` and governs every tool call before it reaches the upstream.
     *
     * `upstream_url` must be a public https:// address — private, loopback,
     * link-local and cloud-metadata destinations are refused, because the
     * request carries your decrypted upstream credential.
     *
     * Every value in `auth['headers']` must reference a KnoxCall secret, e.g.
     * `['Authorization' => 'Bearer {{secret_id:<uuid>}}']`; a literal credential
     * is refused with 422. `allowed_tools` EMPTY means the server advertises
     * nothing. `server_type` 'collection' is not accepted — the data plane does
     * not serve it yet.
     *
     * The result carries BOTH `connect_url` (where an MCP client points) and
     * `resource` (the RFC 8707 value a token for it must be bound to).
     *
     * @param array{name: string, slug: string, upstream_url: string, description?: string,
     *              transport?: string, allowed_tools?: list<string>, pii_inspection?: bool,
     *              auth?: array{headers?: array<string, string>, environment_name?: string}} $input
     */
    public function createMcpServer(string $gatewayId, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/mcp-servers', null, $input));
    }

    public function getMcpServer(string $serverId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId)));
    }

    /**
     * @param array{name?: string, description?: string, upstream_url?: string, transport?: string,
     *              allowed_tools?: list<string>, pii_inspection?: bool,
     *              auth?: array{headers?: array<string, string>, environment_name?: string},
     *              status?: string} $patch  status is 'active'|'paused'; use deleteMcpServer to archive
     */
    public function updateMcpServer(string $serverId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId), null, $patch));
    }

    /** @return array{id: string, status: string} archives (soft-deletes) the MCP server */
    public function deleteMcpServer(string $serverId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId)));
    }

    // -- MCP tools ----------------------------------------------------------------

    /**
     * Paginated tool metadata rows. What a client can actually call is the
     * intersection of the server's allowed_tools, the upstream's real tools and
     * the token's own tool scope.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listMcpTools(string $serverId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/tools', $params ?: null);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateMcpTools(string $serverId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listMcpTools($serverId, $p));
    }

    /**
     * Insert or update a tool row by tool_name.
     *
     * @param array{tool_name: string, description?: string, input_schema?: array<string, mixed>, enabled?: bool} $input
     */
    public function upsertMcpTool(string $serverId, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/tools', null, $input));
    }

    /**
     * Enable, disable or re-describe a tool row.
     *
     * @param array{description?: string, input_schema?: array<string, mixed>, enabled?: bool} $patch
     */
    public function updateMcpTool(string $serverId, string $toolId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/tools/' . rawurlencode($toolId), null, $patch));
    }

    /** @return array{id: string, deleted: bool} */
    public function deleteMcpTool(string $serverId, string $toolId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/tools/' . rawurlencode($toolId)));
    }

    // -- Delegated-OAuth connections (AIGW-190) -------------------------------------
    //
    // A connection holds ONE person's upstream refresh token, envelope-encrypted
    // under the tenant key. Nothing here returns it, redacted or otherwise.
    //
    // There is deliberately no connectMcpServer(): consent has to be given by the
    // person whose credential it is, so the flow starts from a signed-in KnoxCall
    // session in the admin console. An API key is not a person.

    /**
     * One page of the people who have connected their upstream account.
     * Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listMcpGrants(string $serverId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/grants', ['query' => $params]);
    }

    /**
     * Yield every connection on this server, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateMcpGrants(string $serverId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listMcpGrants($serverId, $p));
    }

    /**
     * Revoke ONE person's connection. The stored tokens are destroyed.
     *
     * @return array<string, mixed>
     */
    public function revokeMcpGrant(string $serverId, string $grantId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/grants/' . rawurlencode($grantId)));
    }

    /**
     * Revoke EVERY connection on this server (offboarding in one call).
     *
     * @return array<string, mixed>
     */
    public function revokeAllMcpGrants(string $serverId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/mcp-servers/' . rawurlencode($serverId) . '/grants'));
    }

    // -- Tokens -------------------------------------------------------------------

    /**
     * Paginated; NEVER returns plaintext (only prefix + metadata).
     * Params: page (default 1), per_page (default 20, max 100).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listTokens(string $agentId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/agents/' . rawurlencode($agentId) . '/tokens', $params ?: null);
    }

    /**
     * Yield every token for an agent, walking pages transparently.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateTokens(string $agentId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listTokens($agentId, $p));
    }

    /**
     * Mint a phantom token. The returned `token` is plaintext shown exactly
     * ONCE — store it now. A sandbox client mints a kc_test_… token; a live
     * client mints kc_live_… — the env is server-side, never a parameter.
     *
     * @param array{name?: string, kind?: string, dpop_required?: bool, dpop_jkt?: string, expires_in_seconds?: int} $input  expires_in_seconds: Defaults to 30 days when omitted; clamped to [60s, 90d]. A non-expiring token cannot be minted.
     *              kind: agent|read|tool|oneshot (default agent)
     * @return array{id: string, name?: string, kind: string, prefix: string, token: string, dpop_required: bool, expires_at: ?string}
     */
    public function mintToken(string $agentId, array $input = []): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/agents/' . rawurlencode($agentId) . '/tokens', null, $input));
    }

    /** @return array{id: string, revoked: bool} */
    public function revokeToken(string $agentId, string $tokenId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/agents/' . rawurlencode($agentId) . '/tokens/' . rawurlencode($tokenId)));
    }

    // -- Firewall policies ---------------------------------------------------
    //
    // Prompt-firewall policies are TENANT-scoped and shared across gateways;
    // attach one to an agent with firewall_policy_id. An agent with no policy
    // still runs the built-in prompt-injection patterns but can never exceed
    // "warn" -- attach a policy with action "block" to have matching requests
    // refused with HTTP 400 firewall_block on the data plane.

    /**
     * List prompt-firewall policies (paginated: page, per_page).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed> The {data, meta} envelope.
     */
    public function listFirewallPolicies(array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/firewall-policies', $params ?: null);
    }

    /**
     * Lazily stream every firewall policy, fetching pages on demand.
     *
     * @param array<string,mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateFirewallPolicies(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listFirewallPolicies($p));
    }

    /**
     * Create a firewall policy. Re-using an existing name creates version N+1.
     *
     * Every regex rule is compiled server-side with the same linear-time engine
     * the data plane runs, so lookahead, lookbehind and backreferences are a 400
     * here rather than a rule that silently matches nothing at scan time.
     *
     * @param array<string,mixed> $input name, heuristics?, canary_enabled?, action?
     * @return array<string,mixed>
     */
    public function createFirewallPolicy(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/firewall-policies', null, $input));
    }

    /** @return array<string,mixed> */
    public function getFirewallPolicy(string $policyId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/firewall-policies/' . rawurlencode($policyId)));
    }

    /**
     * Update in place -- the version is NOT bumped. Rules are re-validated.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function updateFirewallPolicy(string $policyId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/firewall-policies/' . rawurlencode($policyId), null, $patch));
    }

    /**
     * Delete a policy. Refused with 409 policy_in_use while any agent or MCP
     * server is still attached.
     *
     * @return array<string,mixed> {id, deleted:true}
     */
    public function deleteFirewallPolicy(string $policyId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/firewall-policies/' . rawurlencode($policyId)));
    }

    /**
     * Dry-run rules against sample text; saves nothing. Rules are compiled
     * first, so this refuses exactly what create/update refuse.
     *
     * @param list<array<string,mixed>>|null $heuristics
     * @return array<string,mixed> {matched, matches, skipped}
     */
    public function testFirewallRules(string $text, ?array $heuristics = null): array
    {
        $body = ['text' => $text];
        if ($heuristics !== null) {
            $body['heuristics'] = $heuristics;
        }
        return self::data($this->client->request('POST', '/v1/ai-gateway/firewall-policies/test', null, $body));
    }

    /**
     * Every token under a gateway, INCLUDING gateway-level tokens with no agent
     * (the shape POST /v1/oauth/token mints for MCP). {@see listTokens} filters
     * on the agent and cannot see them. Plaintext is never returned.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}}
     */
    public function listGatewayTokens(string $gatewayId, array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/tokens', $params ?: null);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateGatewayTokens(string $gatewayId, array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listGatewayTokens($gatewayId, $p));
    }

    /**
     * Revoke any token under a gateway, including a gateway-level one. Use this
     * rather than {@see revokeToken} for a token minted by POST /v1/oauth/token:
     * that token has no agent, so the per-agent revoke can never match it.
     *
     * @return array{id: string, revoked: bool}
     */
    public function revokeGatewayToken(string $gatewayId, string $tokenId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/gateways/' . rawurlencode($gatewayId) . '/tokens/' . rawurlencode($tokenId)));
    }

    // -- PII policies --------------------------------------------------------
    //
    // Tenant-scoped like firewall policies: one policy attaches to any number
    // of agents through pii_redact_policy_id, so these sit at the mount root
    // rather than under a gateway id. Until AIGW-160 they lived only on the
    // admin (session-cookie) plane, which is why createAgent already accepted a
    // pii_redact_policy_id that no /v1 call could produce.
    //
    // ONE THING GOVERNS EVERY METHOD BELOW: an EMPTY recognizer_ids does NOT
    // mean "no recognizers" -- it means "every enabled recognizer this tenant
    // owns". That is why an unowned id is refused at write time instead of
    // being dropped at scan time, and why deleting a listed recognizer WIDENS a
    // policy rather than shrinking it.

    /**
     * List PII redaction policies (paginated: page, per_page). Tenant-scoped,
     * not per gateway.
     *
     * @param array<string,mixed> $params
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}} The {data, meta} envelope.
     */
    public function listPiiPolicies(array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/pii-policies', $params ?: null);
    }

    /**
     * Lazily stream every PII policy, fetching pages on demand.
     *
     * @param array<string,mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iteratePiiPolicies(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listPiiPolicies($p));
    }

    /**
     * Create a PII policy.
     *
     * Every id in `recognizer_ids` must be a recognizer THIS tenant owns: a
     * foreign or unknown id is a 400 `recognizer_not_found` at write time,
     * never a stored value that resolves to nothing when the data plane scans.
     * (The loader intersects on the recognizer row's own tenant, so a foreign
     * id is not a cross-tenant read -- it is a silent downgrade: the policy
     * lists three detectors and runs zero, which is indistinguishable from
     * "redaction is on" right up until the PII reaches the provider.)
     *
     * Omitting `recognizer_ids`, or passing `[]`, selects EVERY enabled
     * recognizer this tenant owns -- not none.
     *
     * @param array{name: string, recognizer_ids?: list<string>, default_action?: string, description?: string} $input
     *              name: 2-64 chars (letters, digits, space, underscore, hyphen), unique per tenant.
     *              default_action: redact|tokenize|whitelist|warn (default redact).
     * @return array{id: string, tenant_id: string, name: string, version: int, recognizer_ids: list<string>, default_action: string, description: ?string, created_at: string}
     */
    public function createPiiPolicy(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/pii-policies', null, $input));
    }

    /**
     * @return array{id: string, tenant_id: string, name: string, version: int, recognizer_ids: list<string>, default_action: string, description: ?string, created_at: string}
     */
    public function getPiiPolicy(string $policyId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/pii-policies/' . rawurlencode($policyId)));
    }

    /**
     * Update in place -- the version is NOT bumped.
     *
     * `recognizer_ids` REPLACES the stored list, it does not merge into it, and
     * every id is re-checked against this tenant (400 `recognizer_not_found`).
     * Sending `[]` is not "disable the policy": it resets it to every enabled
     * recognizer this tenant owns.
     *
     * @param array{recognizer_ids?: list<string>, default_action?: string, description?: string} $patch
     * @return array{id: string, tenant_id: string, name: string, version: int, recognizer_ids: list<string>, default_action: string, description: ?string, created_at: string}
     */
    public function updatePiiPolicy(string $policyId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/pii-policies/' . rawurlencode($policyId), null, $patch));
    }

    /**
     * Delete a PII policy. Refused with 409 `policy_in_use` while ANY agent
     * still references it -- archived agents included, because the row keeps
     * the pointer.
     *
     * The foreign key is ON DELETE SET NULL, so an unchecked delete would not
     * fail: it would detach every bound agent and turn redaction OFF for each
     * of them, with no error. Detach them first.
     *
     * @return array{id: string, deleted: bool} {id, deleted:true}
     */
    public function deletePiiPolicy(string $policyId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/pii-policies/' . rawurlencode($policyId)));
    }

    // -- PII recognizers -----------------------------------------------------
    //
    // A recognizer is one tenant-scoped detector; a policy bundles them. regex
    // and aho_corasick run in-process, the three presidio_* kinds are handed to
    // a Presidio sidecar.

    /**
     * List custom PII recognizers (paginated: page, per_page).
     *
     * @param array<string,mixed> $params
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, total_pages: int, request_id: string}} The {data, meta} envelope.
     */
    public function listPiiRecognizers(array $params = []): array
    {
        return $this->client->request('GET', '/v1/ai-gateway/pii-recognizers', $params ?: null);
    }

    /**
     * Lazily stream every PII recognizer, fetching pages on demand.
     *
     * @param array<string,mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iteratePiiRecognizers(array $params = []): \Generator
    {
        return self::iteratePages($params, fn (array $p): array => $this->listPiiRecognizers($p));
    }

    /**
     * Create a custom recognizer. The tenant comes from the credential, never
     * from the body.
     *
     * A `regex` pattern is compiled server-side with the linear-time engine the
     * data plane runs, so lookahead, lookbehind and backreferences are a 400
     * here rather than a recognizer that is skipped at scan time (fail-open).
     * Dry-run first with {@see testPiiRecognizer}.
     *
     * @param array{name: string, kind: string, pattern: string, context_words?: list<string>, confidence?: float, action?: string, format?: ?string, enabled?: bool} $input
     *              kind: regex|aho_corasick|presidio_pattern|presidio_ner|presidio_custom.
     *              action: redact|tokenize|whitelist|warn. A `whitelist` pattern that
     *              matches arbitrary text is a kill switch for the built-in tier, so the
     *              server refuses one.
     *              confidence: 0-1, default 0.85. format: token format for `tokenize`.
     *              enabled: false mutes the recognizer without losing its definition.
     * @return array{id: string, tenant_id: string, name: string, kind: string, pattern: string, context_words: list<string>, confidence: float, action: string, format: ?string, enabled: bool, created_at: string}
     */
    public function createPiiRecognizer(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/ai-gateway/pii-recognizers', null, $input));
    }

    /**
     * Dry-run a candidate pattern against sample text; saves nothing.
     *
     * It compiles with the SAME engine the data plane runs and builds the SAME
     * detector, so a pattern that passes here is one that will actually run.
     * Do NOT preview with a local `preg_match`: PCRE accepts lookahead,
     * lookbehind and backreferences the server refuses, so a local preview
     * shows matches for a recognizer that can never run and then 400s on save.
     *
     * Discrete arguments rather than one array, matching
     * {@see testFirewallRules}: `pattern` and `text` are both required, and an
     * array would hide a typo in either as a silent 400.
     *
     * @param list<string>|null $contextWords Words that must appear nearby for a match to count.
     * @param string|null $kind regex|aho_corasick|presidio_pattern|presidio_ner|presidio_custom.
     * @param string|null $action redact|tokenize|whitelist|warn.
     * @return array{matched: bool, matches: list<array{span: array{0: int, 1: int}, matched: string, replacement: string, entity_type: string}>}
     */
    public function testPiiRecognizer(
        string $pattern,
        string $text,
        ?string $kind = null,
        ?string $action = null,
        ?array $contextWords = null,
        ?string $name = null
    ): array {
        $body = ['pattern' => $pattern, 'text' => $text];
        if ($kind !== null) {
            $body['kind'] = $kind;
        }
        if ($action !== null) {
            $body['action'] = $action;
        }
        if ($contextWords !== null) {
            $body['context_words'] = $contextWords;
        }
        if ($name !== null) {
            $body['name'] = $name;
        }
        return self::data($this->client->request('POST', '/v1/ai-gateway/pii-recognizers/test', null, $body));
    }

    /**
     * @return array{id: string, tenant_id: string, name: string, kind: string, pattern: string, context_words: list<string>, confidence: float, action: string, format: ?string, enabled: bool, created_at: string}
     */
    public function getPiiRecognizer(string $recognizerId): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/pii-recognizers/' . rawurlencode($recognizerId)));
    }

    /**
     * Update a recognizer. The server validates the MERGED state, not the
     * patch: `{action: "whitelist"}` on its own is still checked against the
     * STORED pattern, so a `.+` row cannot be promoted into an allow-list that
     * suppresses the whole built-in tier by patching one field at a time.
     *
     * @param array{name?: string, kind?: string, pattern?: string, context_words?: list<string>, confidence?: float, action?: string, format?: ?string, enabled?: bool} $patch
     * @return array{id: string, tenant_id: string, name: string, kind: string, pattern: string, context_words: list<string>, confidence: float, action: string, format: ?string, enabled: bool, created_at: string}
     */
    public function updatePiiRecognizer(string $recognizerId, array $patch): array
    {
        return self::data($this->client->request('PATCH', '/v1/ai-gateway/pii-recognizers/' . rawurlencode($recognizerId), null, $patch));
    }

    /**
     * Delete a recognizer. Refused with 409 `recognizer_in_use` while any PII
     * policy still lists it.
     *
     * `recognizer_ids` is a bare uuid array with no foreign key, and an EMPTY
     * one means "every enabled recognizer" -- so silently dropping the id would
     * WIDEN each policy that named it rather than shrink it. Remove it from
     * every policy first.
     *
     * @return array{id: string, deleted: bool} {id, deleted:true}
     */
    public function deletePiiRecognizer(string $recognizerId): array
    {
        return self::data($this->client->request('DELETE', '/v1/ai-gateway/pii-recognizers/' . rawurlencode($recognizerId)));
    }

    // -- Usage --------------------------------------------------------------------

    /**
     * Cost + tokens by model over a window. Params: period (7d|30d|90d,
     * default 30d), agent_id (optional).
     *
     * @param array{period?: string, agent_id?: string} $params
     * @return array{period_days: int, by_model: list<array{provider: string, model: string, requests: int, input_tokens: int, output_tokens: int, cost_usd: float, unpriced_requests: int}>, totals: array<string, mixed>}
     */
    public function usage(array $params = []): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/usage', $params ?: null));
    }

    /**
     * FinOps export: aggregated spend grouped by user | team | agent | model |
     * provider | `tag:<key>`, over a window. The SDK ALWAYS requests JSON
     * (format=json is appended to the query). Params: group_by (required;
     * one of user|team|agent|model|provider or tag:<key>), period (7d|30d|90d,
     * optional), agent_id (optional).
     *
     * @param array{group_by: string, period?: string, agent_id?: string} $params
     * @return array{group_by: string, period_days: int, rows: list<array{group: ?string, requests: int, input_tokens: int, output_tokens: int, cost_usd: float, unpriced_requests: int}>}
     */
    public function exportUsage(array $params): array
    {
        return self::data($this->client->request('GET', '/v1/ai-gateway/usage/export', array_merge($params, ['format' => 'json'])));
    }
}
