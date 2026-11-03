<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\CredentialsFileLock;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall logout` — best-effort `POST /oauth/revoke` with the stored
 * refresh token, then remove the profile under the file lock (the file
 * itself is deleted when it was the last profile). Mirrors knoxcall-python
 * cli/logout.py.
 */
final class Logout
{
    /** @var resource */
    private $out;

    public function __construct(private readonly TransportInterface $transport, $out)
    {
        $this->out = $out;
    }

    public function run(ParsedArgs $args): int
    {
        $path = Common::requireCredentialsPath();
        $profile = CredentialsFile::resolveProfile($args->profile);
        $record = CredentialsFile::readProfile($path, $profile);
        if ($record === null) {
            fwrite($this->out, "No stored credentials for profile '{$profile}' — nothing to do.\n");
            return 0;
        }

        $refreshToken = $record['refresh_token'] ?? null;
        $baseUrl = rtrim(is_string($record['base_url'] ?? null) ? $record['base_url'] : '', '/');
        if (is_string($refreshToken) && $refreshToken !== '' && $baseUrl !== '') {
            $clientId = $record['client_id'] ?? null;
            try {
                Common::postForm($this->transport, "{$baseUrl}/oauth/revoke", [
                    'token' => $refreshToken,
                    'token_type_hint' => 'refresh_token',
                    'client_id' => is_string($clientId) && $clientId !== '' ? $clientId : Common::CLI_CLIENT_ID,
                ]);
            } catch (CliError) {
                // best-effort: removal proceeds even when revocation is unreachable
            }
        }

        $lock = new CredentialsFileLock($path);
        $lock->acquire();
        try {
            CredentialsFile::removeProfile($path, $profile);
        } finally {
            $lock->release();
        }
        fwrite($this->out, "Logged out — removed profile '{$profile}' from {$path}.\n");
        return 0;
    }
}
