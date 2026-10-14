<?php

namespace App\Services\Analytics;

use App\Models\Progress;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RetentionAnalytics
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ArrayRedis $redis,
    ) {}

    /**
     * @return array{0: int, 1: int}
     */
    public function counts(string $cohortDate): array
    {
        $first = Progress::query()
            ->select('user_id', DB::raw('MIN(date) as first_day'))
            ->groupBy('user_id');
        $userIds = DB::query()
            ->fromSub($first, 'first_activity')
            ->whereDate('first_day', $cohortDate)
            ->pluck('user_id');
        $total = $userIds->count();
        if ($total === 0) {
            return [0, 0];
        }
        $returnDay = Carbon::parse($cohortDate)->addDays(7)->toDateString();
        $returned = (int) Progress::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', $returnDay)
            ->distinct()
            ->count('user_id');

        return [$total, $returned];
    }

    public function publish(string $userAgent): void
    {
        if (! $this->analytics->enabled() || trim($userAgent) === '') {
            return;
        }
        $cohortDate = Carbon::now('UTC')->subDays(8)->toDateString();
        try {
            if (! $this->redis->set('analytics:retention:d7:'.$cohortDate, '1', 'NX')) {
                return;
            }
            [$total, $returned] = $this->counts($cohortDate);
            if ($total === 0) {
                return;
            }
            $this->analytics->track('study_retention_d7', $userAgent, '/progress', [
                'cohort_date' => $cohortDate,
                'cohort_size' => $total,
                'returned' => $returned,
                'rate_pct' => round($returned * 100 / $total, 2),
            ]);
        } catch (\Throwable) {
        }
    }
}
