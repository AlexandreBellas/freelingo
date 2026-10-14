<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class Clock
{
    private static ?Carbon $frozen = null;

    public static function now(): Carbon
    {
        return self::$frozen ? self::$frozen->copy() : Carbon::now('UTC');
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function freeze(?Carbon $moment = null): void
    {
        self::$frozen = ($moment ?? Carbon::now('UTC'))->copy()->utc();
    }

    public static function advance(int $seconds): void
    {
        self::$frozen = self::now()->addSeconds($seconds);
    }

    public static function reset(): void
    {
        self::$frozen = null;
    }
}
