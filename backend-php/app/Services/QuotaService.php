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
        $result = $this->redis->eval(
            '',
            1,
            $this->weekKey($userId),
            (string) $weeklyLimit,
            (string) $this->secondsUntilMonday(),
        );

        return [(bool) $result[0], (int) $result[1], $weeklyLimit];
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    public function checkDailyMinutes(int $userId, int $dailyMinutesLimit): array
    {
        $minutes = intdiv((int) ($this->redis->get($this->dayKey($userId)) ?? 0), 60);
        if ($dailyMinutesLimit === 0) {
            return [true, $minutes, 0];
        }

        return [$minutes < $dailyMinutesLimit, $minutes, $dailyMinutesLimit];
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    public function checkWeeklyMinutes(int $userId, int $weeklyMinutesLimit): array
    {
        $minutes = intdiv((int) ($this->redis->get($this->weeklySecondsKey($userId)) ?? 0), 60);
        if ($weeklyMinutesLimit === 0) {
            return [true, $minutes, 0];
        }

        return [$minutes < $weeklyMinutesLimit, $minutes, $weeklyMinutesLimit];
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    public function checkMonthlyTokens(int $userId, int $monthlyLimit): array
    {
        $used = $this->monthlyTokensUsed($userId);
        if ($monthlyLimit === 0) {
            return [true, $used, 0];
        }

        return [$used < $monthlyLimit, $used, $monthlyLimit];
    }

    /**
     * @return array{0: int, 1: ?string, 2: ?string, 3: ?int}
     */
    public function checkAll(
        int $userId,
        int $monthlyTokensLimit,
        int $dailyMinutesLimit,
        int $weeklyMinutesLimit,
        int $weeklySessionsLimit,
        int $maxDuration,
    ): array {
        if ($monthlyTokensLimit > 0) {
            [$ok, $used, $limit] = $this->checkMonthlyTokens($userId, $monthlyTokensLimit);
            if (! $ok) {
                $message = "Monthly token limit reached ({$used}/{$limit} tokens). Voice is unavailable until next month.";

                return [$maxDuration, 'quota_exceeded_tokens', $message, 1008];
            }
        }

        [$dailyOk, $minutesUsed, $minutesLimit] = $this->checkDailyMinutes($userId, $dailyMinutesLimit);
        if (! $dailyOk) {
            $message = "Daily time limit reached ({$minutesUsed}/{$minutesLimit} min).";

            return [$maxDuration, 'quota_exceeded_time', $message, 1008];
        }

        [$weeklyOk, $weeklyUsed, $weeklyLimit] = $this->checkWeeklyMinutes($userId, $weeklyMinutesLimit);
        if (! $weeklyOk) {
            $message = "Weekly time limit reached ({$weeklyUsed}/{$weeklyLimit} min).";

            return [$maxDuration, 'quota_exceeded_weekly_minutes', $message, 1008];
        }

        [$sessionsOk, $sessionsUsed, $sessionsLimit] = $this->checkAndIncrementSessions($userId, $weeklySessionsLimit);
        if (! $sessionsOk) {
            $message = "Weekly session limit reached ({$sessionsUsed}/{$sessionsLimit}).";

            return [$maxDuration, 'quota_exceeded_sessions', $message, 1008];
        }

        if ($dailyMinutesLimit > 0) {
            $maxDuration = min($maxDuration, ($dailyMinutesLimit - $minutesUsed) * 60);
        }
        if ($weeklyMinutesLimit > 0) {
            $maxDuration = min($maxDuration, ($weeklyMinutesLimit - $weeklyUsed) * 60);
        }

        return [$maxDuration, null, null, null];
    }

    public function recordSessionSeconds(int $userId, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $dayKey = $this->dayKey($userId);
        $weekKey = $this->weeklySecondsKey($userId);
        $this->redis->incrby($dayKey, $seconds);
        $this->redis->incrby($weekKey, $seconds);
        if ($this->redis->ttl($dayKey) < 0) {
            $this->redis->expire($dayKey, $this->secondsUntilMidnight());
        }
        if ($this->redis->ttl($weekKey) < 0) {
            $this->redis->expire($weekKey, $this->secondsUntilMonday());
        }
    }

    public function secondsUntilMonday(): int
    {
        $now = Carbon::now('UTC');
        $daysAhead = 7 - ($now->dayOfWeekIso - 1);
        $nextMonday = $now->copy()->addDays($daysAhead)->startOfDay();

        return max($nextMonday->getTimestamp() - $now->getTimestamp(), 1);
    }

    public function secondsUntilMidnight(): int
    {
        $now = Carbon::now('UTC');
        $tomorrow = $now->copy()->addDay()->startOfDay();

        return max($tomorrow->getTimestamp() - $now->getTimestamp(), 1);
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
