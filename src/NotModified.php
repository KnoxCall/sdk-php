<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * The value {@see KnoxCall::request()} returns for a `304 Not Modified` when
 * the caller opted in with `$allowNotModified` (a conditional GET carrying
 * `If-None-Match`). Internal: the one consumer is
 * `wrap->interceptManifest(['if_none_match' => …])`, which maps it to `null`.
 * Without the opt-in a 304 keeps its old behaviour (an empty body decoded as
 * `null`), so nothing else changes.
 *
 * @internal
 */
final class NotModified
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
