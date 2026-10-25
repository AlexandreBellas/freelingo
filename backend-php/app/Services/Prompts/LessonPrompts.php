<?php

namespace App\Services\Prompts;

final class LessonPrompts
{
    public static function grammarExerciseRatio(string $lessonType): int
    {
        $ratios = PromptCatalog::get('GRAMMAR_EXERCISE_RATIO');
        $key = strtolower(trim($lessonType));

        return (int) ($ratios[$key] ?? PromptCatalog::get('DEFAULT_GRAMMAR_EXERCISE_RATIO'));
    }

    public static function lessonTypeGuidance(string $lessonType): string
    {
        $map = PromptCatalog::get('LESSON_TYPE_GUIDANCE');
        $key = strtolower(trim($lessonType));
        $guidance = $map[$key] ?? PromptCatalog::text('GENERIC_LESSON_TYPE_GUIDANCE');

        return PromptCatalog::render('LESSON_TYPE_GUIDANCE_TEMPLATE', [
            'lesson_type' => $lessonType !== '' ? $lessonType : 'generic',
            'guidance' => $guidance,
        ]);
    }

    public static function previousLessonsBlock(string $summary, string $lessonType): string
    {
        if (trim($summary) === '') {
            return '';
        }
        $review = strtolower(trim($lessonType)) === 'review';

        return PromptCatalog::render('PREVIOUS_LESSONS_TEMPLATE', [
            'previous_lessons' => trim($summary),
            'reuse_policy' => PromptCatalog::text($review ? 'PREVIOUS_LESSONS_REUSE_POLICY_REVIEW' : 'PREVIOUS_LESSONS_REUSE_POLICY'),
        ]);
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public static function lessonGeneration(array $vars): string
    {
        $lessonType = (string) $vars['lesson_type'];
        $vars['lesson_type_guidance'] = self::lessonTypeGuidance($lessonType);
        $vars['grammar_exercise_ratio'] = self::grammarExerciseRatio($lessonType);
        $vars['previous_lessons_block'] = self::previousLessonsBlock((string) ($vars['previous_lessons_summary'] ?? ''), $lessonType);

        return PromptCatalog::render('LESSON_GENERATION_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function fillBlankEval(array $vars): string
    {
        return PromptCatalog::render('FILL_BLANK_EVAL_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function freeWriteEval(array $vars): string
    {
        return PromptCatalog::render('FREE_WRITE_EVAL_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function pronunciationEval(array $vars): string
    {
        return PromptCatalog::render('PRONUNCIATION_EVAL_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function regenerateExercise(array $vars): string
    {
        return PromptCatalog::render('REGENERATE_EXERCISE_PROMPT', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function nativeExplanation(array $vars): string
    {
        return PromptCatalog::render('NATIVE_EXPLANATION_ON_DEMAND', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function nativeExerciseExplanation(array $vars): string
    {
        return PromptCatalog::render('NATIVE_EXERCISE_EXPLANATION_ON_DEMAND', $vars);
    }

    /** @param array<string, string|int|float> $vars */
    public static function nativeExerciseHint(array $vars): string
    {
        return PromptCatalog::render('NATIVE_EXERCISE_HINT_ON_DEMAND', $vars);
    }
}
