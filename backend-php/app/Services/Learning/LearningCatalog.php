<?php

namespace App\Services\Learning;

final class LearningCatalog
{
    public const CEFR_LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public const COMPLETION_UNIT_IDS = ['completion-test', 'level-test'];

    public const LESSON_TYPES = ['grammar', 'vocabulary', 'reading', 'writing', 'listening', 'speaking', 'review'];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function curriculum(string $targetLanguage): array
    {
        return self::load('curriculum', $targetLanguage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function units(string $level, string $targetLanguage): array
    {
        $curriculum = self::curriculum($targetLanguage);

        return $curriculum[$level] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function grammarSlugs(string $targetLanguage): array
    {
        $slugs = [];
        foreach (self::curriculum($targetLanguage) as $units) {
            foreach ($units as $unit) {
                foreach ($unit['grammar_points'] ?? [] as $slug) {
                    $slugs[$slug] = true;
                }
            }
        }

        return array_keys($slugs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function assessmentBank(string $targetLanguage): array
    {
        $decoded = self::loadList('assessment', $targetLanguage);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    public static function vocabularyWords(string $level, string $targetLanguage): array
    {
        $byLevel = self::load('vocabulary', $targetLanguage);

        return $byLevel[$level] ?? [];
    }

    public static function languageName(string $targetLanguage): string
    {
        return self::languageInfo()[$targetLanguage]['name'] ?? $targetLanguage;
    }

    public static function nativeLanguageName(string $nativeLanguage): string
    {
        return self::nativeNames()[$nativeLanguage] ?? $nativeLanguage;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(string $kind, string $language): array
    {
        $key = $kind.':'.$language;
        if (! isset(self::$cache[$key])) {
            $path = self::path($kind, $language);
            self::$cache[$key] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$cache[$key];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadList(string $kind, string $language): array
    {
        return self::load($kind, $language);
    }

    private static function path(string $kind, string $language): string
    {
        $base = dirname(__DIR__, 2).'/Data/'.$kind;
        $candidates = [$language];
        if (str_contains($language, '-')) {
            $candidates[] = explode('-', $language, 2)[0];
        }
        $candidates[] = 'en-GB';
        foreach ($candidates as $candidate) {
            $path = $base.'/'.$candidate.'.json';
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('Missing '.$kind.' data for '.$language);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function languageInfo(): array
    {
        return [
            'en-US' => ['name' => 'English (US)'],
            'en-GB' => ['name' => 'English (UK)'],
            'de-DE' => ['name' => 'German'],
            'es-ES' => ['name' => 'Spanish (Spain)'],
            'fr-FR' => ['name' => 'French'],
            'it-IT' => ['name' => 'Italian'],
            'pt-PT' => ['name' => 'European Portuguese'],
            'ja-JP' => ['name' => 'Japanese'],
            'ko-KR' => ['name' => 'Korean (South Korea)'],
            'zh-CN' => ['name' => 'Chinese (Mainland China)'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function nativeNames(): array
    {
        return [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'pt' => 'Portuguese',
            'de' => 'German', 'it' => 'Italian', 'pl' => 'Polish', 'nl' => 'Dutch',
            'ro' => 'Romanian', 'ru' => 'Russian', 'tr' => 'Turkish', 'sv' => 'Swedish',
            'da' => 'Danish', 'fi' => 'Finnish', 'hr' => 'Croatian',
        ];
    }
}
