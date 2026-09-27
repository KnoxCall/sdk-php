<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * Base class for every exception thrown by the KnoxCall SDK.
 *
 * Hierarchy:
 *   KnoxCallException
 *   ├── ApiException (HTTP status errors, ->statusCode)
 *   │   ├── AuthenticationException   401
 *   │   ├── PermissionDeniedException 403
 *   │   ├── NotFoundException         404
 *   │   ├── ConflictException         409
 *   │   ├── ValidationException       422 (->fields)
 *   │   ├── RateLimitException        429 (->retryAfter)
 *   │   ├── ServerException           5xx
 *   │   └── SignupException           KnoxCall::signup() failures
 *   ├── ConnectionException (transport failures, ->requestSent)
 *   │   └── ConnectionTimeoutException
 *   ├── NotAuthenticatedException (no credential auto-detected — offer login())
 *   ├── StaleAssertionException (a workload assertion source returned spent bytes)
 *   ├── WebhookSignatureVerificationException (constructEvent() failures)
 *   └── Wrap\WrapSandboxMismatchError (wrap httpClient both-must-agree failures)
 */
class KnoxCallException extends \RuntimeException {}
