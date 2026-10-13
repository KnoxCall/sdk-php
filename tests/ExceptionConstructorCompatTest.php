<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\ServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exception constructors are public API: callers build them in their own
 * test doubles, custom transports and retry wrappers. A parameter inserted
 * anywhere but the END of a published constructor shifts every positional
 * argument after it, so `new X('m', 500, 'code', 'req')` becomes a TypeError
 * (or, with a null in the way, silently files the request id as the error
 * code) — a breaking change that semver would call a minor.
 *
 * That happened once: ServerException had no constructor of its own in 1.0.0
 * (it inherited ApiException's), and `retryAfter` was then added as its THIRD
 * parameter. This file pins the 1.0.0 shape of every exception class, taken
 * from the `knoxcall-php-v1.0.0` tag, so the next addition has to go last.
 */
final class ExceptionConstructorCompatTest extends TestCase
{
    /**
     * Constructor parameters as published in 1.0.0 (tag knoxcall-php-v1.0.0):
     * [name, type, optional]. Frozen — this is a record of what shipped, not a
     * policy; a class may ADD optional parameters after these, nothing else.
     */
    private const API_EXCEPTION_1_0_0 = [
        ['message', 'string', false],
        ['statusCode', 'int', false],
        ['errorCode', '?string', true],
        ['requestId', '?string', true],
        ['responseHeaders', 'array', true],
        ['responseBody', 'mixed', true],
        ['previous', '?Throwable', true],
    ];

    /** PHP's own \Exception::__construct, inherited unchanged in 1.0.0. */
    private const BUILTIN_EXCEPTION_1_0_0 = [
        ['message', 'string', true],
        ['code', 'int', true],
        ['previous', '?Throwable', true],
    ];

    /**
     * @return array<string, array{class-string, list<array{string, string, bool}>}>
     */
    public static function publishedConstructors(): array
    {
        $api = self::API_EXCEPTION_1_0_0;
        $builtin = self::BUILTIN_EXCEPTION_1_0_0;
        $tail = array_slice($api, 2); // errorCode … previous

        $cases = [
            // Inherited ApiException's constructor verbatim in 1.0.0.
            'ApiException' => [\KnoxCall\ApiException::class, $api],
            'AuthenticationException' => [\KnoxCall\AuthenticationException::class, $api],
            'ConflictException' => [\KnoxCall\ConflictException::class, $api],
            'NotFoundException' => [\KnoxCall\NotFoundException::class, $api],
            'PaymentRequiredException' => [\KnoxCall\PaymentRequiredException::class, $api],
            'PermissionDeniedException' => [\KnoxCall\PermissionDeniedException::class, $api],
            'ServerException' => [\KnoxCall\ServerException::class, $api],
            'SignupException' => [\KnoxCall\SignupException::class, $api],
            'TokenExchangeException' => [\KnoxCall\TokenExchangeException::class, $api],
            // Declared their own constructor in 1.0.0 — that shape is published.
            'RateLimitException' => [\KnoxCall\RateLimitException::class, array_merge(
                [['message', 'string', false], ['statusCode', 'int', true], ['retryAfter', '?int', true]],
                $tail,
            )],
            'ValidationException' => [\KnoxCall\ValidationException::class, array_merge(
                [['message', 'string', false], ['statusCode', 'int', true], ['fields', 'array', true]],
                $tail,
            )],
            'AIGatewayException' => [\KnoxCall\AIGatewayException::class, array_merge(
                [
                    ['message', 'string', false],
                    ['statusCode', 'int', false],
                    ['errorDescription', 'string', true],
                    ['retryAfter', '?int', true],
                ],
                $tail,
            )],
            'ConnectionException' => [\KnoxCall\ConnectionException::class, [
                ['message', 'string', false],
                ['requestSent', 'bool', true],
                ['previous', '?Throwable', true],
            ]],
            'ConnectionTimeoutException' => [\KnoxCall\ConnectionTimeoutException::class, [
                ['message', 'string', false],
                ['requestSent', 'bool', true],
                ['previous', '?Throwable', true],
            ]],
            'KnoxCallException' => [\KnoxCall\KnoxCallException::class, $builtin],
            'NotAuthenticatedException' => [\KnoxCall\NotAuthenticatedException::class, $builtin],
            'StaleAssertionException' => [\KnoxCall\StaleAssertionException::class, $builtin],
            'WebhookSignatureVerificationException' => [\KnoxCall\WebhookSignatureVerificationException::class, $builtin],
        ];
        return $cases;
    }

    /**
     * @param class-string $class
     * @param list<array{string, string, bool}> $published
     */
    #[DataProvider('publishedConstructors')]
    public function testConstructorKeepsItsPublishedPositionalShape(string $class, array $published): void
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($ctor, "{$class} has no constructor");
        $params = $ctor->getParameters();

        $this->assertGreaterThanOrEqual(
            count($published),
            count($params),
            "{$class}: a published constructor parameter was removed",
        );

        foreach ($published as $i => [$name, $type, $optional]) {
            $p = $params[$i];
            // Name: PHP 8 named arguments make the name public API too.
            $this->assertSame(
                $name,
                $p->getName(),
                "{$class}: parameter #" . ($i + 1) . " was \${$name} in 1.0.0 and is now \${$p->getName()} "
                . '— a new parameter goes LAST, never in front of a published one',
            );
            // Type: anything the 1.0.0 type accepted must still be accepted.
            $now = (string) $p->getType();
            $this->assertTrue(
                $now === $type || $now === '?' . $type || $now === 'mixed',
                "{$class}: \${$name} was {$type} in 1.0.0 and is now {$now}",
            );
            // A 1.0.0 caller may have omitted an optional parameter.
            if ($optional) {
                $this->assertTrue($p->isOptional(), "{$class}: \${$name} was optional in 1.0.0 and is now required");
            }
        }

        // Anything added after 1.0.0 must be optional, or every 1.0.0 call site
        // stops compiling.
        foreach (array_slice($params, count($published)) as $p) {
            $this->assertTrue(
                $p->isOptional(),
                "{$class}: \${$p->getName()} was added after 1.0.0 and must be optional",
            );
        }
    }

    public function testServerExceptionAcceptsTheOneZeroZeroPositionalCall(): void
    {
        // The exact 1.0.0 call that the mid-list `retryAfter` turned into a
        // TypeError on argument #3.
        $e = new ServerException('boom', 500, 'internal_error', 'req_1');
        $this->assertSame(500, $e->statusCode);
        $this->assertSame('internal_error', $e->errorCode);
        $this->assertSame('req_1', $e->requestId);
        $this->assertNull($e->retryAfter);
    }

    public function testServerExceptionNeverFilesTheRequestIdAsTheErrorCode(): void
    {
        // The quieter half of the same break: with a null error code in the
        // way, a mid-list `retryAfter` took the null and the request id slid
        // into $errorCode — no error, just a wrong value.
        $e = new ServerException('boom', 502, null, 'req_2');
        $this->assertNull($e->errorCode);
        $this->assertSame('req_2', $e->requestId);
        $this->assertNull($e->retryAfter);
    }

    public function testServerExceptionAcceptsEveryOneZeroZeroPositionThenRetryAfterLast(): void
    {
        $cause = new \RuntimeException('socket closed');
        $headers = ['retry-after' => '5', 'x-request-id' => 'req_3'];
        $body = ['error' => ['type' => 'dependency_unavailable']];

        $e = new ServerException('shed', 503, 'dependency_unavailable', 'req_3', $headers, $body, $cause, 5);
        $this->assertSame(503, $e->statusCode);
        $this->assertSame('dependency_unavailable', $e->errorCode);
        $this->assertSame('req_3', $e->requestId);
        $this->assertSame($headers, $e->responseHeaders);
        $this->assertSame($body, $e->responseBody);
        $this->assertSame($cause, $e->getPrevious());
        $this->assertSame(5, $e->retryAfter);

        // And by name, which is how the SDK itself passes it.
        $named = new ServerException('shed', 503, retryAfter: 7);
        $this->assertNull($named->errorCode);
        $this->assertSame(7, $named->retryAfter);
    }

    public function testFromResponseStillPopulatesEveryServerExceptionField(): void
    {
        // The SDK's own construction site moved to a named argument; prove the
        // mapping lands each value in its field.
        $e = \KnoxCall\ApiException::fromResponse(
            503,
            ['error' => ['type' => 'dependency_unavailable', 'message' => 'later', 'request_id' => 'req_4']],
            ['retry-after' => '9'],
        );
        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertSame(503, $e->statusCode);
        $this->assertSame('dependency_unavailable', $e->errorCode);
        $this->assertSame('req_4', $e->requestId);
        $this->assertSame(['retry-after' => '9'], $e->responseHeaders);
        $this->assertSame(9, $e->retryAfter);

        $plain = \KnoxCall\ApiException::fromResponse(500, ['error' => ['type' => 'internal_error']], []);
        $this->assertInstanceOf(ServerException::class, $plain);
        $this->assertSame('internal_error', $plain->errorCode);
        $this->assertNull($plain->retryAfter);
    }
}
