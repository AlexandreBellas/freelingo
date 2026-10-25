<?php

namespace Tests\Unit;

use App\Models\LlmUsage;
use App\Services\QuotaService;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotaServiceTest extends TestCase
{
    private ArrayRedis $redis;

    private QuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new ArrayRedis;
        $this->quota = new QuotaService($this->redis);
    }

    public function test_keys_and_status_start_at_zero(): void
    {
        $now = Carbon::now('UTC');
        $status = $this->quota->status(42, 5, 30, 90);
        $this->assertSame(0, $status['sessions_this_week']);
        $this->assertSame(5, $status['sessions_limit']);
        $this->assertFalse($status['sessions_unlimited']);
        $this->assertSame(0, $status['minutes_today']);
        $this->assertSame(30, $status['minutes_limit']);
        $this->assertFalse($status['time_unlimited']);
        $this->assertSame(0, $status['minutes_this_week']);
        $this->assertSame(90, $status['weekly_minutes_limit']);
        $this->assertFalse($status['weekly_minutes_unlimited']);
        $this->assertSame(
            sprintf('conv_sessions:42:%d-W%02d', $now->isoWeekYear, $now->isoWeek),
            sprintf('conv_sessions:42:%d-W%02d', $now->isoWeekYear, $now->isoWeek),
        );
        $this->assertGreaterThanOrEqual(1, $this->quota->secondsUntilMidnight());
        $this->assertGreaterThanOrEqual(1, $this->quota->secondsUntilMonday());
    }

    public function test_zero_limits_are_unlimited_and_do_not_increment_sessions(): void
    {
        [$allowed, $used, $limit] = $this->quota->checkAndIncrementSessions(7, 0);
        $this->assertTrue($allowed);
        $this->assertSame(0, $used);
        $this->assertSame(0, $limit);

        [$dailyAllowed, $minutes, $dailyLimit] = $this->quota->checkDailyMinutes(7, 0);
        $this->assertTrue($dailyAllowed);
        $this->assertSame(0, $minutes);
        $this->assertSame(0, $dailyLimit);
    }

    public function test_sessions_increment_until_the_weekly_limit(): void
    {
        [$first, $before, $limit] = $this->quota->checkAndIncrementSessions(3, 2);
        $this->assertTrue($first);
        $this->assertSame(0, $before);
        $this->assertSame(2, $limit);
        $this->assertGreaterThan(0, $this->redis->ttl($this->sessionKey(3)));

        [$second] = $this->quota->checkAndIncrementSessions(3, 2);
        $this->assertTrue($second);
        [$blocked, $used] = $this->quota->checkAndIncrementSessions(3, 2);
        $this->assertFalse($blocked);
        $this->assertSame(2, $used);
        $this->assertSame('2', $this->redis->get($this->sessionKey(3)));
    }

    public function test_minutes_block_at_the_boundary_and_seconds_expire_once(): void
    {
        $this->quota->recordSessionSeconds(4, 0);
        $this->assertNull($this->redis->get($this->dayKey(4)));

        $this->quota->recordSessionSeconds(4, 59);
        $this->assertTrue($this->quota->checkDailyMinutes(4, 1)[0]);
        $this->quota->recordSessionSeconds(4, 60);
        $this->assertFalse($this->quota->checkDailyMinutes(4, 1)[0]);
        $this->assertSame(1, $this->quota->checkDailyMinutes(4, 1)[1]);
        $this->quota->recordSessionSeconds(4, 10);
        $this->assertGreaterThan(0, $this->redis->ttl($this->dayKey(4)));
        $this->assertGreaterThan(0, $this->redis->ttl($this->weekKey(4)));
        $this->assertFalse($this->quota->checkWeeklyMinutes(4, 2)[0]);
    }

    public function test_monthly_tokens_and_combined_checks(): void
    {
        [$user] = $this->makeUser();
        LlmUsage::query()->create([
            'user_id' => $user->id,
            'source' => 'chat',
            'prompt_tokens' => 40,
            'completion_tokens' => 60,
            'total_tokens' => 100,
            'created_at' => Carbon::now('UTC'),
        ]);
        $this->assertSame(100, $this->quota->monthlyTokensUsed($user->id));
        $this->assertFalse($this->quota->checkMonthlyTokens($user->id, 100)[0]);
        $this->assertTrue($this->quota->checkMonthlyTokens($user->id, 0)[0]);

        $blocked = $this->quota->checkAll($user->id, 100, 30, 90, 5, 600);
        $this->assertSame(600, $blocked[0]);
        $this->assertSame('quota_exceeded_tokens', $blocked[1]);
        $this->assertSame(1008, $blocked[3]);

        $this->redis->setex($this->dayKey($user->id), 3600, '1800');
        $time = $this->quota->checkAll($user->id, 0, 30, 90, 5, 600);
        $this->assertSame('quota_exceeded_time', $time[1]);
        $this->assertSame('Daily time limit reached (30/30 min).', $time[2]);

        $this->redis->delete($this->dayKey($user->id));
        $this->redis->setex($this->weekKey($user->id), 3600, '5400');
        $weekly = $this->quota->checkAll($user->id, 0, 30, 90, 5, 600);
        $this->assertSame('quota_exceeded_weekly_minutes', $weekly[1]);

        $this->redis->delete($this->weekKey($user->id));
        $this->redis->setex($this->sessionKey($user->id), 3600, '5');
        $sessions = $this->quota->checkAll($user->id, 0, 30, 90, 5, 600);
        $this->assertSame('quota_exceeded_sessions', $sessions[1]);
        $this->assertSame('Weekly session limit reached (5/5).', $sessions[2]);

        $this->redis->delete($this->sessionKey($user->id));
        $allowed = $this->quota->checkAll($user->id, 0, 30, 90, 5, 600);
        $this->assertNull($allowed[1]);
        $this->assertSame(600, $allowed[0]);
        $this->assertSame('1', $this->redis->get($this->sessionKey($user->id)));
    }

    private function sessionKey(int $userId): string
    {
        $now = Carbon::now('UTC');

        return sprintf('conv_sessions:%d:%d-W%02d', $userId, $now->isoWeekYear, $now->isoWeek);
    }

    private function dayKey(int $userId): string
    {
        return 'conv_seconds:'.$userId.':'.Carbon::now('UTC')->format('Y-m-d');
    }

    private function weekKey(int $userId): string
    {
        $now = Carbon::now('UTC');

        return sprintf('conv_weekly_seconds:%d:%d-W%02d', $userId, $now->isoWeekYear, $now->isoWeek);
    }
}
