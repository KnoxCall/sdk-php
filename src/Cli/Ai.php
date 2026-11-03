<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\KnoxCall;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall ai exchange` — RFC 8693 workload federation from a terminal.
 *
 * Mirrors knoxcall-python's knoxcall/cli/ai.py (the PARITY §13 reference):
 * same flags, same messages, same exit codes.
 *
 * The one KnoxCall command that needs no `knoxcall login` and no KnoxCall
 * credential at all: the CI workload's own OIDC id_token IS the credential, and
 * the server verifies it against the issuer's published JWKS.
 *
 *     export KC_TOKEN="$(knoxcall ai exchange --tenant acme)"
 *
 * Two rules this command exists to enforce, because both are easy to get wrong
 * in a CI script and neither fails in a way that names itself:
 *
 *   1. The subject token is read from the environment, NEVER a flag. An argv
 *      value lands in shell history, in `ps` output, and in the CI log line
 *      that echoes the command. Same rule `knoxcall init` applies to
 *      KNOXCALL_WRAP_SECRET.
 *   2. The host is the tenant data plane, and there is no default. On
 *      api.knoxcall.com this endpoint answers 401, which reads as "my CI token
 *      was rejected" and sends people hunting through their issuer's JWKS.
 *
 * Only the token goes to stdout, so `$(...)` captures exactly the token.
 */
final class Ai
{
    /** The subject token is read from here, never from argv. See rule 1 above. */
    public const SUBJECT_TOKEN_ENV = 'KNOXCALL_SUBJECT_TOKEN';

    /** @param resource $out */
    public function __construct(
        private readonly ?TransportInterface $transport,
        private $out,
        /** @var resource|null */
        private $err = null,
    ) {
        $this->err = $err ?? (\defined('STDERR') ? \STDERR : $out);
    }

    public function run(ParsedArgs $args): int
    {
        $subjectToken = trim((string) (getenv(self::SUBJECT_TOKEN_ENV) ?: ''));
        if ($subjectToken === '') {
            throw new CliError(
                self::SUBJECT_TOKEN_ENV . " is not set — put your CI provider's OIDC id_token there "
                . '(a flag would land in shell history, ps output and the CI log). GitHub Actions: '
                . 'request one with `id-token: write` and the ACTIONS_ID_TOKEN_REQUEST_URL endpoint, '
                . 'audience "knoxcall:gateway".'
            );
        }

        if (($args->tenant ?? '') === '' && ($args->baseUrl ?? '') === '') {
            throw new CliError(
                'one of --tenant or --base-url is required: POST /v1/oauth/token is served only on '
                . 'the tenant data-plane host (https://{tenant}.knoxcall.com). Pointing it at '
                . 'api.knoxcall.com answers 401, which reads like a rejected subject_token but means '
                . 'the endpoint is not there.'
            );
        }

        $input = ['subject_token' => $subjectToken];
        // array_key_exists on the PARSED args, not isset: `--resource ""` was
        // ASKED for, and the server refuses it. Treating empty as absent would
        // hand back an UNCONFINED agent token to a caller who wanted a confined
        // one.
        if ($args->resource !== null) {
            $input['resource'] = $args->resource;
        }
        if (($args->audience ?? '') !== '') {
            $input['audience'] = $args->audience;
        }

        $opts = ['tenant' => $args->tenant, 'sandbox' => $args->sandbox, 'base_url' => $args->baseUrl];
        if ($this->transport !== null) {
            $opts['transport'] = $this->transport;
        }

        $res = KnoxCall::exchangeToken($input, $opts);
        $token = $res['access_token'] ?? '';
        if (!is_string($token) || $token === '') {
            throw new CliError('the exchange returned no access_token');
        }

        // stdout: the token, nothing else. stderr: everything a human wants.
        fwrite($this->out, $token . "\n");
        $kind = $args->resource !== null ? 'tool (MCP, resource-bound)' : 'agent';
        $ttl = isset($res['expires_in']) && is_int($res['expires_in'])
            ? ", valid {$res['expires_in']}s"
            : '';
        fwrite($this->err, "exchanged for a {$kind} token{$ttl}\n");
        return 0;
    }
}
