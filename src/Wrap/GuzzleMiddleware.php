<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\ConnectionException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A Guzzle middleware for a `HandlerStack` an SDK builds itself and lets you
 * push middleware onto: a request the pipeline claims — a Route covers its
 * host + path, or the host is listed — is answered from KnoxCall without ever
 * reaching the stack's handler; every other request continues down the stack
 * untouched. Built by {@see \KnoxCall\Resources\WrapResource::guzzleMiddleware()}:
 *
 *   $stack = \GuzzleHttp\HandlerStack::create();
 *   $stack->push($knox->wrap->guzzleMiddleware(['hosts' => ['api.resend.com']]), 'knoxcall');
 *   $guzzle = new \GuzzleHttp\Client(['handler' => $stack]);   // or the SDK's own stack
 *
 * It shares {@see InterceptPipeline} with the PSR-18 client, so the decisions
 * are identical. Guzzle is not a dependency of this package: the middleware is
 * a plain callable and only touches Guzzle's promise classes when it runs.
 */
final class GuzzleMiddleware
{
    public function __construct(
        private readonly InterceptPipeline $pipeline,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /** The shared pipeline (ready / refresh / manifest / stop). */
    public function pipeline(): InterceptPipeline
    {
        return $this->pipeline;
    }

    /**
     * The Guzzle middleware signature: `callable(callable $handler): callable`.
     *
     * @return callable(RequestInterface, array<string, mixed>): \GuzzleHttp\Promise\PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $url = (string) $request->getUri();
            $method = $request->getMethod();
            $decision = $this->pipeline->decide($url, $method);
            if ($decision['mode'] === InterceptResolver::MODE_DIRECT) {
                $this->pipeline->directDecided($decision, $url, $method, static fn (): array => PsrResponses::flattenHeaders($request));
                return $handler($request, $options);
            }

            $body = (string) $request->getBody();
            $headers = PsrResponses::flattenHeaders($request);
            try {
                $raw = $this->pipeline->send($decision, $url, $method, $headers, $body);
            } catch (ConnectionException $e) {
                // Guzzle's own connection failure type, so a wrapped SDK's retry
                // middleware (which knows Guzzle, not KnoxCall) still fires.
                return self::rejected(new \GuzzleHttp\Exception\ConnectException($e->getMessage(), $request, $e));
            }
            if ($raw === InterceptPipeline::DIRECT) {
                return $handler($request->withBody($this->streamFactory->createStream($body)), $options);
            }
            return self::fulfilled(PsrResponses::fromRaw($this->responseFactory, $this->streamFactory, $raw));
        };
    }

    /** @return \GuzzleHttp\Promise\PromiseInterface */
    private static function fulfilled(ResponseInterface $response): object
    {
        if (class_exists(\GuzzleHttp\Promise\Create::class)) {
            return \GuzzleHttp\Promise\Create::promiseFor($response);
        }
        return new \GuzzleHttp\Promise\FulfilledPromise($response);
    }

    /** @return \GuzzleHttp\Promise\PromiseInterface */
    private static function rejected(\Throwable $reason): object
    {
        if (class_exists(\GuzzleHttp\Promise\Create::class)) {
            return \GuzzleHttp\Promise\Create::rejectionFor($reason);
        }
        return new \GuzzleHttp\Promise\RejectedPromise($reason);
    }
}
