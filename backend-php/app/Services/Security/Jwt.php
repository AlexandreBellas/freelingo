<?php

namespace App\Services\Security;

use App\Exceptions\ApiException;
use App\Support\Settings;

class Jwt
{
    public static function createAccessToken(int $userId, string $role, ?int $expiresAt = null): string
    {
        $exp = $expiresAt ?? (time() + (Settings::int('access_token_expire_minutes', 15) * 60));

        return self::encode([
            'sub' => (string) $userId,
            'role' => $role,
            'exp' => $exp,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function signPayload(array $payload, ?string $secret = null): string
    {
        $header = self::b64encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::b64encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::b64encode(hash_hmac('sha256', $header.'.'.$body, $secret ?? Settings::string('secret_key'), true));

        return $header.'.'.$body.'.'.$signature;
    }

    public static function createRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function decode(string $token, array $options = []): array
    {
        $verifyExp = $options['verify_exp'] ?? true;
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException('Invalid token', 401);
        }
        [$header, $payload, $signature] = $parts;
        $expected = self::sign($header.'.'.$payload);
        if (! hash_equals($expected, $signature)) {
            throw new ApiException('Invalid token', 401);
        }
        $data = json_decode(self::b64decode($payload), true);
        if (! is_array($data) || ! isset($data['sub'])) {
            throw new ApiException('Invalid token', 401);
        }
        if ($verifyExp && (! isset($data['exp']) || (int) $data['exp'] < time())) {
            throw new ApiException('Invalid token', 401);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        $header = self::b64encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::b64encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::sign($header.'.'.$body);

        return $header.'.'.$body.'.'.$signature;
    }

    private static function sign(string $data): string
    {
        return self::b64encode(hash_hmac('sha256', $data, Settings::string('secret_key'), true));
    }

    private static function b64encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
