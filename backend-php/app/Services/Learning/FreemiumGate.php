<?php

namespace App\Services\Learning;

use App\Models\User;
use App\Services\Redis\ArrayRedis;
use App\Support\Clock;
use App\Support\Settings;
use Illuminate\Support\Carbon;

final class FreemiumGate
{
    public function __construct(private readonly ArrayRedis $redis) {}

    public function assertLessons(User $user): void
    {
        $this->assertFeature($user, 'lessons');
    }

    public function recordLesson(User $user): void
    {
        $this->record($user, 'lessons');
    }

    public function assertFeature(User $user, string $feature): void
    {
        if (! Settings::bool('stripe_enabled')) {
            return;
        }
        if ($this->subscribed($user)) {
            return;
        }
        if ($this->trialActive($user)) {
            return;
        }
        $result = $this->quota($feature, $user->id);
        if ($result === null) {
            throw new \App\Exceptions\ApiException('subscription_required', 402);
        }
        if (! $result['allowed']) {
            throw new \App\Exceptions\ApiException([
                'reason' => 'freemium_exhausted',
                'feature' => $feature,
                'remaining' => $result['remaining'],
                'limit' => $result['limit'],
            ], 402);
        }
    }

    public function record(User $user, string $feature): void
    {
        if (! Settings::bool('stripe_enabled') || $this->subscribed($user) || $this->trialActive($user)) {
            return;
        }
        if (! in_array($feature, ['chat', 'lessons', 'listening', 'reading'], true)) {
            return;
        }
        $key = $this->key($feature, $user->id);
        $this->redis->incr($key);
    }

    private function subscribed(User $user): bool
    {
        if (! Settings::bool('stripe_enabled')) {
            return true;
        }

        return in_array($user->subscription_status, ['trialing', 'active'], true);
    }

    private function trialActive(User $user): bool
    {
        if (! Settings::bool('stripe_enabled') || ! Settings::bool('freemium_trial_enabled')) {
            return false;
        }
        if (! $user->freemium_trial_ends_at) {
            return false;
        }

        return Carbon::parse($user->freemium_trial_ends_at)->utc()->greaterThan(Clock::now());
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int}|null
     */
    private function quota(string $feature, int $userId): ?array
    {
        $limits = [
            'chat' => Settings::int('freemium_chat_daily_messages', 5),
            'lessons' => Settings::int('freemium_lessons_daily', 3),
            'listening' => Settings::int('freemium_listening_weekly', 3),
            'reading' => Settings::int('freemium_reading_weekly', 3),
            'voice' => Settings::int('freemium_voice_weekly_minutes', 5) * 60,
        ];
        if (! isset($limits[$feature])) {
            return null;
        }
        $limit = $limits[$feature];
        $used = (int) ($this->redis->get($this->key($feature, $userId)) ?? 0);
        $remaining = max($limit - $used, 0);

        return ['allowed' => $remaining > 0, 'remaining' => $remaining, 'limit' => $limit];
    }

    private function key(string $feature, int $userId): string
    {
        $now = Clock::now();
        if (in_array($feature, ['chat', 'lessons'], true)) {
            return 'freemium:'.$feature.':'.$userId.':'.$now->format('Y-m-d');
        }
        if ($feature === 'voice') {
            return sprintf('freemium:voice:%d:%d-W%02d', $userId, $now->isoWeekYear, $now->isoWeek);
        }

        return sprintf('freemium:%s:%d:%d-W%02d', $feature, $userId, $now->isoWeekYear, $now->isoWeek);
    }
}
