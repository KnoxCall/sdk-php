<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\ConnectionException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 network-failure wrapper for the wrap httpClient.
 *
 * PSR-18 requires {@see \Psr\Http\Client\ClientInterface::sendRequest()} to
 * throw a {@see NetworkExceptionInterface} when the request cannot be sent
 * (DNS, connect, TLS, reset, timeout) — a wrapped SDK's retry logic branches on
 * exactly that type. KnoxCall's data plane raises {@see ConnectionException}
 * instead, so {@see KnoxCallHttpClient} catches it and rethrows it wrapped here.
 *
 * It EXTENDS {@see ConnectionException} (preserving `requestSent` and the
 * original as `getPrevious()`) so existing `catch (ConnectionException)` code
 * keeps working, while ALSO implementing {@see NetworkExceptionInterface} so
 * PSR-18 consumers catch it too.
 */
final class WrapNetworkException extends ConnectionException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        ConnectionException $previous,
    ) {
        parent::__construct($previous->getMessage(), $previous->requestSent, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
