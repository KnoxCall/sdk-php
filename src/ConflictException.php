<?php

declare(strict_types=1);

namespace KnoxCall;

/** 409 — never retried: a real conflict does not resolve by replaying. */
class ConflictException extends ApiException {}
