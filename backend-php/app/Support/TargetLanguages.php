<?php

namespace App\Support;

use App\Exceptions\ApiException;

final class TargetLanguages
{
    /** @var list<string> */
    public const SUPPORTED = [
        'en-US', 'en-GB', 'de-DE', 'es-ES', 'fr-FR', 'it-IT', 'ja-JP', 'ko-KR', 'pt-PT', 'zh-CN',
    ];

    /** @var list<string> */
    public const NATIVE = [
        'da', 'de', 'en', 'es', 'fi', 'fr', 'hr', 'it', 'nl', 'pl', 'pt', 'ro', 'ru', 'sv', 'tr',
    ];

    /** @var list<string> */
    public const BANNER_LOCALES = [
        'en', 'es', 'fr', 'pt', 'de', 'it', 'ru', 'nl', 'pl', 'ro', 'tr', 'sv', 'da', 'fi', 'hr',
    ];

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        $configured = Settings::get('available_target_languages', self::SUPPORTED);
        if (! is_array($configured)) {
            return self::SUPPORTED;
        }

        return array_values(array_filter(
            $configured,
            fn (mixed $lang) => is_string($lang) && in_array($lang, self::SUPPORTED, true),
        ));
    }

    public static function assertAvailable(string $language): void
    {
        $available = self::available();
        if (! in_array($language, $available, true)) {
            throw new ApiException('Language not available. Choose from: '.implode(', ', $available), 422);
        }
    }
}
