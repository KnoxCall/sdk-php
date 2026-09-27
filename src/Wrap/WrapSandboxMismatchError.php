<?php

declare(strict_types=1);

namespace KnoxCall\Wrap;

use KnoxCall\KnoxCallException;

/**
 * Thrown when the wrap httpClient's both-must-agree check fails: a wrapped
 * Stripe key's Test/Live prefix does not match the KnoxCall client's `sandbox`
 * flag, a publishable (`pk_`) key was supplied, or a caller-supplied
 * route-around host is not a bare DNS hostname.
 *
 * A {@see KnoxCallException} subclass, so existing `catch (KnoxCallException)`
 * blocks keep working while callers can branch on this specific type.
 */
final class WrapSandboxMismatchError extends KnoxCallException {}
