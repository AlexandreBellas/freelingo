<?php

namespace App\Services\Practice;

use App\Exceptions\ApiException;
use App\Models\StudyPlan;
use App\Models\User;
use App\Services\Redis\ArrayRedis;
use App\Services\UserLanguageService;
use App\Support\Settings;
use Illuminate\Support\Carbon;

final class Access
{
    public const MAINTENANCE_KEY = 'maintenance_mode';

    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly UserLanguageService $languages,
    ) {
    }

    public static function make(): self
    {
        return app(self::class);
    }

    public function isSubscribed(User $user): bool
    {
        if (! Settings::bool('stripe_enabled')) {
            return true;
        }

        return in_array($user->subscription_status, ['trialing', 'active'], true);
    }

    public function freemiumTrialActive(User $user): bool
    {
        if (! Settings::bool('stripe_enabled') || ! Settings::bool('freemium_trial_enabled')) {
            return false;
        }
        if ($user->freemium_trial_ends_at === null) {
            return false;
        }

        return Carbon::parse($user->freemium_trial_ends_at)->utc()->greaterThan(Carbon::now('UTC'));
    }

    public function assertNotMaintenance(User $user): void
    {
        if ($user->role === 'admin') {
            return;
        }
        if ($this->redis->get(self::MAINTENANCE_KEY) === '1') {
            throw new ApiException('Service temporarily unavailable — maintenance mode is active', 503);
        }
    }

    public function activePlan(User $user): StudyPlan
    {
        $plan = $this->activePlanOptional($user);
        if (! $plan) {
            $active = $this->languages->active($user->id);
            if (! $active) {
                throw new ApiException('No active language set', 404);
            }
            throw new ApiException('No active study plan found', 404);
        }

        return $plan;
    }

    public function activePlanOptional(User $user): ?StudyPlan
    {
        $active = $this->languages->active($user->id);
        if (! $active) {
            return null;
        }

        return StudyPlan::query()
            ->where('user_language_id', $active->id)
            ->where('is_active', true)
            ->first();
    }

    public function assertStudyContext(StudyPlan $plan, ?int $studyPlanId, ?string $language, ?string $level): StudyPlan
    {
        $expected = [$studyPlanId, $language, $level];
        $actual = [$plan->id, $plan->target_language, $plan->cefr_level];
        foreach ($expected as $index => $value) {
            if ($value !== null && $value !== $actual[$index]) {
                throw new ApiException('study_context_changed', 409);
            }
        }

        return $plan;
    }

    public function assertFeature(User $user, string $feature, bool $readonly = false): void
    {
        if (! Settings::bool('stripe_enabled') || $this->isSubscribed($user) || $this->freemiumTrialActive($user)) {
            return;
        }
        $quota = $this->featureQuota($user->id, $feature);
        if ($quota === null) {
            throw new ApiException('subscription_required', 402);
        }
        if ($readonly) {
            if ($quota['limit'] === 0) {
                throw new ApiException('subscription_required', 402);
            }

            return;
        }
        if (! $quota['allowed']) {
            throw new ApiException([
                'reason' => 'freemium_exhausted',
                'feature' => $feature,
                'remaining' => $quota['remaining'],
                'limit' => $quota['limit'],
            ], 402);
        }
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int}|null
     */
    public function featureQuota(int $userId, string $feature): ?array
    {
        $map = [
            'chat' => [$this->dayKey('freemium:chat', $userId), Settings::int('freemium_chat_daily_messages', 5)],
            'lessons' => [$this->dayKey('freemium:lessons', $userId), Settings::int('freemium_lessons_daily', 3)],
            'listening' => [$this->weekKey('freemium:listening', $userId), Settings::int('freemium_listening_weekly', 3)],
            'reading' => [$this->weekKey('freemium:reading', $userId), Settings::int('freemium_reading_weekly', 3)],
            'voice' => [$this->weekKey('freemium:voice', $userId), Settings::int('freemium_voice_weekly_minutes', 5) * 60],
        ];
        if (! isset($map[$feature])) {
            return null;
        }
        [$key, $limit] = $map[$feature];
        $used = (int) ($this->redis->get($key) ?? 0);
        $remaining = max($limit - $used, 0);

        return ['allowed' => $remaining > 0, 'remaining' => $remaining, 'limit' => $limit];
    }

    public function recordFeature(User $user, string $feature): void
    {
        if (! Settings::bool('stripe_enabled') || $this->isSubscribed($user) || $this->freemiumTrialActive($user)) {
            return;
        }
        $key = match ($feature) {
            'chat' => $this->dayKey('freemium:chat', $user->id),
            'lessons' => $this->dayKey('freemium:lessons', $user->id),
            'listening' => $this->weekKey('freemium:listening', $user->id),
            'reading' => $this->weekKey('freemium:reading', $user->id),
            default => null,
        };
        if ($key === null) {
            return;
        }
        $this->redis->incr($key);
    }

    /**
     * @return array{0: bool, 1: bool, 2: ?array<string, mixed>, 3: int}
     */
    public function voiceAccess(User $user, ?string $trialToken = null): array
    {
        if ($this->isSubscribed($user) || $this->freemiumTrialActive($user)) {
            return [true, false, null, 0];
        }
        $quota = $this->featureQuota($user->id, 'voice');
        if ($quota && $quota['allowed']) {
            return [true, true, null, $quota['remaining']];
        }
        $trial = $this->validateVoiceTrial($user, $trialToken);
        if ($trial) {
            return [true, false, $trial, 0];
        }

        return [false, false, null, 0];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateVoiceTrial(User $user, ?string $token): ?array
    {
        if (! $token || $this->isSubscribed($user) || $user->assessment_voice_trial_used) {
            return null;
        }
        if (! Settings::bool('stripe_enabled')) {
            return null;
        }
        $raw = $this->redis->get("assessment_voice_trial:{$user->id}:{$token}");
        if (! $raw) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! isset($payload['user_id'], $payload['plan_id'])) {
            $this->redis->delete("assessment_voice_trial:{$user->id}:{$token}");

            return null;
        }

        return [
            'token' => $token,
            'user_id' => (int) $payload['user_id'],
            'plan_id' => (int) $payload['plan_id'],
            'target_language' => (string) ($payload['target_language'] ?? ''),
            'cefr_level' => (string) ($payload['cefr_level'] ?? ''),
            'duration_seconds' => (int) ($payload['duration_seconds'] ?? Settings::int('assessment_voice_trial_duration_seconds', 300)),
        ];
    }

    public function consumeVoiceTrial(User $user, string $token): void
    {
        $this->redis->delete("assessment_voice_trial:{$user->id}:{$token}");
        $user->assessment_voice_trial_used = true;
        $user->save();
    }

    /**
     * @return array{0: int, 1: ?string, 2: ?string, 3: ?int}
     */
    public function checkConversationQuotas(User $user, int $maxDuration): array
    {
        if ((int) $user->monthly_tokens_limit > 0) {
            [$allowed, $used, $limit] = $this->monthlyTokens($user->id, (int) $user->monthly_tokens_limit);
            if (! $allowed) {
                return [$maxDuration, 'quota_exceeded_tokens', "Monthly token limit reached ({$used}/{$limit} tokens). Voice is unavailable until next month.", 1008];
            }
        }
        $dailySeconds = (int) ($this->redis->get($this->dayKey('quota:minutes', $user->id)) ?? 0);
        $dailyMinutes = intdiv($dailySeconds, 60);
        $dailyLimit = (int) $user->conversation_daily_minutes;
        if ($dailyLimit !== 0 && $dailyMinutes >= $dailyLimit) {
            return [$maxDuration, 'quota_exceeded_time', "Daily time limit reached ({$dailyMinutes}/{$dailyLimit} min).", 1008];
        }
        $weeklySeconds = (int) ($this->redis->get($this->weekKey('quota:weekly-seconds', $user->id)) ?? 0);
        $weeklyMinutes = intdiv($weeklySeconds, 60);
        $weeklyLimit = (int) $user->conversation_weekly_minutes;
        if ($weeklyLimit !== 0 && $weeklyMinutes >= $weeklyLimit) {
            return [$maxDuration, 'quota_exceeded_weekly_minutes', "Weekly time limit reached ({$weeklyMinutes}/{$weeklyLimit} min).", 1008];
        }
        $sessionLimit = (int) $user->conversation_weekly_sessions;
        if ($sessionLimit !== 0) {
            $key = $this->weekKey('quota:sessions', $user->id);
            $current = (int) ($this->redis->get($key) ?? 0);
            if ($current >= $sessionLimit) {
                return [$maxDuration, 'quota_exceeded_sessions', "Weekly session limit reached ({$current}/{$sessionLimit}).", 1008];
            }
            $this->redis->incr($key);
        }

        return [$maxDuration, null, null, null];
    }

    public function recordSessionSeconds(int $userId, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $this->redis->incrby($this->dayKey('quota:minutes', $userId), $seconds);
        $this->redis->incrby($this->weekKey('quota:weekly-seconds', $userId), $seconds);
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    public function monthlyTokens(int $userId, int $limit): array
    {
        $start = Carbon::now('UTC')->startOfMonth();
        $used = (int) \App\Models\LlmUsage::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $start)
            ->sum('total_tokens');
        if ($limit === 0) {
            return [true, $used, 0];
        }

        return [$used < $limit, $used, $limit];
    }

    private function dayKey(string $prefix, int $userId): string
    {
        return $prefix.':'.$userId.':'.Carbon::now('UTC')->format('Y-m-d');
    }

    private function weekKey(string $prefix, int $userId): string
    {
        $now = Carbon::now('UTC');

        return $prefix.':'.$userId.':'.$now->format('o').'-W'.$now->format('W');
    }
}
