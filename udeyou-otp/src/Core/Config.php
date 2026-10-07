<?php

declare(strict_types=1);

namespace Udeyou\Core;

/**
 * Minimal .env loader + typed accessors. No external dependency.
 */
final class Config
{
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            throw new \RuntimeException("Missing config file: {$envFile} (copy .env.example to .env)");
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }

        // Real environment variables win over the file (handy for tests / CI).
        foreach (self::$values as $key => $_) {
            $env = getenv($key);
            if ($env !== false) {
                self::$values[$key] = $env;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Config key {$key} is required");
        }
        return $value;
    }
}
