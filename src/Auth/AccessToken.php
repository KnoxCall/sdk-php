<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\Redacted;

/**
 * Pre-acquired access token (or KnoxCall API key) bootstrap. The `type`
 * discriminator defaults — callers never pass it.
 */
final class AccessToken
{
    public readonly string $type;

    private readonly Redacted $token;

    public function __construct(#[\SensitiveParameter] string $accessToken)
    {
        $this->type = 'access_token';
        $this->token = new Redacted($accessToken);
    }

    public function accessToken(): string
    {
        return $this->token->expose();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'accessToken' => '[REDACTED]',
        ];
    }
}
