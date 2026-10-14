<?php

namespace App\Services\Learning;

use App\Exceptions\ApiException;
use App\Models\Exercise;
use App\Models\Flashcard;
use App\Models\Lesson;
use App\Models\Progress;
use App\Models\StudyPlan;
use App\Support\JsonDate;

final class Presenters
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(StudyPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'user_id' => $plan->user_id,
            'cefr_level' => $plan->cefr_level,
            'goals' => $plan->goals ?? [],
            'duration_weeks' => $plan->duration_weeks,
            'days_per_week' => $plan->days_per_week,
            'current_unit' => $plan->current_unit,
            'progress_day' => $plan->progress_day,
            'generated_plan' => $plan->generated_plan ?? new \stdClass,
            'is_active' => (bool) $plan->is_active,
            'completion_test_taken' => (bool) $plan->completion_test_taken,
            'completion_test_score' => $plan->completion_test_score !== null ? (float) $plan->completion_test_score : null,
            'completion_test_recommendation' => $plan->completion_test_recommendation,
            'created_at' => JsonDate::dateTime($plan->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lesson(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'study_plan_id' => $lesson->study_plan_id,
            'title' => $lesson->title,
            'lesson_type' => $lesson->lesson_type,
            'cefr_level' => $lesson->cefr_level,
            'week_number' => $lesson->week_number,
            'day_number' => $lesson->day_number,
            'content' => $lesson->content ?? new \stdClass,
            'is_completed' => (bool) $lesson->is_completed,
            'completed_at' => JsonDate::dateTime($lesson->completed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exercise(Exercise $exercise, ?string $question = null, ?string $explanation = null, ?string $nativeExplanation = null, ?string $nativeHint = null): array
    {
        return [
            'id' => $exercise->id,
            'lesson_id' => $exercise->lesson_id,
            'exercise_type' => $exercise->exercise_type,
            'question' => $question ?? $exercise->question,
            'options' => $exercise->options,
            'correct_answer' => $exercise->correct_answer,
            'user_answer' => $exercise->user_answer,
            'score' => $exercise->score !== null ? (float) $exercise->score : null,
            'feedback' => $exercise->feedback,
            'corrections' => $exercise->corrections,
            'explanation' => $explanation ?? $exercise->explanation,
            'native_explanation' => $nativeExplanation,
            'native_hint' => $nativeHint,
            'answered_at' => JsonDate::dateTime($exercise->answered_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function progress(Progress $entry): array
    {
        $skills = $entry->skills ?? [];

        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'date' => JsonDate::date($entry->date),
            'xp_earned' => $entry->xp_earned,
            'lessons_completed' => $entry->lessons_completed,
            'exercises_correct' => $entry->exercises_correct,
            'exercises_total' => $entry->exercises_total,
            'streak_day' => $entry->streak_day,
            'skills' => $skills === [] ? new \stdClass : $skills,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Flashcard $card, array $extra = []): array
    {
        return array_merge([
            'id' => $card->id,
            'user_id' => $card->user_id,
            'study_plan_id' => $card->study_plan_id,
            'word' => $card->word,
            'definition' => $card->definition,
            'example_sentence' => $card->example_sentence,
            'translation' => $card->translation,
            'source' => $card->source,
            'is_suspended' => (bool) $card->is_suspended,
            'ease_factor' => (float) $card->ease_factor,
            'interval' => (int) $card->interval,
            'repetitions' => (int) $card->repetitions,
            'next_review' => JsonDate::date($card->next_review),
            'created_at' => JsonDate::dateTime($card->created_at),
        ], $extra);
    }

    public static function user(mixed $requestUser): \App\Models\User
    {
        if (! $requestUser instanceof \App\Models\User) {
            throw new ApiException('Not authenticated', 401);
        }

        return $requestUser;
    }
}
