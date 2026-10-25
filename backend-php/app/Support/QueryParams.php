<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

final class QueryParams
{
    public static function int(Request $request, string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        if (! $request->query->has($key)) {
            return $default;
        }
        $raw = $request->query($key);
        if (! is_scalar($raw) || ! preg_match('/^-?\d+$/', (string) $raw)) {
            throw new ApiException('Validation error', 422);
        }
        $value = (int) $raw;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new ApiException('Validation error', 422);
        }

        return $value;
    }

    public static function optionalEnum(Request $request, string $key, array $allowed): ?string
    {
        if (! $request->query->has($key) || $request->query($key) === null || $request->query($key) === '') {
            return null;
        }
        $value = (string) $request->query($key);
        if (! in_array($value, $allowed, true)) {
            throw new ApiException('Validation error', 422);
        }

        return $value;
    }

    public static function enum(Request $request, string $key, string $default, array $allowed): string
    {
        if (! $request->query->has($key) || $request->query($key) === null || $request->query($key) === '') {
            return $default;
        }
        $value = (string) $request->query($key);
        if (! in_array($value, $allowed, true)) {
            throw new ApiException('Validation error', 422);
        }

        return $value;
    }

    public static function bool(Request $request, string $key): ?bool
    {
        if (! $request->query->has($key)) {
            return null;
        }
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $normalized = strtolower((string) $raw);
        if (in_array($normalized, ['1', 'true'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false'], true)) {
            return false;
        }
        throw new ApiException('Validation error', 422);
    }

    public static function boundedString(Request $request, string $key, ?string $default, ?int $max = null): ?string
    {
        if (! $request->query->has($key)) {
            return $default;
        }
        $value = $request->query($key);
        if ($value === null) {
            return $default;
        }
        $value = (string) $value;
        if ($max !== null && strlen($value) > $max) {
            throw new ApiException('Validation error', 422);
        }

        return $value;
    }
}
