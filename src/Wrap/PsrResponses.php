<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-7 glue shared by the PSR-18 client and the Guzzle middleware: the raw
 * `['status', 'headers', 'body']` result of {@see \KnoxCall\KnoxCall::call()} /
 * {@see \KnoxCall\KnoxCall::ephemeral()} in, a PSR-7 response out — built from
 * injected PSR-17 factories so no concrete PSR-7 implementation is baked in.
 */
final class PsrResponses
{
    /**
     * @param array{status: int, headers: array<string, string>, body: string} $raw
     */
    public static function fromRaw(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory, array $raw): ResponseInterface
    {
        $response = $responseFactory->createResponse($raw['status']);
        foreach ($raw['headers'] as $name => $value) {
            // Transport lower-cases response header names; forward them as
            // captured. withHeader replaces any factory default of the same name.
            $response = $response->withHeader((string) $name, (string) $value);
        }
        return $response->withBody($streamFactory->createStream($raw['body']));
    }

    /**
     * Flatten PSR-7 headers (name => value[]) to name => value, joining
     * multi-valued headers with ", " (RFC 7230 §3.2.2). Original casing kept.
     *
     * @return array<string, string>
     */
    public static function flattenHeaders(RequestInterface $request): array
    {
        $out = [];
        foreach ($request->getHeaders() as $name => $values) {
            $out[$name] = implode(', ', $values);
        }
        return $out;
    }
}
