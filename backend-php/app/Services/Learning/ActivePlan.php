<?php

namespace App\Services\Learning;

use App\Exceptions\ApiException;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\UserLanguageService;

final class ActivePlan
{
    public function __construct(private readonly UserLanguageService $languages) {}

    public function language(User $user): ?UserLanguage
    {
        return $this->languages->active($user->id);
    }

    public function optional(User $user): ?StudyPlan
    {
        $language = $this->language($user);
        if (! $language) {
            return null;
        }

        return StudyPlan::query()
            ->where('user_language_id', $language->id)
            ->where('is_active', true)
            ->first();
    }

    public function required(User $user): StudyPlan
    {
        if (! $this->language($user)) {
            throw new ApiException('No active language set', 404);
        }
        $plan = $this->optional($user);
        if (! $plan) {
            throw new ApiException('No active study plan found', 404);
        }

        return $plan;
    }

    public function forLanguage(User $user, string $language): ?StudyPlan
    {
        $row = UserLanguage::query()
            ->where('user_id', $user->id)
            ->where('target_language', $language)
            ->first();
        if (! $row) {
            return null;
        }

        return StudyPlan::query()
            ->where('user_language_id', $row->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();
    }
}
