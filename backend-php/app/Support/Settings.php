<?php

namespace App\Support;

final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return config('freelingo.'.$key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }
}
