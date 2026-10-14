<?php

namespace App\Support;

use DateTimeInterface;

final class JsonDate
{
    public static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! $value instanceof DateTimeInterface) {
            return (string) $value;
        }
        $formatted = $value->format('Y-m-d\TH:i:s');
        $micro = (int) $value->format('u');
        if ($micro !== 0) {
            $formatted .= '.'.$value->format('u');
        }

        return $formatted;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
