<?php

namespace Tests\Unit;

use App\Models\ChatHistory;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\Progress;
use App\Models\ProgressReward;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\LearningHelpers;
use Tests\TestCase;

class ProgressLearningTest extends TestCase
{
    use LearningHelpers;

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    public function test_late_skill_update_repairs_later_days(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $progress = new ProgressService;
        $today = $progress->today();
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => Carbon::parse($today)->subDays(2)->format('Y-m-d'),
            'skills' => ['grammar' => 0.8],
            'skill_updates' => [],
            'streak_day' => 4,
            'xp_earned' => 0,
        ]);
        $progress->updateDaily($user->id, $plan->id, xp: 10, skill: 'vocabulary', skillScore: 0.4, activityDate: $today);
        $late = $progress->updateDaily(
            $user->id,
            $plan->id,
            xp: 5,
            skill: 'grammar',
            skillScore: 1.0,
            activityDate: Carbon::parse($today)->subDay()->format('Y-m-d'),
        );
        $this->assertEquals(0.86, $late->skills['grammar']);
        $this->assertSame(5, $late->streak_day);
        $latest = Progress::query()->where('study_plan_id', $plan->id)->orderByDesc('date')->first();
        $this->assertEquals(0.86, $latest->skills['grammar']);
        $this->assertEquals(0.4, $latest->skills['vocabulary']);
        $this->assertSame(6, $latest->streak_day);
        $this->assertSame(15, Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));
    }

    public function test_chat_rewards_need_five_answered_turns_and_stay_idempotent(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user, ['target_language' => 'fr-FR', 'is_active' => false]);
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'target_language' => 'fr-FR',
            'source' => 'chat',
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
        $rewards = app(ProgressRewards::class);
        $orphan = ChatHistory::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello!',
            'modality' => 'chat',
            'created_at' => Clock::now(),
        ]);
        $rewards->rewardConversation($orphan->id);
        $this->assertSame(0, Progress::query()->count());

        $last = null;
        for ($i = 0; $i < 5; $i++) {
            $userMessage = ChatHistory::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => "I would like to discuss topic {$i}",
                'modality' => 'chat',
                'created_at' => Clock::now(),
            ]);
            $last = ChatHistory::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => "Let's discuss topic {$i}",
                'modality' => 'chat',
                'reply_to_id' => $userMessage->id,
                'created_at' => Clock::now(),
            ]);
        }
        $rewards->rewardConversation($last->id);
        $rewards->rewardConversation($last->id);
        $this->assertSame(10, (int) Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));
        $this->assertSame(1, ProgressReward::query()->count());
    }

    public function test_unit_completion_awards_thirty_xp_once(): void
    {
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user, [
            'generated_plan' => [
                'weekly_plan' => [[
                    'week' => 1,
                    'days' => [
                        ['day' => 1, 'title' => 'Lesson A', 'unit_id' => 'a1-unit-1'],
                        ['day' => 2, 'title' => 'Level A1 Completion Test', 'unit_id' => 'completion-test'],
                    ],
                ]],
            ],
        ]);
        Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Lesson A',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'unit_id' => 'a1-unit-1',
            'content' => [],
            'is_completed' => true,
        ]);
        $rewards = app(ProgressRewards::class);
        $this->assertSame(30, $rewards->rewardPlanCompletion($user->id, $plan->id, 'a1-unit-1'));
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, 'a1-unit-1'));
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, null));
    }
}
