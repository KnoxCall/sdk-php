<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * ULID generator (Crockford base32) — used for X-Idempotency-Key values that
 * stay stable across retries of one logical request.
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ENCODING[$ms & 31] . $time;
            $ms >>= 5;
        }
        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ENCODING[random_int(0, 31)];
        }
        return $time . $random;
    }
}
