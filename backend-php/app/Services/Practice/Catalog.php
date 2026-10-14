<?php

namespace App\Services\Practice;

final class Catalog
{
    public const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public const COMPLETION_UNIT_ID = 'completion-test';

    public const LEGACY_COMPLETION_UNIT_ID = 'level-test';

    /** @var list<string> */
    public const COMPLETION_UNIT_IDS = ['completion-test', 'level-test'];

    /** @var list<string> */
    public const LESSON_TYPES = ['grammar', 'vocabulary', 'reading', 'writing', 'listening', 'speaking', 'review'];

    /** @var array<string, string> */
    private const MODULES = [
        'en-GB' => 'en-GB',
        'en-US' => 'en-US',
        'de' => 'de',
        'es' => 'es',
        'fr' => 'fr',
        'it' => 'it',
        'ja' => 'ja',
        'ko' => 'ko',
        'pt' => 'pt',
        'zh' => 'zh',
    ];

    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function moduleKey(string $targetLanguage): string
    {
        if (isset(self::MODULES[$targetLanguage])) {
            return self::MODULES[$targetLanguage];
        }
        $prefix = explode('-', $targetLanguage)[0];

        return self::MODULES[$prefix] ?? 'en-GB';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function vocabularySets(string $language = 'en-GB'): array
    {
        return self::load('vocabulary', $language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function vocabularySet(string $id, string $language = 'en-GB'): ?array
    {
        foreach (self::vocabularySets($language) as $set) {
            if ($set['id'] === $id) {
                return $set;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function vocabularyByLevel(string $level, string $language = 'en-GB'): array
    {
        return array_values(array_filter(
            self::vocabularySets($language),
            fn (array $set) => $set['level'] === $level,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function grammarTopics(string $language = 'en-GB'): array
    {
        return self::load('grammar', $language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function grammarTopic(string $slug, string $language = 'en-GB'): ?array
    {
        foreach (self::grammarTopics($language) as $topic) {
            if ($topic['slug'] === $slug) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function phrasebookCategories(string $language = 'en-GB'): array
    {
        return self::load('phrasebook', $language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function phrasebookCategory(string $id, string $language = 'en-GB'): ?array
    {
        foreach (self::phrasebookCategories($language) as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function phrasebookByLevel(string $level, string $language = 'en-GB'): array
    {
        return array_values(array_filter(
            self::phrasebookCategories($language),
            fn (array $category) => $category['level'] === $level,
        ));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function curriculum(string $language = 'en-GB'): array
    {
        /** @var array<string, list<array<string, mixed>>> $data */
        $data = self::load('curriculum', $language);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function curriculumUnits(string $level, string $language = 'en-GB'): array
    {
        return self::curriculum($language)[$level] ?? [];
    }

    /**
     * @return list<array<string, mixed>>|array<string, list<array<string, mixed>>>
     */
    private static function load(string $kind, string $language): array
    {
        $key = self::moduleKey($language);
        $cacheKey = $kind.':'.$key;
        if (! isset(self::$cache[$cacheKey])) {
            $path = resource_path("data/{$kind}/{$key}.json");
            $decoded = json_decode((string) file_get_contents($path), true);
            self::$cache[$cacheKey] = is_array($decoded) ? $decoded : [];
        }

        return self::$cache[$cacheKey];
    }
}
