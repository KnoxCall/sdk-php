<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\Redacted;

/**
 * OAuth 2.1 client-credentials bootstrap. The `type` discriminator defaults —
 * callers never pass it.
 *
 * The secret is wrapped in Redacted (never an object property holding the raw
 * string), so var_dump/print_r/serialize on the credential — or on a client
 * holding it — cannot leak it. #[\SensitiveParameter] additionally redacts the
 * value from stack traces on PHP 8.2+ (a harmless no-op on 8.1).
 */
final class ClientCredentials
{
    public readonly string $type;

    private readonly Redacted $secret;

    public function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter] string $clientSecret,
    ) {
        $this->type = 'client_credentials';
        $this->secret = new Redacted($clientSecret);
    }

    public function clientSecret(): string
    {
        return $this->secret->expose();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'clientId' => $this->clientId,
            'clientSecret' => '[REDACTED]',
        ];
    }
}
