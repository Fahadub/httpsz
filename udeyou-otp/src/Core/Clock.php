<?php

declare(strict_types=1);

namespace Udeyou\Core;

/** All dates in the app are UTC "Y-m-d H:i:s" strings. */
final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function plusSeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    public static function minusSeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    public static function toIso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }
}
