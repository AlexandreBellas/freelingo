<?php

namespace App\Services\Security;

use App\Support\Settings;

class PasswordHasher
{
    private static ?string $dummyHash = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => max(4, Settings::int('bcrypt_rounds', 12)),
        ]);
    }

    public static function verify(string $plain, string $hashed): bool
    {
        return password_verify($plain, $hashed);
    }

    public static function dummyVerify(): void
    {
        password_verify('freelingo_dummy_placeholder', self::dummyHash());
    }

    private static function dummyHash(): string
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash('freelingo_dummy_placeholder', PASSWORD_BCRYPT, [
                'cost' => max(4, Settings::int('bcrypt_rounds', 12)),
            ]);
        }

        return self::$dummyHash;
    }
}
