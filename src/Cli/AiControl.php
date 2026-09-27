<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\StoredCredentials;
use KnoxCall\KnoxCall;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall ai` — the AI-gateway CONTROL plane from a terminal (AIGW-162).
 *
 * Mirrors the Node reference (sdk/knoxcall-node/src/cli/ai-control.ts): same
 * flags, same messages, same stdout/stderr split, same exit codes.
 *
 * `ai exchange` ({@see Ai}) is the data-plane door: it needs no login, because
 * the CI workload's OIDC token is the credential. Everything here is the
 * opposite — it acts as the signed-in tenant, through the same
 * ~/.knoxcall/credentials.json profile `login` writes and `whoami` reads.
 *
 * WHY THIS EXISTS. Until now the five SDK CLIs shipped exactly one `ai`
 * sub-command, `exchange`. A capable `knoxcall ai gateways|agents|mint|usage`
 * lived in a standalone `cli/` package that was never published, never tested,
 * never in CI and not in the workspaces — and it could not create a secret, a
 * gateway or an agent, so it could not get you to a first call either. So there
 * was no CLI golden path at all: the only way from "I have an API key" to "my
 * app is calling an LLM through KnoxCall" was the browser or hand-written HTTP.
 *
 * The golden path these commands exist to make true, from a tenant with
 * nothing in it:
 *
 *     export ANTHROPIC_API_KEY=sk-ant-...
 *     knoxcall ai create-agent --name copilot --slug copilot \
 *         --provider anthropic --secret-from-env ANTHROPIC_API_KEY
 *     knoxcall ai mint --agent <id>
 *     curl "$AGENT_URL/v1/messages" -H "Authorization: Bearer $TOKEN" ...
 *
 * Two commands, then a real streamed call. `create-agent` prints the agent id,
 * the `agent_url` and the exact next command, so the path is discoverable
 * without re-reading the docs.
 *
 * THREE RULES, each one a bug this shape invites:
 *
 *   1. A PROVIDER KEY IS NEVER AN ARGV VALUE. `--secret-from-env NAME` names
 *      the environment variable to read; there is deliberately no
 *      `--secret-value`. An argv value lands in shell history, in `ps` output
 *      and in the CI log line that echoes the command. Same rule `ai exchange`
 *      applies to KNOXCALL_SUBJECT_TOKEN and `init` to KNOXCALL_WRAP_SECRET.
 *
 *   2. NO POSITIONAL ARGUMENTS. Four of the five SDK CLIs hand-roll their
 *      parser and reject positionals outright; only python gets them free from
 *      argparse. Ids are flags (`--gateway`, `--agent`) so the surface is the
 *      same in all five rather than "the same except in PHP".
 *
 *   3. AN AGENT WITHOUT AN UPSTREAM IS REFUSED HERE, not at its first call.
 *      The API accepts createAgent with no provider/upstream_secret_id and
 *      stores an agent whose first data-plane request 502s (AIGW-161). A
 *      command whose entire purpose is "get me to a working call" must not be
 *      able to produce that, so `--provider` and one of `--secret` /
 *      `--secret-from-env` are required together.
 *
 * No HTTP lives here: every call goes through the typed SDK resources
 * ($client->aiGateway, $client->secrets).
 */
final class AiControl
{
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /**
     * @param resource $out
     * @param resource|null $err
     */
    public function __construct(
        private readonly ?TransportInterface $transport,
        $out,
        $err = null,
    ) {
        $this->out = $out;
        $this->err = $err ?? (\defined('STDERR') ? \STDERR : $out);
    }

    public function run(ParsedArgs $args): int
    {
        return match ($args->aiCommand) {
            'gateways' => $this->gateways($args),
            'agents' => $this->agents($args),
            'create-agent' => $this->createAgent($args),
            'mint' => $this->mint($args),
            'usage' => $this->usage($args),
            default => throw new \LogicException('unreachable: ArgParser vets the ai sub-command'),
        };
    }

    // -- knoxcall ai gateways -------------------------------------------------

    private function gateways(ParsedArgs $args): int
    {
        $client = $this->clientFor($args);
        $rows = self::rows($client->aiGateway->listGateways(['per_page' => 100]));
        if ($rows === []) {
            fwrite($this->err, "No AI gateways. `knoxcall ai create-agent` will create one for you.\n");
            return 0;
        }
        foreach ($rows as $g) {
            fwrite($this->out, self::text($g['id'] ?? null) . '  '
                . self::text($g['slug'] ?? null) . '  '
                . self::text($g['name'] ?? null) . "\n");
        }
        return 0;
    }

    // -- knoxcall ai agents --gateway ID --------------------------------------

    private function agents(ParsedArgs $args): int
    {
        $gatewayId = self::required($args->gateway, '--gateway');
        $client = $this->clientFor($args);
        $rows = self::rows($client->aiGateway->listAgents($gatewayId, ['per_page' => 100]));
        if ($rows === []) {
            fwrite($this->err, "No agents in that gateway.\n");
            return 0;
        }
        // agent_url is on every projection since AIGW-161, so a list is enough
        // to point an SDK at an existing agent — no follow-up GET.
        foreach ($rows as $a) {
            fwrite($this->out, self::text($a['id'] ?? null) . '  '
                . self::text($a['slug'] ?? null) . '  '
                . self::text($a['agent_url'] ?? null) . "\n");
        }
        return 0;
    }

    // -- knoxcall ai create-agent ---------------------------------------------

    private function createAgent(ParsedArgs $args): int
    {
        $slug = self::required($args->slug, '--slug');
        // Rule 3: refuse here rather than let the API store an agent with no
        // upstream whose first data-plane call 502s.
        $provider = self::required($args->provider, '--provider');
        if (self::presence($args->secret) === null && self::presence($args->secretFromEnv) === null) {
            throw new CliError(
                'one of --secret or --secret-from-env is required: an agent created without an '
                . 'upstream credential is accepted by the API and 502s on its first call.'
            );
        }

        $client = $this->clientFor($args);
        $gatewayId = $this->resolveGateway($client, $args->gateway);
        $secretId = $this->resolveSecret($client, $args);

        $body = [
            'name' => self::presence($args->name) ?? $slug,
            'slug' => $slug,
            'provider' => $provider,
            'upstream_secret_id' => $secretId,
        ];
        if (self::presence($args->upstream) !== null) {
            $body['upstream'] = $args->upstream;
        }
        if (self::presence($args->model) !== null) {
            $body['default_model'] = $args->model;
        }
        $agent = $client->aiGateway->createAgent($gatewayId, $body);

        // stdout: the agent id, so `$(...)` captures exactly that. Everything a
        // human needs next goes to stderr, including the command that follows.
        $agentId = self::text($agent['id'] ?? null);
        fwrite($this->out, $agentId . "\n");
        fwrite($this->err, "\n  agent:     " . self::text($agent['slug'] ?? null) . " ({$agentId})\n");
        fwrite($this->err, "  gateway:   {$gatewayId}\n");
        fwrite($this->err, "  provider:  {$provider}\n");
        // AIGW-161: agent_url is server-computed and is '' when the tenant slug
        // cannot be resolved. Treat '' as "not available", never as a URL.
        $agentUrl = self::presence(is_string($agent['agent_url'] ?? null) ? $agent['agent_url'] : null);
        if ($agentUrl !== null) {
            fwrite($this->err, "  base_url:  {$agentUrl}\n");
        }
        fwrite($this->err, "\n  Next:  knoxcall ai mint --agent {$agentId}\n");
        return 0;
    }

    /**
     * Resolve the gateway to create under.
     *
     * `--gateway` takes an id OR a slug. With no `--gateway`: use the tenant's
     * only gateway, or create one when they have none — that is what makes the
     * command work on a fresh tenant, which is the whole point. With SEVERAL and
     * no flag it refuses and lists them rather than picking: "whichever sorts
     * first" is how the quickstart wizard silently landed a second agent in the
     * wrong gateway.
     */
    private function resolveGateway(KnoxCall $client, ?string $wanted): string
    {
        $rows = self::rows($client->aiGateway->listGateways(['per_page' => 100]));
        $wanted = self::presence($wanted);
        if ($wanted !== null) {
            foreach ($rows as $g) {
                if (($g['id'] ?? null) === $wanted || ($g['slug'] ?? null) === $wanted) {
                    return self::text($g['id'] ?? null);
                }
            }
            throw new CliError("no gateway '{$wanted}' — this tenant has: " . self::gatewayList($rows));
        }
        if (count($rows) === 1) {
            return self::text($rows[0]['id'] ?? null);
        }
        if ($rows === []) {
            $created = $client->aiGateway->createGateway(['name' => 'Default', 'slug' => 'default']);
            fwrite($this->err, 'created gateway ' . self::text($created['slug'] ?? null)
                . ' (' . self::text($created['id'] ?? null) . ")\n");
            return self::text($created['id'] ?? null);
        }
        throw new CliError(
            '--gateway is required: this tenant has ' . count($rows) . ' gateways ('
            . self::gatewayList($rows) . '). Picking one for you would put the agent somewhere '
            . 'you did not choose.'
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private static function gatewayList(array $rows): string
    {
        $listed = [];
        foreach ($rows as $g) {
            $listed[] = self::text($g['slug'] ?? null) . ' (' . self::text($g['id'] ?? null) . ')';
        }
        return $listed === [] ? 'none' : implode(', ', $listed);
    }

    /**
     * Resolve the upstream secret, escrowing one from the environment if asked.
     *
     * The key is read from getenv(NAME), never from a flag — see rule 1.
     * Re-running with the same `--secret-from-env` reuses the existing secret by
     * name rather than creating a second copy of the same credential.
     */
    private function resolveSecret(KnoxCall $client, ParsedArgs $args): string
    {
        $secret = self::presence($args->secret);
        if ($secret !== null) {
            return $secret;
        }
        $envName = self::required($args->secretFromEnv, '--secret or --secret-from-env');
        $value = trim((string) (getenv($envName) ?: ''));
        if ($value === '') {
            throw new CliError(
                "{$envName} is not set — put your provider key there. There is deliberately no "
                . '--secret-value flag: an argv value lands in shell history, ps output and the CI log.'
            );
        }

        $name = 'ai-gateway-' . (self::presence($args->slug) ?? 'agent') . '-key';
        foreach (self::rows($client->secrets->list(['per_page' => 100])) as $s) {
            if (($s['name'] ?? null) === $name) {
                $hitId = self::text($s['id'] ?? null);
                fwrite($this->err, "reusing secret '{$name}' ({$hitId})\n");
                return $hitId;
            }
        }
        $created = $client->secrets->create(['name' => $name, 'value' => $value]);
        $createdId = self::text($created['id'] ?? null);
        fwrite($this->err, "escrowed secret '{$name}' ({$createdId}) — the key is now in KnoxCall custody\n");
        return $createdId;
    }

    // -- knoxcall ai mint --agent ID ------------------------------------------

    private function mint(ParsedArgs $args): int
    {
        $agentId = self::required($args->agent, '--agent');
        $client = $this->clientFor($args);
        $body = [];
        if (self::presence($args->kind) !== null) {
            $body['kind'] = $args->kind;
        }
        if (self::presence($args->name) !== null) {
            $body['name'] = $args->name;
        }
        $minted = $client->aiGateway->mintToken($agentId, $body);

        // The plaintext is returned ONCE. stdout carries only the token so
        // `> token.txt` captures the token and nothing else; the metadata and
        // the warning go to stderr.
        fwrite($this->out, self::text($minted['token'] ?? null) . "\n");
        fwrite($this->err, "\n  id:       " . self::text($minted['id'] ?? null) . "\n");
        fwrite($this->err, '  kind:     ' . self::text($minted['kind'] ?? null) . "\n");
        fwrite($this->err, '  prefix:   ' . self::text($minted['prefix'] ?? null) . "\n");
        fwrite($this->err, '  dpop:     ' . self::text($minted['dpop_required'] ?? null) . "\n");
        $expires = self::presence(is_string($minted['expires_at'] ?? null) ? $minted['expires_at'] : null);
        fwrite($this->err, '  expires:  ' . ($expires ?? 'never') . "\n");
        fwrite($this->err, "\n  Save this token now — it will not be shown again.\n");
        return 0;
    }

    // -- knoxcall ai usage ----------------------------------------------------

    private function usage(ParsedArgs $args): int
    {
        $client = $this->clientFor($args);
        $agentId = self::presence($args->agent);
        $params = ['period' => self::presence($args->period) ?? '30d'];
        if ($agentId !== null) {
            $params['agent_id'] = $agentId;
        }
        $rollup = $client->aiGateway->usage($params);
        $totals = is_array($rollup['totals'] ?? null) ? $rollup['totals'] : [];

        $scope = $agentId !== null ? " (agent {$agentId})" : '';
        fwrite($this->out, 'Usage — last ' . self::text($rollup['period_days'] ?? null) . " days{$scope}\n");
        fwrite($this->out, '  requests:      ' . self::text($totals['requests'] ?? null) . "\n");
        fwrite($this->out, '  input tokens:  ' . self::text($totals['input_tokens'] ?? null) . "\n");
        fwrite($this->out, '  output tokens: ' . self::text($totals['output_tokens'] ?? null) . "\n");
        fwrite($this->out, '  cost (USD):    ' . self::money($totals['cost_usd'] ?? null) . "\n");
        fwrite($this->out, '  unpriced:      ' . self::text($totals['unpriced_requests'] ?? null) . "\n");

        $byModel = is_array($rollup['by_model'] ?? null) ? array_values($rollup['by_model']) : [];
        if ($byModel === []) {
            fwrite($this->out, "\nNo usage in this period.\n");
            return 0;
        }
        fwrite($this->out, "\nBy model:\n");
        foreach ($byModel as $m) {
            if (!is_array($m)) {
                continue;
            }
            fwrite($this->out, '  ' . self::text($m['provider'] ?? null) . '/' . self::text($m['model'] ?? null)
                . '  ' . self::text($m['requests'] ?? null) . ' req'
                . '  in ' . self::text($m['input_tokens'] ?? null)
                . '  out ' . self::text($m['output_tokens'] ?? null)
                . '  $' . self::money($m['cost_usd'] ?? null) . "\n");
        }
        return 0;
    }

    // -- shared ---------------------------------------------------------------

    /**
     * A client acting as the signed-in tenant, or a refusal telling them to log
     * in. Obtained exactly the way `whoami` and `init` obtain theirs — there is
     * no second way into the credentials file.
     */
    private function clientFor(ParsedArgs $args): KnoxCall
    {
        $path = Common::requireCredentialsPath();
        $profile = CredentialsFile::resolveProfile($args->profile);
        if (CredentialsFile::readProfile($path, $profile) === null) {
            throw new CliError("not logged in (profile '{$profile}') — run `knoxcall login`");
        }

        $opts = ['credentials' => new StoredCredentials($path, $profile)];
        if (self::presence($args->baseUrl) !== null) {
            $opts['base_url'] = $args->baseUrl;
        }
        if ($args->sandbox) {
            $opts['sandbox'] = true;
        }
        if ($this->transport !== null) {
            $opts['transport'] = $this->transport;
        }
        return new KnoxCall($opts);
    }

    /** NULL for "flag absent"; an empty string is absent too, unlike --resource. */
    private static function presence(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }

    private static function required(?string $value, string $flag): string
    {
        $found = self::presence($value);
        if ($found === null) {
            throw new CliError("{$flag} is required");
        }
        return $found;
    }

    /**
     * The `data` array of a {data, meta} page, as a list.
     *
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private static function rows(array $page): array
    {
        $data = $page['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Render a JSON scalar the way the other four CLIs do: booleans as
     * `true`/`false` (PHP would otherwise print `1` and the empty string), null
     * as empty, everything else as its string form.
     */
    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        return (string) $value;
    }

    /** Costs are compared across SDKs, so they are always four decimal places. */
    private static function money(mixed $value): string
    {
        return sprintf('%.4f', is_numeric($value) ? (float) $value : 0.0);
    }
}
