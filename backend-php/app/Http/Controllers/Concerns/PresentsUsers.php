<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;

trait PresentsUsers
{
    /**
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'role' => $user->role,
            'native_language' => $user->native_language,
            'target_language' => $user->target_language,
            'ui_locale' => $user->ui_locale,
            'is_active' => (bool) $user->is_active,
            'is_verified' => (bool) $user->is_verified,
            'conversation_max_duration' => (int) $user->conversation_max_duration,
            'conversation_inactivity_timeout' => (int) $user->conversation_inactivity_timeout,
            'conversation_speech_pause' => (int) $user->conversation_speech_pause,
            'avatar' => $user->avatar,
            'bio' => $user->bio,
            'learning_goals' => $this->learningGoals($user),
            'subscription_status' => $user->subscription_status,
            'subscription_ends_at' => $this->iso($user->subscription_ends_at),
            'cancel_at_period_end' => (bool) $user->cancel_at_period_end,
            'trial_used' => (bool) $user->trial_used,
            'assessment_voice_trial_used' => (bool) $user->assessment_voice_trial_used,
            'freemium_trial_ends_at' => $this->iso($user->freemium_trial_ends_at),
            'freemium_trial_used' => (bool) $user->freemium_trial_used,
            'dismissed_dashboard_banner_revision' => $user->dismissed_dashboard_banner_revision,
            'created_at' => $this->iso($user->created_at),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function learningGoals(User $user): ?array
    {
        $value = $user->learning_goals;
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value)) {
            $parsed = json_decode($value, true);
            if (is_array($parsed)) {
                return array_values($parsed);
            }
        }

        return null;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $formatted = $value->format('Y-m-d\TH:i:s');
            $micro = $value->format('u');
            if ((int) $micro !== 0) {
                $formatted .= '.'.rtrim($micro, '0');
            }

            return $formatted;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s');
    }
}
