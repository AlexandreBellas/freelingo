<?php

namespace App\Services\Learning;

use App\Exceptions\ApiException;

final class RequestChecks
{
    public static function fail(array $loc, string $message = 'Invalid value'): never
    {
        throw new ApiException([[
            'type' => 'value_error',
            'loc' => $loc,
            'msg' => $message,
        ]], 422);
    }

    public static function body(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }

    public static function requireString(array $data, string $field, bool $allowEmpty = true): string
    {
        if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
            self::fail(['body', $field], $field.' is required');
        }
        if (! $allowEmpty && trim($data[$field]) === '') {
            self::fail(['body', $field], $field.' is required');
        }

        return $data[$field];
    }

    public static function optionalString(array $data, string $field, string $default = ''): string
    {
        if (! array_key_exists($field, $data)) {
            return $default;
        }
        if (! is_string($data[$field])) {
            self::fail(['body', $field]);
        }

        return $data[$field];
    }

    public static function maxChars(string $value, int $max, array $loc): void
    {
        if (mb_strlen($value) > $max) {
            self::fail($loc, 'too long');
        }
    }

    public static function strictInt(array $data, string $field, int $min, int $max, array $locPrefix = ['body']): int
    {
        if (! array_key_exists($field, $data) || ! is_int($data[$field]) || $data[$field] < $min || $data[$field] > $max) {
            self::fail([...$locPrefix, $field]);
        }

        return $data[$field];
    }

    public static function intWithDefault(array $data, string $field, int $default, int $min, int $max): int
    {
        if (! array_key_exists($field, $data)) {
            return $default;
        }
        if (! is_int($data[$field]) || $data[$field] < $min || $data[$field] > $max) {
            self::fail(['body', $field]);
        }

        return $data[$field];
    }

    public static function forbidExtra(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                self::fail(['body', (string) $key], 'extra field');
            }
        }
    }

    public static function cefr(array $data, string $field = 'cefr_level'): string
    {
        $value = self::requireString($data, $field, false);
        if (! in_array($value, LearningCatalog::CEFR_LEVELS, true)) {
            self::fail(['body', $field], "Unknown CEFR level '{$value}': expected one of ".implode(', ', LearningCatalog::CEFR_LEVELS));
        }

        return $value;
    }

    /**
     * @return array{word: string, definition: string, example_sentence: string, translation: string}
     */
    public static function flashcardContent(array $data, array $locPrefix = ['body']): array
    {
        foreach (['word', 'definition', 'example_sentence', 'translation'] as $field) {
            if (! array_key_exists($field, $data)) {
                self::fail([...$locPrefix, $field]);
            }
            if ($data[$field] === null && in_array($field, ['example_sentence', 'translation'], true)) {
                $data[$field] = '';
            }
            if (! is_string($data[$field])) {
                self::fail([...$locPrefix, $field]);
            }
        }
        self::maxChars($data['word'], 100, [...$locPrefix, 'word']);
        self::maxChars($data['translation'], 255, [...$locPrefix, 'translation']);

        return [
            'word' => $data['word'],
            'definition' => $data['definition'],
            'example_sentence' => $data['example_sentence'],
            'translation' => $data['translation'],
        ];
    }

    public static function uuid(mixed $value, array $loc): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            self::fail($loc, 'invalid uuid');
        }

        return $value;
    }
}
