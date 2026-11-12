<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * Thrown by KnoxCall::constructEvent() / $client->webhooks->constructEvent()
 * when an incoming webhook delivery fails verification: missing signature
 * header, signature mismatch, stale timestamp, or a body that is not valid
 * JSON. The message says what failed without echoing the signature or secret.
 */
class WebhookSignatureVerificationException extends KnoxCallException {}
