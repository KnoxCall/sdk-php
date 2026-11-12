<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\StoredCredentials;
use KnoxCall\KnoxCall;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall whoami` — construct the SDK client from the stored credentials,
 * `GET /v1/account`, print tenant slug, name, and plan. Mirrors
 * knoxcall-python cli/whoami.py.
 */
final class Whoami
{
    /** @var resource */
    private $out;

    public function __construct(private readonly ?TransportInterface $transport, $out)
    {
        $this->out = $out;
    }

    public function run(ParsedArgs $args): int
    {
        $path = Common::requireCredentialsPath();
        $profile = CredentialsFile::resolveProfile($args->profile);
        if (CredentialsFile::readProfile($path, $profile) === null) {
            throw new CliError("not logged in (profile '{$profile}') — run `knoxcall login`");
        }

        $opts = ['credentials' => new StoredCredentials($path, $profile)];
        if ($this->transport !== null) {
            $opts['transport'] = $this->transport;
        }
        $account = (new KnoxCall($opts))->account->get();

        $string = static function (mixed $value): string {
            return is_string($value) ? $value : '';
        };
        $slug = $string($account['slug'] ?? null);
        $name = $string($account['name'] ?? null);
        if ($name === '') {
            $name = $string($account['company_name'] ?? null);
        }
        $plan = $string($account['plan'] ?? null);
        if ($plan === '') {
            $plan = $string($account['subscription_plan'] ?? null);
        }

        $tenant = $name !== '' ? $name : ($slug !== '' ? $slug : '(unknown)');
        fwrite($this->out, "Tenant: {$tenant}\n");
        if ($slug !== '') {
            fwrite($this->out, "Slug:   {$slug}\n");
        }
        if ($plan !== '') {
            fwrite($this->out, "Plan:   {$plan}\n");
        }
        fwrite($this->out, "Profile: {$profile} ({$path})\n");
        return 0;
    }
}
