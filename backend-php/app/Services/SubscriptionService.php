<?php

namespace App\Services;

use App\Models\User;
use App\Support\Settings;

class SubscriptionService
{
    public function isSubscribed(User $user, ?bool $stripeEnabled = null): bool
    {
        $enabled = $stripeEnabled ?? Settings::bool('stripe_enabled');
        if (! $enabled) {
            return true;
        }

        return in_array($user->subscription_status, ['trialing', 'active'], true);
    }

    public function applyQuotas(User $user): void
    {
        $user->conversation_weekly_sessions = Settings::int('default_conversation_weekly_sessions', 0);
        $user->conversation_weekly_minutes = Settings::int('default_conversation_weekly_minutes', 90);
        $user->conversation_daily_minutes = Settings::int('default_conversation_daily_minutes', 30);
        $user->monthly_tokens_limit = Settings::int('default_monthly_tokens_limit', 1000000);
        $user->conversation_max_duration = Settings::int('default_conversation_max_duration', 1800);
        $user->conversation_inactivity_timeout = Settings::int('default_conversation_inactivity_timeout', 180);
    }
}
