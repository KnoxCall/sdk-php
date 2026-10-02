<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

use KnoxCall\Redacted;

/**
 * RFC 8693 OIDC token-exchange bootstrap (workload identity: GitHub Actions,
 * GCP, AWS IRSA, Azure MI, Vercel, CircleCI ID tokens). The `type`
 * discriminator defaults — callers never pass it.
 */
final class OIDCTokenExchange
{
    public readonly string $type;

    private readonly Redacted $token;

    public function __construct(
        #[\SensitiveParameter] string $subjectToken,
        public readonly string $issuer,
    ) {
        $this->type = 'oidc_token_exchange';
        $this->token = new Redacted($subjectToken);
    }

    public function subjectToken(): string
    {
        return $this->token->expose();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'issuer' => $this->issuer,
            'subjectToken' => '[REDACTED]',
        ];
    }
}
