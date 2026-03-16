<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return ($value !== false) ? $value : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = getenv($key);
        return ($value !== false) ? (int)$value : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false) return $default;
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Required configuration key '{$key}' is not set.");
        }
        return $value;
    }
}
