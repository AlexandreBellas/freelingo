<?php

namespace App\Services\Prompts;

final class AssessmentPrompts
{
    /** @param array<string, string|int|float> $vars */
    public static function freeWrite(array $vars): string
    {
        $vars['json_only_instruction'] = PromptCatalog::text('JSON_ONLY_INSTRUCTION');

        return PromptCatalog::render('FREE_WRITE_ASSESSMENT_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function endOfLevel(array $vars): string
    {
        $vars['json_only_instruction'] = PromptCatalog::text('JSON_ONLY_INSTRUCTION');

        return PromptCatalog::render('END_OF_LEVEL_TEST_PROMPT', $vars);
    }

    public static function legacyQuiz(string $targetLanguageName, string $overlay): string
    {
        return PromptCatalog::render('LEGACY_ASSESSMENT_QUIZ_PROMPT', [
            'target_language_name' => $targetLanguageName,
            'language_prompt_overlay' => $overlay,
        ]);
    }

    public static function legacyEvalSystem(): string
    {
        return PromptCatalog::text('LEGACY_ASSESSMENT_EVAL_PROMPT');
    }

    public static function legacyEvalUser(string $sessionId, mixed $quiz, mixed $answers): string
    {
        return PromptCatalog::render('LEGACY_ASSESSMENT_EVAL_USER_PROMPT', [
            'session_id' => $sessionId,
            'payload' => json_encode(['quiz' => $quiz, 'answers' => $answers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
