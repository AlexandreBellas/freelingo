<?php

namespace App\Services;

use App\Models\GameAdmission;
use App\Models\User;
use App\Services\Redis\ArrayRedis;
use App\Support\Iso;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class FreemiumService
{
    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function trialActive(mixed $endsAt): bool
    {
        if (! Settings::bool('stripe_enabled') || ! Settings::bool('freemium_trial_enabled') || $endsAt === null) {
            return false;
        }
        $ends = $endsAt instanceof \DateTimeInterface ? Carbon::instance(\DateTime::createFromInterface($endsAt)) : Carbon::parse((string) $endsAt);

        return $ends->greaterThan(Carbon::now('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $chat = $this->remaining($this->redis->get($this->chatKey($user->id)), Settings::int('freemium_chat_daily_messages', 5));
        $lessons = $this->remaining($this->redis->get($this->lessonsKey($user->id)), Settings::int('freemium_lessons_daily', 3));
        $listening = $this->remaining($this->redis->get($this->listeningKey($user->id)), Settings::int('freemium_listening_weekly', 3));
        $reading = $this->remaining($this->redis->get($this->readingKey($user->id)), Settings::int('freemium_reading_weekly', 3));
        $voiceLimit = Settings::int('freemium_voice_weekly_minutes', 5) * 60;
        $voice = $this->remaining($this->redis->get($this->voiceKey($user->id)), $voiceLimit);
        $games = $this->gameQuota($user->id);

        return [
            'games_remaining' => $games['remaining'],
            'games_limit' => $games['limit'],
            'trial_active' => $this->trialActive($user->freemium_trial_ends_at),
            'trial_ends_at' => Iso::string($user->freemium_trial_ends_at),
            'chat_remaining' => $chat['remaining'],
            'chat_limit' => $chat['limit'],
            'lessons_remaining' => $lessons['remaining'],
            'lessons_limit' => $lessons['limit'],
            'listening_remaining' => $listening['remaining'],
            'listening_limit' => $listening['limit'],
            'reading_remaining' => $reading['remaining'],
            'reading_limit' => $reading['limit'],
            'voice_remaining_seconds' => $voice['remaining'],
            'voice_limit_seconds' => $voice['limit'],
        ];
    }

    /**
     * @return array{remaining: int, limit: int}
     */
    public function gameQuota(int $userId): array
    {
        $now = Carbon::now('UTC');
        $used = GameAdmission::query()
            ->where('user_id', $userId)
            ->whereDate('date', $now->toDateString())
            ->where(function ($query) use ($now) {
                $query->where('status', 'consumed')
                    ->orWhere(function ($query) use ($now) {
                        $query->where('status', 'reserved')->where('deadline', '>', $now);
                    });
            })
            ->count();
        $limit = Settings::int('freemium_games_daily', 3);

        return ['remaining' => max(0, $limit - $used), 'limit' => $limit];
    }

    public function record(User $user, string $feature): void
    {
        if (! Settings::bool('stripe_enabled')) {
            return;
        }
        if ($this->subscriptions->isSubscribed($user) || $this->trialActive($user->freemium_trial_ends_at)) {
            return;
        }
        try {
            match ($feature) {
                'chat' => $this->redis->incrby($this->chatKey($user->id), 1),
                'lessons' => $this->redis->incrby($this->lessonsKey($user->id), 1),
                'listening' => $this->redis->incrby($this->listeningKey($user->id), 1),
                'reading' => $this->redis->incrby($this->readingKey($user->id), 1),
                default => null,
            };
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{remaining: int, limit: int}
     */
    private function remaining(?string $used, int $limit): array
    {
        $remaining = max($limit - (int) ($used ?? 0), 0);

        return ['remaining' => $remaining, 'limit' => $limit];
    }

    private function chatKey(int $userId): string
    {
        return 'freemium:chat:'.$userId.':'.Carbon::now('UTC')->format('Y-m-d');
    }

    private function lessonsKey(int $userId): string
    {
        return 'freemium:lessons:'.$userId.':'.Carbon::now('UTC')->format('Y-m-d');
    }

    private function listeningKey(int $userId): string
    {
        return 'freemium:listening:'.$userId.':'.$this->week();
    }

    private function readingKey(int $userId): string
    {
        return 'freemium:reading:'.$userId.':'.$this->week();
    }

    private function voiceKey(int $userId): string
    {
        return 'freemium:voice:'.$userId.':'.$this->week();
    }

    private function week(): string
    {
        $now = Carbon::now('UTC');

        return sprintf('%d-W%02d', $now->isoWeekYear, $now->isoWeek);
    }
}
