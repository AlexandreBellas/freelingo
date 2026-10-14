<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class Iso
{
    public static function string(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $formatted = $value->format('Y-m-d\TH:i:s');
            $micro = $value->format('u');
            if ((int) $micro !== 0) {
                $formatted .= '.'.rtrim($micro, '0');
            }

            return $formatted;
        }

        return Carbon::parse((string) $value)->utc()->format('Y-m-d\TH:i:s');
    }
}
