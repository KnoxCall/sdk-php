<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * Thrown by KnoxCall::exchangeToken() when the credential-less RFC 8693
 * POST /v1/oauth/token call is refused, or returns a body with no
 * access_token. Carries the usual ApiException fields: ->statusCode,
 * ->errorCode (the RFC 6749 §5.2 code — "invalid_grant", "invalid_target",
 * "unsupported_grant_type", "invalid_request", "server_error"),
 * ->responseHeaders and ->responseBody.
 */
class TokenExchangeException extends ApiException {}
