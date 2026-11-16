<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * Opaque wrapper for sensitive values — mirrors redacted.py / redacted.ts.
 *
 * The value is held in a static WeakMap rather than an object property, so
 * var_dump(), print_r(), var_export(), serialize() and json_encode() on the
 * wrapper (or anything holding it) can never print the secret. Call
 * ->expose() only at the moment of HTTP header construction.
 */
final class Redacted implements \Stringable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function expose(): string
    {
        return self::$values[$this];
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
