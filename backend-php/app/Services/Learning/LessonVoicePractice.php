<?php

namespace App\Services\Learning;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\UserLanguage;

final class LessonVoicePractice
{
    /**
     * @return array{0: Lesson, 1: StudyPlan}|null
     */
    public function source(int $userId, int $lessonId): ?array
    {
        $lesson = Lesson::query()
            ->select('lessons.*')
            ->join('study_plans', 'lessons.study_plan_id', '=', 'study_plans.id')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('lessons.id', $lessonId)
            ->where('study_plans.user_id', $userId)
            ->where('user_languages.user_id', $userId)
            ->where('lessons.is_completed', true)
            ->first();
        if (! $lesson) {
            return null;
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        if (! $plan) {
            return null;
        }

        return [$lesson, $plan];
    }

    public function context(int $userId, int $lessonId): ?string
    {
        $row = $this->source($userId, $lessonId);
        if (! $row) {
            return null;
        }
        [$lesson, $plan] = $row;
        $content = is_array($lesson->content) ? $lesson->content : [];
        $objectives = [];
        $schedule = is_array($plan->generated_plan) ? $plan->generated_plan : [];
        foreach ($this->items($schedule['weekly_plan'] ?? null) as $week) {
            if (! is_array($week) || ($week['week'] ?? null) !== $lesson->week_number) {
                continue;
            }
            foreach ($this->items($week['days'] ?? null) as $day) {
                if (is_array($day) && ($day['day'] ?? null) === $lesson->day_number) {
                    $objectives = $this->items($day['objectives'] ?? null);
                    break;
                }
            }
        }
        $exercises = Exercise::query()
            ->where('lesson_id', $lesson->id)
            ->whereNotNull('user_answer')
            ->orderBy('score')
            ->orderBy('id')
            ->limit(8)
            ->get();
        $answered = [];
        $generator = app(LessonGenerator::class);
        foreach ($exercises as $exercise) {
            $normalized = $generator->normalizedText($exercise->exercise_type, $exercise->question, $exercise->explanation);
            $answered[] = $this->bounded([
                'question' => $normalized['question'],
                'explanation' => $normalized['explanation'],
                'answer' => $exercise->user_answer,
                'correct_answer' => $exercise->correct_answer,
                'score' => $exercise->score,
                'feedback' => $exercise->feedback,
                'corrections' => $exercise->corrections,
            ]);
        }
        $data = [
            'title' => $lesson->title,
            'lesson_type' => $lesson->lesson_type,
            'objectives' => $this->bounded($objectives),
            'explanation' => $this->bounded($content['explanation'] ?? null),
            'vocabulary' => $this->bounded($content['vocabulary'] ?? null),
            'grammar_refs' => $this->bounded($content['grammar_refs'] ?? null),
            'answered_exercises' => $answered,
        ];

        return htmlspecialchars(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    private function bounded(mixed $value, int $depth = 0, ?array &$budget = null): mixed
    {
        if ($budget === null) {
            $budget = [3000];
        }
        if (is_string($value)) {
            $excerpt = mb_substr($value, 0, min(600, $budget[0]));
            $budget[0] -= mb_strlen($excerpt);

            return $excerpt;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if ($depth >= 3) {
            return null;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(fn ($item) => $this->bounded($item, $depth + 1, $budget), array_slice($value, 0, 8));
        }
        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 8) {
                    break;
                }
                $out[mb_substr((string) $key, 0, 80)] = $this->bounded($item, $depth + 1, $budget);
                $count++;
            }

            return $out;
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
