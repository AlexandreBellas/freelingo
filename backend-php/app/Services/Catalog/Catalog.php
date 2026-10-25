<?php

namespace App\Services\Catalog;

final class Catalog
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @var list<string> */
    public const LANGUAGES = ['en-GB', 'en-US', 'de', 'es', 'fr', 'it', 'ja', 'ko', 'pt', 'zh'];

    /** @var list<string> */
    public const CEFR_LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public const COMPLETION_UNIT_ID = 'completion-test';

    public const LEGACY_COMPLETION_UNIT_ID = 'level-test';

    /**
     * @return array<string, mixed>
     */
    public static function language(string $targetLanguage): array
    {
        $key = self::resolve($targetLanguage);
        if (! isset(self::$cache[$key])) {
            $path = base_path('resources/data/'.str_replace('-', '_', $key).'.json');
            $decoded = json_decode((string) file_get_contents($path), true);
            self::$cache[$key] = is_array($decoded) ? $decoded : [];
        }

        return self::$cache[$key];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function grammar(string $targetLanguage = 'en-GB'): array
    {
        return self::language($targetLanguage)['grammar'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function grammarTopic(string $slug, string $targetLanguage = 'en-GB'): ?array
    {
        foreach (self::grammar($targetLanguage) as $topic) {
            if (($topic['slug'] ?? null) === $slug) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function vocabulary(string $targetLanguage = 'en-GB'): array
    {
        return self::language($targetLanguage)['vocabulary'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function vocabularyByLevel(string $level, string $targetLanguage = 'en-GB'): array
    {
        return array_values(array_filter(
            self::vocabulary($targetLanguage),
            fn (array $set) => ($set['level'] ?? null) === $level,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function vocabularySet(string $id, string $targetLanguage = 'en-GB'): ?array
    {
        foreach (self::vocabulary($targetLanguage) as $set) {
            if (($set['id'] ?? null) === $id) {
                return $set;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function phrasebook(string $targetLanguage = 'en-GB'): array
    {
        return self::language($targetLanguage)['phrasebook'] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function curriculum(string $targetLanguage = 'en-GB'): array
    {
        return self::language($targetLanguage)['curriculum'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function curriculumUnits(string $level, string $targetLanguage = 'en-GB'): array
    {
        return self::curriculum($targetLanguage)[$level] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function assessment(string $targetLanguage = 'en-GB'): array
    {
        return self::language($targetLanguage)['assessment'] ?? [];
    }

    public static function resolve(string $targetLanguage): string
    {
        if (in_array($targetLanguage, self::LANGUAGES, true)) {
            return $targetLanguage;
        }
        $prefix = explode('-', $targetLanguage)[0];
        if (in_array($prefix, self::LANGUAGES, true)) {
            return $prefix;
        }

        return 'en-GB';
    }
}
