<?php

namespace App\Services\Learning;

use App\Models\User;
use App\Services\Redis\ArrayRedis;
use App\Support\Settings;

final class AssessmentVoiceTrialService
{
    private const PREFIX = 'assessment_voice_trial';

    public function __construct(private readonly ArrayRedis $redis) {}

    public function duration(): int
    {
        return Settings::int('assessment_voice_trial_duration_seconds', 300);
    }

    public function availableFor(?string $subscriptionStatus, bool $used, bool $stripeEnabled): bool
    {
        if (! $stripeEnabled) {
            return false;
        }

        return ! in_array($subscriptionStatus, ['trialing', 'active'], true) && ! $used;
    }

    public function available(User $user, bool $stripeEnabled): bool
    {
        if (! $stripeEnabled) {
            return false;
        }

        return ! in_array($user->subscription_status, ['trialing', 'active'], true) && ! $user->assessment_voice_trial_used;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $userId,
        ?string $subscriptionStatus,
        bool $used,
        int $planId,
        string $targetLanguage,
        string $cefrLevel,
    ): array {
        $stripe = Settings::bool('stripe_enabled');
        if (! $this->availableFor($subscriptionStatus, $used, $stripe)) {
            return ['available' => false];
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload = [
            'user_id' => $userId,
            'plan_id' => $planId,
            'target_language' => $targetLanguage,
            'cefr_level' => $cefrLevel,
            'duration_seconds' => $this->duration(),
        ];
        $this->redis->setex($this->key($userId, $token), 86400, json_encode($payload));

        return [
            'available' => true,
            'token' => $token,
            'duration_seconds' => $this->duration(),
            'expires_in_seconds' => 86400,
        ];
    }

    private function key(int $userId, string $token): string
    {
        return self::PREFIX.':'.$userId.':'.$token;
    }
}
