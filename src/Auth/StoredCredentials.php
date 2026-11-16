<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

/**
 * Credentials file written by `knoxcall login` (PARITY §2). The `type`
 * discriminator defaults — callers never pass it.
 *
 * Holds no secrets itself — tokens are read from the file (path/profile
 * resolved from KNOXCALL_CREDENTIALS_FILE / KNOXCALL_PROFILE when not given)
 * at token-fetch time and wrapped in Redacted immediately. The file is the
 * cross-process token cache and the sole refresh authority: the server's
 * refresh tokens are SINGLE-USE with family revocation on reuse, so refreshes
 * run under the sibling `.lock` file and the rotated token is written back
 * atomically before the lock is released (see CredentialsFile /
 * CredentialsFileLock).
 */
final class StoredCredentials
{
    public readonly string $type;

    public function __construct(
        public readonly ?string $path = null,
        public readonly ?string $profile = null,
    ) {
        $this->type = 'stored_credentials';
    }

    /** Effective file path: explicit > KNOXCALL_CREDENTIALS_FILE > ~/.knoxcall/credentials.json. */
    public function resolvedPath(): ?string
    {
        return CredentialsFile::resolvePath($this->path);
    }

    /** Effective profile name: explicit > KNOXCALL_PROFILE > "default". */
    public function resolvedProfile(): string
    {
        return CredentialsFile::resolveProfile($this->profile);
    }
}
