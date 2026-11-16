<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\StoredCredentials;
use KnoxCall\KnoxCall;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall init` — get started wrapping a provider SDK through KnoxCall.
 *
 * SAFE BY DESIGN — this does NOT provision a tenant. It works against the
 * tenant you are already signed in to (`knoxcall login`). Two modes:
 *
 *   knoxcall init
 *       Scaffold mode: confirm who you're signed in as and print a two-step
 *       wrap quickstart. No writes.
 *
 *   knoxcall init --provider stripe --secret-name wrap-stripe --host api.stripe.com
 *       One-shot escrow: move a provider key into KnoxCall custody and print
 *       the gateway base_url to point your SDK at. The KEY is read from the
 *       KNOXCALL_WRAP_SECRET env var (never a flag) so it stays out of your
 *       shell history/argv. Escrow is idempotent-ish server-side (409 on a
 *       duplicate name).
 *
 * Mirrors knoxcall-node src/cli/init.ts (PARITY §13). Tenant provisioning and
 * a fully headless one-shot flow are a deliberate follow-up — a CLI that mints
 * tenants is a bigger, riskier surface.
 */
final class Init
{
    /** @var resource */
    private $out;

    public function __construct(private readonly ?TransportInterface $transport, $out)
    {
        $this->out = $out;
    }

    public function run(ParsedArgs $args): int
    {
        // Auth: reuse the stored login. Never provision.
        $path = Common::requireCredentialsPath();
        $profile = CredentialsFile::resolveProfile($args->profile);
        if (CredentialsFile::readProfile($path, $profile) === null) {
            throw new CliError("not logged in (profile '{$profile}') — run `knoxcall login` first");
        }

        $opts = ['credentials' => new StoredCredentials($path, $profile)];
        if ($this->transport !== null) {
            $opts['transport'] = $this->transport;
        }
        if ($args->baseUrl !== null && $args->baseUrl !== '') {
            $opts['base_url'] = $args->baseUrl;
        }
        if ($args->sandbox) {
            $opts['sandbox'] = true;
        }
        $client = new KnoxCall($opts);

        $account = $client->account->get();
        $string = static fn (mixed $value): string => is_string($value) ? $value : '';
        $tenant = $string($account['name'] ?? null);
        if ($tenant === '') {
            $tenant = $string($account['company_name'] ?? null);
        }
        if ($tenant === '') {
            $tenant = $string($account['slug'] ?? null);
        }
        if ($tenant === '') {
            $tenant = '(unknown)';
        }
        $this->println("Signed in as {$tenant}.");

        // One-shot escrow mode: --provider selects it; the rest are then required.
        if ($args->provider !== null && $args->provider !== '') {
            $name = trim($args->secretName ?? '');
            $host = strtolower(trim($args->host ?? ''));
            // The key is NEVER a flag — argv/shell history would leak it. It
            // comes from the environment, and is never echoed back to the user.
            $value = getenv('KNOXCALL_WRAP_SECRET');
            if ($name === '') {
                throw new CliError('--secret-name is required with --provider');
            }
            if ($host === '') {
                throw new CliError('--host is required with --provider');
            }
            if (!is_string($value) || $value === '') {
                throw new CliError('set the provider key in the KNOXCALL_WRAP_SECRET env var (not a flag)');
            }

            $client->wrap->escrow([
                'provider' => $args->provider,
                'name' => $name,
                'value' => $value,
                'hosts' => [$host],
            ]);
            $res = $client->wrap->gatewayUrl(['secret' => $name, 'host' => $host]);
            $baseUrl = $string($res['base_url'] ?? null);

            $this->println('');
            $this->println("Escrowed '{$name}' for {$host} — your provider key is now in KnoxCall custody.");
            $this->println('Point a base-URL-only SDK at:');
            $this->println("  {$baseUrl}");
            $this->println('');
            $this->println('…or transport-wrap an SDK that takes a PSR-18 HTTP client:');
            $this->println('  $knox = new KnoxCall([/* your KnoxCall key */]);');
            $this->println('  $sdk  = new SomeSDK(["httpClient" => $knox->wrap->httpClient()]);');
            return 0;
        }

        // Scaffold mode: print the two-step quickstart, no writes.
        $this->println('');
        $this->println('Wrap a provider SDK through KnoxCall in two steps:');
        $this->println('');
        $this->println('1) Move the provider key into custody (key via KNOXCALL_WRAP_SECRET):');
        $this->println('   KNOXCALL_WRAP_SECRET=sk_live_… \\');
        $this->println('   knoxcall init --provider stripe --secret-name wrap-stripe --host api.stripe.com');
        $this->println('');
        $this->println('2) Route your SDK through KnoxCall (the key never re-enters your process):');
        $this->println('   $knox = new KnoxCall([/* your KnoxCall key */]);');
        $this->println('   $sdk  = new SomeSDK(["httpClient" => $knox->wrap->httpClient()]);');
        $this->println('   // …or point a base-URL-only SDK at the base_url that step 1 prints.');
        return 0;
    }

    private function println(string $line): void
    {
        fwrite($this->out, $line . "\n");
    }
}
