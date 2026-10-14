<?php

namespace App\Services\Practice;

use Illuminate\Support\Carbon;

final class Text
{
    public static function pyFormat(string $template, array $values): string
    {
        $out = '';
        $length = strlen($template);
        for ($i = 0; $i < $length; $i++) {
            $char = $template[$i];
            $next = $i + 1 < $length ? $template[$i + 1] : '';
            if ($char === '{' && $next === '{') {
                $out .= '{';
                $i++;

                continue;
            }
            if ($char === '}' && $next === '}') {
                $out .= '}';
                $i++;

                continue;
            }
            if ($char === '{') {
                $end = strpos($template, '}', $i);
                if ($end === false) {
                    $out .= $char;

                    continue;
                }
                $key = substr($template, $i + 1, $end - $i - 1);
                if (! array_key_exists($key, $values)) {
                    throw new \InvalidArgumentException("Missing format key {$key}");
                }
                $out .= (string) $values[$key];
                $i = $end;

                continue;
            }
            $out .= $char;
        }

        return $out;
    }

    public static function pyJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /**
     * Match Python json.dumps(..., sort_keys=True, separators=(",", ":"), ensure_ascii=False).
     */
    public static function canonicalJson(mixed $value): string
    {
        return self::encodeSorted($value);
    }

    public static function sha256Canonical(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    public static function iso(Carbon|\DateTimeInterface|null $value, bool $zulu = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $carbon = Carbon::parse($value)->utc();
        $formatted = $carbon->format('Y-m-d\TH:i:s');
        $micro = $carbon->format('u');
        if ((int) $micro !== 0) {
            $formatted .= '.'.rtrim($micro, '0');
        }

        return $zulu ? $formatted.'Z' : $formatted;
    }

    public static function utcNow(): Carbon
    {
        return Carbon::now('UTC');
    }

    private static function encodeSorted(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $isList = array_is_list($value);
            if ($isList) {
                return '['.implode(',', array_map(fn ($item) => self::encodeSorted($item), $value)).']';
            }
            ksort($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).':'.self::encodeSorted($item);
            }

            return '{'.implode(',', $parts).'}';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return json_encode($value) ?: '0';
        }

        return json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
    }
}
