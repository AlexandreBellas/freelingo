<?php

namespace App\Services;

use App\Models\LlmUsage;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;

class QuotaService
{
    public function __construct(private readonly ArrayRedis $redis) {}

    /**
     * @return array<string, int|bool>
     */
    public function status(int $userId, int $weeklyLimit, int $dailyMinutesLimit, int $weeklyMinutesLimit = 0): array
    {
        $sessions = (int) ($this->redis->get($this->weekKey($userId)) ?? 0);
        $seconds = (int) ($this->redis->get($this->dayKey($userId)) ?? 0);
        $weeklySeconds = (int) ($this->redis->get($this->weeklySecondsKey($userId)) ?? 0);
        $minutes = intdiv($seconds, 60);
        $weeklyMinutes = intdiv($weeklySeconds, 60);

        return [
            'sessions_this_week' => $sessions,
            'sessions_limit' => $weeklyLimit,
            'sessions_unlimited' => $weeklyLimit === 0,
            'minutes_today' => $minutes,
            'minutes_limit' => $dailyMinutesLimit,
            'time_unlimited' => $dailyMinutesLimit === 0,
            'minutes_this_week' => $weeklyMinutes,
            'weekly_minutes_limit' => $weeklyMinutesLimit,
            'weekly_minutes_unlimited' => $weeklyMinutesLimit === 0,
        ];
    }

    public function monthlyTokensUsed(int $userId): int
    {
        $start = Carbon::now('UTC')->startOfMonth();

        return (int) LlmUsage::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $start)
            ->sum('total_tokens');
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    public function checkAndIncrementSessions(int $userId, int $weeklyLimit): array
    {
        if ($weeklyLimit === 0) {
            return [true, 0, 0];
        }
        $result = $this->redis->eval('', 1, $this->weekKey($userId), (string) $weeklyLimit);

        return [(bool) $result[0], (int) $result[1], $weeklyLimit];
    }

    public function recordSessionSeconds(int $userId, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $this->redis->incrby($this->dayKey($userId), $seconds);
        $this->redis->incrby($this->weeklySecondsKey($userId), $seconds);
    }

    private function weekKey(int $userId): string
    {
        $now = Carbon::now('UTC');

        return sprintf('conv_sessions:%d:%d-W%02d', $userId, $now->isoWeekYear, $now->isoWeek);
    }

    private function dayKey(int $userId): string
    {
        return 'conv_seconds:'.$userId.':'.Carbon::now('UTC')->format('Y-m-d');
    }

    private function weeklySecondsKey(int $userId): string
    {
        $now = Carbon::now('UTC');

        return sprintf('conv_weekly_seconds:%d:%d-W%02d', $userId, $now->isoWeekYear, $now->isoWeek);
    }
}
