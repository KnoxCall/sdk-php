<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class AccountResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    public function get(): array
    {
        return self::data($this->client->request('GET', '/v1/account'));
    }

    public function getUsage(): array
    {
        return self::data($this->client->request('GET', '/v1/account/usage'));
    }
}
