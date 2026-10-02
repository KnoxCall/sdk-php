<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * Thrown by KnoxCall::signup() when the credential-less POST /v1/signup call
 * fails (validation error, slug conflict, rate limit) or returns an
 * unexpected body. Carries the usual ApiException fields: ->statusCode,
 * ->errorCode (the server's error type, e.g. "slug_taken" or "rate_limited"),
 * ->requestId, ->responseHeaders, and ->responseBody.
 */
class SignupException extends ApiException {}
