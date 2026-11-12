<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client double for the wrap httpClient's route-around/direct path:
 * records every request it is handed and returns a fixed response. Lets tests
 * prove a route-around request bypassed KnoxCall entirely (the MockTransport
 * sees nothing) and went straight to the provider.
 */
final class FakeDirectClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return $this->response;
    }
}
