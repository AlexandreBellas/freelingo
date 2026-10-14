<?php

namespace App\Services\Prompts;

final class FlashcardPrompts
{
    /** @param array<string, string|int|float> $vars */
    public static function generation(array $vars): string
    {
        $vars['language_prompt_overlay'] = (string) ($vars['language_prompt_overlay'] ?: ($vars['lang_hint'] ?? ''));

        return PromptCatalog::render('FLASHCARD_GEN_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function lookup(array $vars): string
    {
        $vars['language_prompt_overlay'] = (string) ($vars['language_prompt_overlay'] ?: ($vars['lang_hint'] ?? ''));

        return PromptCatalog::render('WORD_LOOKUP_PROMPT', $vars);
    }
}
