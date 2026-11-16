<?php

declare(strict_types=1);

namespace KnoxCall;

/** 401 — or an unusable token-endpoint response (non-JSON 200, missing access_token). */
class AuthenticationException extends ApiException {}
