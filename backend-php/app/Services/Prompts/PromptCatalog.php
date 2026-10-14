<?php

namespace App\Services\Prompts;

use App\Support\PyFormat;

final class PromptCatalog
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public static function get(string $key): mixed
    {
        self::$data ??= json_decode((string) file_get_contents(__DIR__.'/templates.json'), true, 512, JSON_THROW_ON_ERROR);

        return self::$data[$key];
    }

    public static function text(string $key): string
    {
        return (string) self::get($key);
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public static function render(string $key, array $vars): string
    {
        return PyFormat::format(self::text($key), $vars);
    }

    public static function overlay(string $targetLanguage): string
    {
        $aliases = self::get('LANGUAGE_OVERLAY_ALIASES');
        $canonical = $aliases[$targetLanguage] ?? $targetLanguage;
        $overlays = self::get('LANGUAGE_OVERLAYS');

        return (string) ($overlays[$canonical] ?? '');
    }
}
