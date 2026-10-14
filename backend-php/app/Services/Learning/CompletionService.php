<?php

namespace App\Services\Learning;

use App\Models\Lesson;
use App\Models\StudyPlan;

final class CompletionService
{
    public function totalPlanDays(StudyPlan $plan): int
    {
        return $plan->duration_weeks * $plan->days_per_week;
    }

    public function positionReached(StudyPlan $plan): bool
    {
        $total = $this->totalPlanDays($plan);

        return $total > 0 && $plan->progress_day >= $total - 1;
    }

    public function hasPendingLessons(StudyPlan $plan): bool
    {
        return Lesson::query()
            ->where('study_plan_id', $plan->id)
            ->where('is_completed', false)
            ->whereRaw('((week_number - 1) * ? + (day_number - 1)) < ?', [$plan->days_per_week, $plan->progress_day])
            ->exists();
    }

    public function isLevelTestEligible(StudyPlan $plan, bool $hasPending = false): bool
    {
        return (bool) $plan->completion_test_taken || ($this->positionReached($plan) && ! $hasPending);
    }

    public function nextCefrLevel(string $level): ?string
    {
        $index = array_search($level, LearningCatalog::CEFR_LEVELS, true);
        if ($index === false) {
            return null;
        }
        $next = $index + 1;

        return $next < count(LearningCatalog::CEFR_LEVELS) ? LearningCatalog::CEFR_LEVELS[$next] : null;
    }

    /**
     * @return array{state: string, score: float|null, recommendation: string|null, next_level: string|null}
     */
    public function state(StudyPlan $plan, bool $hasPending = false): array
    {
        if ($plan->completion_test_taken && $this->positionReached($plan)) {
            return [
                'state' => 'taken',
                'score' => $plan->completion_test_score !== null ? (float) $plan->completion_test_score : null,
                'recommendation' => $plan->completion_test_recommendation,
                'next_level' => $plan->completion_test_recommendation === 'advance'
                    ? $this->nextCefrLevel($plan->cefr_level)
                    : null,
            ];
        }
        if ($this->positionReached($plan) && ! $hasPending) {
            return ['state' => 'ready', 'score' => null, 'recommendation' => null, 'next_level' => null];
        }

        return ['state' => 'in_progress', 'score' => null, 'recommendation' => null, 'next_level' => null];
    }
}
