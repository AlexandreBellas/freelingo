<?php

namespace Tests;

use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use Illuminate\Support\Carbon;

trait LearningHelpers
{
    protected function seedPlan(User $user, array $overrides = []): StudyPlan
    {
        $language = UserLanguage::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return StudyPlan::query()->create(array_merge([
            'user_id' => $user->id,
            'user_language_id' => $language->id,
            'cefr_level' => 'A1',
            'target_language' => $user->target_language,
            'goals' => ['grammar'],
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'current_unit' => '',
            'progress_day' => 0,
            'generated_plan' => ['weekly_plan' => []],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ], $overrides));
    }
}
