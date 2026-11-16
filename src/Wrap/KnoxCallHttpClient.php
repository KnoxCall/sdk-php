<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\ConnectionException;
use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 {@see ClientInterface} decorator that re-targets each
 * `sendRequest(RequestInterface)` through KnoxCall — the PHP equivalent of the
 * Node SDK's `wrap.fetch()`.
 *
 * Hand it to any modern third-party SDK that accepts a PSR-18 client — a Guzzle
 * 7 instance (which itself implements PSR-18) or an SDK built on php-http/httplug:
 *
 *   $http = $knox->wrap->httpClient(['routes' => 'auto']);   // route-aware
 *   $sdk  = new SomeVendor\Client([                          // SDK formats its own auth
 *       'apiKey'     => 'sk_live_…',
 *       'httpClient' => $http,                               // any PSR-18 client slot
 *   ]);
 *
 * For SDKs that expose ONLY a base-URL override and no transport hook (Resend,
 * Mailgun, Airtable, …) use {@see \KnoxCall\Resources\WrapResource::gatewayUrl()}
 * instead — this decorator needs a PSR-18 seam to plug into. For an SDK that
 * builds its own Guzzle `HandlerStack` and lets you push middleware, see
 * {@see \KnoxCall\Resources\WrapResource::guzzleMiddleware()}.
 *
 * The decorator is a THIN adapter over the shared {@see InterceptPipeline}:
 * PSR-7 request → the pipeline's decision (direct / route / ephemeral) →
 * {@see KnoxCall::call()} or {@see KnoxCall::ephemeral()} (which own the proxy
 * heavy-lifting: SSRF pin, PAN denylist, metering, audit) → PSR-7 response,
 * built from injected PSR-17 factories so no concrete PSR-7 implementation is
 * baked in. Direct decisions (route-around, the client's own hosts, the kill
 * switch, a D4 fallback) send the ORIGINAL request via the direct client.
 */
final class KnoxCallHttpClient implements ClientInterface
{
    private readonly InterceptPipeline $pipeline;

    /** Underlying PSR-18 client for direct calls (lazy-resolved). */
    private ?ClientInterface $directClient;

    /**
     * @param array<string, mixed> $opts see {@see \KnoxCall\Resources\WrapResource::httpClient()};
     *   a prebuilt `['pipeline' => InterceptPipeline]` is honoured
     */
    public function __construct(
        KnoxCall $client,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        array $opts = [],
    ) {
        $this->pipeline = ($opts['pipeline'] ?? null) instanceof InterceptPipeline
            ? $opts['pipeline']
            : new InterceptPipeline($client, true, $opts);
        $this->directClient = isset($opts['direct_client']) && $opts['direct_client'] instanceof ClientInterface
            ? $opts['direct_client']
            : null;
    }

    /** The shared pipeline (the manifest store, the decision table). */
    public function pipeline(): InterceptPipeline
    {
        return $this->pipeline;
    }

    /**
     * Load the manifest now if it is stale (the first attempt included) and
     * return it — call before the first request when the first decision must
     * already see the manifest. Never throws for a manifest failure.
     *
     * @return array<string, mixed>|null
     */
    public function ready(): ?array
    {
        return $this->pipeline->ready();
    }

    /** @return array<string, mixed>|null refresh the manifest now (no-op with routes off) */
    public function refresh(): ?array
    {
        return $this->pipeline->refresh();
    }

    /** @return array<string, mixed>|null the manifest this client is deciding on, or null */
    public function manifest(): ?array
    {
        return $this->pipeline->manifest();
    }

    /** Stop refreshing and drop the manifest; listed hosts stay ephemeral, the rest direct. */
    public function stop(): void
    {
        $this->pipeline->stop();
    }

    /**
     * Re-target one PSR-7 request through KnoxCall and return the upstream's
     * PSR-7 response.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();

        // Decided BEFORE the body is read: a direct request is forwarded
        // untouched (its body stream is not read here), so a streamed /
        // multipart body stays intact — never "try KnoxCall then fall back",
        // which would already have transited.
        $decision = $this->pipeline->decide($url, $method);
        if ($decision['mode'] === InterceptResolver::MODE_DIRECT) {
            $this->pipeline->directDecided($decision, $url, $method, static fn (): array => PsrResponses::flattenHeaders($request));
            return $this->direct()->sendRequest($request);
        }

        // Body bytes, byte-verbatim. (string) on a PSR-7 stream rewinds + reads
        // it fully. Held in memory, so a resend after a routing refusal is
        // replayable by construction.
        $body = (string) $request->getBody();
        $headers = PsrResponses::flattenHeaders($request);

        try {
            $raw = $this->pipeline->send($decision, $url, $method, $headers, $body);
        } catch (ConnectionException $e) {
            // PSR-18 §sendRequest: a transport failure is a NetworkExceptionInterface
            // so a wrapped SDK's retry logic recognises it.
            throw new WrapNetworkException($request, $e);
        }
        if ($raw === InterceptPipeline::DIRECT) {
            // A D4 fallback or a re-decision to direct: the ORIGINAL request,
            // its body restored from the bytes already read.
            return $this->direct()->sendRequest($request->withBody($this->streamFactory->createStream($body)));
        }
        return PsrResponses::fromRaw($this->responseFactory, $this->streamFactory, $raw);
    }

    /**
     * The underlying PSR-18 client for direct calls. Resolved lazily so a
     * client whose every request goes through KnoxCall needs no direct
     * transport at all.
     */
    private function direct(): ClientInterface
    {
        if ($this->directClient !== null) {
            return $this->directClient;
        }
        if (class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            return $this->directClient = \Http\Discovery\Psr18ClientDiscovery::find();
        }
        if (class_exists(\GuzzleHttp\Client::class)) {
            return $this->directClient = new \GuzzleHttp\Client();
        }
        throw new KnoxCallException(
            'wrap httpClient needs a direct PSR-18 client to send a request straight to the provider (a route-around '
            . 'rule, the client\'s own host, the kill switch, or an unavailable-direct fallback), but none is available. '
            . 'Pass ["direct_client" => $psr18Client] to httpClient(), or install php-http/discovery alongside a PSR-18 '
            . 'implementation (e.g. guzzlehttp/guzzle ^7).'
        );
    }
}
