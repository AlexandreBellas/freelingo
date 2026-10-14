<?php

namespace Tests\Unit;

use App\Models\ChatHistory;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\Memory;
use App\Models\Progress;
use App\Models\ProgressReward;
use App\Models\StudyPlan;
use App\Models\UserLanguage;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\MemoryService;
use App\Support\Clock;
use App\Support\JsonDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LearningHelpers;
use Tests\TestCase;

class LearningExtraTest extends TestCase
{
    use LearningHelpers;

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    #[DataProvider('conversationRewardCases')]
    public function test_conversation_rewards_wait_for_answered_turns_and_ignore_repeats(string $source, int $threshold, int $xp): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user, ['target_language' => 'fr-FR', 'is_active' => false]);
        $conversation = $this->conversation($user->id, $plan, $source);
        $greeting = $this->message($conversation, 'assistant', 'Hello!');
        app(ProgressRewards::class)->rewardConversation($greeting->id);
        $this->assertSame(0, Progress::query()->count());

        $this->addTurns($conversation, $threshold - 1);
        app(ProgressRewards::class)->rewardConversation($this->latestReply($conversation)->id);
        $entry = Progress::query()->where('study_plan_id', $plan->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(0, $entry->xp_earned);

        $this->addTurns($conversation, 1, $threshold);
        $reply = $this->latestReply($conversation);
        app(ProgressRewards::class)->rewardConversation($reply->id);
        app(ProgressRewards::class)->rewardConversation($reply->id);
        $this->assertSame($xp, $entry->fresh()->xp_earned);
        $this->assertSame(1, ProgressReward::query()->count());

        $this->addTurns($conversation, 8, $threshold + 1, 'I would like to discuss topic 0');
        app(ProgressRewards::class)->rewardConversation($this->latestReply($conversation)->id);
        $this->assertSame($xp, $entry->fresh()->xp_earned);
    }

    #[DataProvider('conversationLimitCases')]
    public function test_conversation_daily_limit_spans_conversations(string $source, int $turns, int $limit): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $rewards = app(ProgressRewards::class);
        for ($i = 0; $i < 5; $i++) {
            $conversation = $this->conversation($user->id, $plan, $source);
            $this->addTurns($conversation, $turns);
            $rewards->rewardConversation($this->latestReply($conversation)->id);
        }
        $this->assertSame($limit, (int) Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));
    }

    public function test_chat_long_conversation_awards_three_blocks_but_not_old_turns(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, 'chat');
        $this->addTurns($conversation, 20);
        $rewards = app(ProgressRewards::class);
        $rewards->rewardConversation($this->latestReply($conversation)->id);
        $this->assertSame(30, (int) Progress::query()->sum('xp_earned'));

        $yesterday = Carbon::parse('2026-10-12 12:00:00', 'UTC');
        ChatHistory::query()->update(['created_at' => $yesterday]);
        $this->addTurns($conversation, 1, 20);
        $rewards->rewardConversation($this->latestReply($conversation)->id);
        $this->assertSame(3, ProgressReward::query()->count());
    }

    public function test_unanswered_and_planless_conversations_do_not_award(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $orphan = Conversation::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => null,
            'target_language' => 'en-US',
            'source' => 'voice',
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
        $this->addTurns($orphan, 5);
        app(ProgressRewards::class)->rewardConversation($this->latestReply($orphan)->id);

        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, 'chat');
        for ($i = 0; $i < 6; $i++) {
            $this->message($conversation, 'user', "Unanswered {$i}");
        }
        $latest = ChatHistory::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        app(ProgressRewards::class)->rewardConversation($latest->id);
        $this->assertSame(0, Progress::query()->count());
    }

    public function test_reward_is_atomic_and_rejects_a_foreign_plan(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        [$other] = $this->makeAdmin();
        $plan = $this->seedPlan($user);
        $rewards = app(ProgressRewards::class);
        $this->assertSame(0, $rewards->award($other->id, $plan->id, 'unit', 'u1', 30, Clock::today()));
        DB::beginTransaction();
        $this->assertSame(30, $rewards->award($user->id, $plan->id, 'unit', 'u1', 30, Clock::today()));
        $this->assertSame(30, (int) Progress::query()->sum('xp_earned'));
        DB::rollBack();
        $this->assertSame(0, Progress::query()->count());
        $this->assertSame(0, ProgressReward::query()->count());
    }

    public function test_unscored_activity_preserves_skills_across_days(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => '2026-10-12',
            'skills' => ['grammar' => 0.8],
            'skill_updates' => [],
            'streak_day' => 4,
            'xp_earned' => 0,
        ]);
        $entry = app(ProgressService::class)->updateDaily($user->id, $plan->id);
        $this->assertSame(['grammar' => 0.8], $entry->skills);
        $this->assertSame(5, $entry->streak_day);
    }

    public function test_unit_bonus_waits_for_every_scheduled_lesson_and_level_requires_the_test(): void
    {
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user, [
            'generated_plan' => [
                'weekly_plan' => [[
                    'week' => 1,
                    'days' => [
                        ['day' => 1, 'title' => 'First', 'unit_id' => 'u1'],
                        ['day' => 2, 'title' => 'Second', 'unit_id' => 'u1'],
                        ['day' => 3, 'title' => 'Test', 'unit_id' => 'completion-test'],
                    ],
                ]],
            ],
        ]);
        Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'First',
            'unit_id' => 'u1',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
            'is_completed' => true,
        ]);
        $rewards = app(ProgressRewards::class);
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, 'u1'));
        Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Second',
            'unit_id' => 'u1',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 2,
            'content' => [],
            'is_completed' => true,
        ]);
        $this->assertSame(30, $rewards->rewardPlanCompletion($user->id, $plan->id, 'u1'));
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, 'u1'));
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, null));
        $plan->completion_test_taken = true;
        $plan->save();
        $this->assertSame(100, $rewards->rewardPlanCompletion($user->id, $plan->id, null));
        $this->assertSame(0, $rewards->rewardPlanCompletion($user->id, $plan->id, null));
        $this->assertSame(130, (int) Progress::query()->sum('xp_earned'));
    }

    #[DataProvider('voiceContinuationCases')]
    public function test_text_continuation_of_voice_uses_the_chat_threshold_and_cap(int $voiceTurns): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, 'voice');
        $rewards = app(ProgressRewards::class);
        if ($voiceTurns > 0) {
            $this->addTurns($conversation, $voiceTurns, 0, null, 'voice');
            $rewards->rewardConversation($this->latestReply($conversation)->id);
        }
        for ($i = 0; $i < 16; $i++) {
            $this->addTurns($conversation, 1, $i, null, 'chat');
            $rewards->rewardConversation($this->latestReply($conversation)->id);
            $rows = ProgressReward::query()->get();
            $voice = $rows->where('kind', 'voice')->sum('xp');
            $chat = $rows->where('kind', 'chat')->sum('xp');
            $this->assertSame($voiceTurns === 3 ? 20 : 0, (int) $voice);
            $this->assertSame(min(3, intdiv($i + 1, 5)) * 10, (int) $chat);
        }
        $this->assertSame('voice', $conversation->fresh()->source);
    }

    #[DataProvider('secondReplyCases')]
    public function test_interleaved_requests_count_only_their_own_completed_pairs(bool $secondSucceeds): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, 'chat');
        $this->addTurns($conversation, 3);
        $rewards = app(ProgressRewards::class);
        $rewards->rewardConversation($this->latestReply($conversation)->id);
        $first = $this->message($conversation, 'user', 'My fourth contribution');
        $second = $this->message($conversation, 'user', 'My fifth contribution');
        $answer = $this->message($conversation, 'assistant', 'Reply to fourth', $first->id);
        $rewards->rewardConversation($answer->id);
        $this->assertSame(0, (int) Progress::query()->sum('xp_earned'));
        if ($secondSucceeds) {
            $last = $this->message($conversation, 'assistant', 'Reply to fifth', $second->id);
        } else {
            $retry = $this->message($conversation, 'user', $first->content);
            $last = $this->message($conversation, 'assistant', 'Reply to retry', $retry->id);
        }
        $rewards->rewardConversation($last->id);
        $rewards->rewardConversation($last->id);
        $this->assertSame($secondSucceeds ? 10 : 0, (int) Progress::query()->sum('xp_earned'));
    }

    #[DataProvider('midnightRewardCases')]
    public function test_response_day_survives_midnight_and_pairs_with_the_previous_prompt(string $modality, int $threshold, int $xp): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, $modality);
        $this->addTurns($conversation, $threshold - 1, 0, null, $modality);
        $prompt = $this->message($conversation, 'user', 'A question before midnight', null, $modality, Carbon::parse('2026-10-12 23:59:59', 'UTC'));
        $answer = $this->message($conversation, 'assistant', 'The answer after midnight', $prompt->id, $modality, Carbon::parse('2026-10-13 00:00:00', 'UTC'));
        Clock::freeze(Carbon::parse('2026-10-14 00:00:01', 'UTC'));
        $rewards = app(ProgressRewards::class);
        $rewards->rewardConversation($answer->id);
        $rewards->rewardConversation($answer->id);
        $reward = ProgressReward::query()->first();
        $entry = Progress::query()->first();
        $this->assertSame('2026-10-13', JsonDate::date($reward->date));
        $this->assertSame('2026-10-13', JsonDate::date($entry->date));
        $this->assertSame($conversation->id.':2026-10-13:1', $reward->source_key);
        $this->assertSame($xp, $reward->xp);
        $this->assertSame($xp, $entry->xp_earned);
    }

    public function test_legacy_and_empty_responses_do_not_infer_completed_turns(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $conversation = $this->conversation($user->id, $plan, 'voice');
        $this->addTurns($conversation, 5);
        ChatHistory::query()->update(['modality' => null, 'reply_to_id' => null]);
        $rewards = app(ProgressRewards::class);
        $rewards->rewardConversation(ChatHistory::query()->max('id'));
        $this->assertSame(0, Progress::query()->count());

        $prompt = $this->message($conversation, 'user', 'An unanswered question', null, 'voice');
        $empty = $this->message($conversation, 'assistant', "\n\t ", $prompt->id, 'voice');
        $rewards->rewardConversation($empty->id);
        $this->assertSame(0, Progress::query()->count());
        $this->addTurns($conversation, 2, 6, null, 'voice');
        $rewards->rewardConversation($this->latestReply($conversation)->id);
        $this->assertSame(0, (int) Progress::query()->sum('xp_earned'));
    }

    #[DataProvider('lateSkillCases')]
    public function test_late_skill_update_repairs_the_summary_without_losing_later_scores(bool $existingDay, bool $laterScored): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $spanish = UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => 'fr-FR',
            'is_active' => false,
            'created_at' => Clock::now(),
        ]);
        $other = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $spanish->id,
            'cefr_level' => 'A1',
            'target_language' => 'fr-FR',
            'goals' => ['grammar'],
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'current_unit' => '',
            'progress_day' => 0,
            'generated_plan' => ['weekly_plan' => []],
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);
        $today = '2026-10-13';
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => '2026-10-11',
            'skills' => ['grammar' => 0.8],
            'skill_updates' => [],
            'streak_day' => 4,
            'xp_earned' => 0,
        ]);
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $other->id,
            'date' => $today,
            'skills' => ['grammar' => 0.1],
            'skill_updates' => [],
            'streak_day' => 2,
            'xp_earned' => 0,
        ]);
        $progress = app(ProgressService::class);
        if ($existingDay) {
            $progress->updateDaily($user->id, $plan->id, activityDate: '2026-10-12');
        }
        $progress->updateDaily($user->id, $plan->id, xp: 10, skill: 'vocabulary', skillScore: 0.4, activityDate: $today);
        if ($laterScored) {
            foreach ([0.2, 0.6] as $score) {
                $progress->updateDaily($user->id, $plan->id, skill: 'grammar', skillScore: $score, activityDate: $today);
            }
        }
        $late = $progress->updateDaily($user->id, $plan->id, xp: 5, skill: 'grammar', skillScore: 1.0, activityDate: '2026-10-12');
        $this->assertEquals(0.86, $late->skills['grammar']);
        $this->assertSame(5, $late->streak_day);

        $summary = $this->api('GET', '/api/progress/summary', [], $headers);
        $summary->assertOk();
        $skills = $summary->json('skills');
        $this->assertEqualsWithDelta($laterScored ? 0.643 : 0.86, $skills['grammar'], 0.0001);
        $this->assertEqualsWithDelta(0.4, $skills['vocabulary'], 0.0001);
        $summary->assertJsonPath('current_streak', 6);
        $summary->assertJsonPath('total_xp', 15);
        $summary->assertJsonPath('today_xp', 10);

        $untouched = Progress::query()->where('study_plan_id', $other->id)->first();
        $this->assertEquals(0.1, $untouched->skills['grammar']);
        $this->assertSame(2, $untouched->streak_day);
    }

    public function test_repeated_late_updates_converge_across_gaps_and_new_skills(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plans = [];
        foreach (['en-US', 'fr-FR'] as $language) {
            $row = UserLanguage::query()->where('user_id', $user->id)->where('target_language', $language)->first();
            if (! $row) {
                $row = UserLanguage::query()->create([
                    'user_id' => $user->id,
                    'target_language' => $language,
                    'is_active' => false,
                    'created_at' => Clock::now(),
                ]);
            }
            $plans[] = StudyPlan::query()->create([
                'user_id' => $user->id,
                'user_language_id' => $row->id,
                'cefr_level' => 'A1',
                'target_language' => $language,
                'goals' => ['grammar'],
                'duration_weeks' => 4,
                'days_per_week' => 4,
                'current_unit' => '',
                'progress_day' => 0,
                'generated_plan' => ['weekly_plan' => []],
                'is_active' => false,
                'created_at' => Clock::now(),
            ]);
        }
        $start = Carbon::parse('2026-10-09', 'UTC');
        $events = [
            [0, 'grammar', 1.0],
            [0, 'grammar', 0.2],
            [0, 'reading', 0.5],
            [1, 'grammar', 0.3],
            [1, 'grammar', 0.9],
            [1, 'vocabulary', 0.6],
            [3, null, null],
            [4, 'reading', 0.8],
        ];
        $progress = app(ProgressService::class);
        foreach ([[0, 1, 2, 3, 4, 5, 6, 7], [3, 6, 7, 0, 4, 1, 5, 2]] as $index => $order) {
            foreach ($order as $eventIndex) {
                [$day, $skill, $score] = $events[$eventIndex];
                $progress->updateDaily(
                    $user->id,
                    $plans[$index]->id,
                    xp: 1,
                    skill: $skill,
                    skillScore: $score,
                    activityDate: $start->copy()->addDays($day)->format('Y-m-d'),
                );
            }
        }
        $snapshots = [];
        foreach ($plans as $plan) {
            $entries = Progress::query()->where('study_plan_id', $plan->id)->orderBy('date')->get();
            $this->assertSame([1, 2, 1, 2], $entries->map(fn (Progress $entry) => $entry->streak_day)->all());
            $this->assertEquals(0.59, $entries->last()->skills['reading']);
            $snapshots[] = $entries->map(fn (Progress $entry) => [
                JsonDate::date($entry->date),
                $entry->skills,
                $entry->skill_updates,
                $entry->streak_day,
                $entry->xp_earned,
            ])->all();
        }
        $this->assertEquals($snapshots[0], $snapshots[1]);
    }

    public function test_late_reconciliation_rolls_back_with_its_activity(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $progress = app(ProgressService::class);
        $progress->updateDaily($user->id, $plan->id, skill: 'grammar', skillScore: 0.2, xp: 10, activityDate: '2026-10-13');
        DB::beginTransaction();
        try {
            $progress->updateDaily($user->id, $plan->id, skill: 'grammar', skillScore: 1.0, xp: 5, activityDate: '2026-10-12');
            $updated = Progress::query()->whereDate('date', '2026-10-13')->first();
            $this->assertNotNull($updated);
            $this->assertEquals(0.76, $updated->skills['grammar']);
            $this->assertSame(2, $updated->streak_day);
        } finally {
            DB::rollBack();
        }
        $entries = Progress::query()->get();
        $this->assertCount(1, $entries);
        $this->assertEquals(['grammar' => 0.2], $entries[0]->skills);
        $this->assertEquals(['grammar' => [0.2]], $entries[0]->skill_updates);
        $this->assertSame(1, $entries[0]->streak_day);
        $this->assertSame(10, $entries[0]->xp_earned);
    }

    public function test_legacy_skill_checkpoint_is_not_reinterpreted_as_unscored_activity(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $legacy = Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => '2026-10-12',
            'skills' => ['grammar' => 0.4],
            'streak_day' => 1,
            'xp_earned' => 0,
        ]);
        $progress = app(ProgressService::class);
        $progress->updateDaily($user->id, $plan->id, activityDate: '2026-10-13');
        $progress->updateDaily($user->id, $plan->id, skill: 'grammar', skillScore: 1.0, activityDate: '2026-10-11');
        $legacy->refresh();
        $latest = Progress::query()->whereDate('date', '2026-10-13')->first();
        $this->assertNull($legacy->skill_updates);
        $this->assertEquals(['grammar' => 0.4], $legacy->skills);
        $this->assertEquals(['grammar' => 0.4], $latest->skills);
        $this->assertSame(3, $latest->streak_day);

        $progress->updateDaily($user->id, $plan->id, skill: 'grammar', skillScore: 1.0, activityDate: '2026-10-12');
        $legacy->refresh();
        $latest->refresh();
        $this->assertEquals(0.58, $legacy->skills['grammar']);
        $this->assertEquals(0.58, $latest->skills['grammar']);
    }

    public function test_assessment_banks_follow_the_language_fallback(): void
    {
        $reflection = new \ReflectionClass(LearningCatalog::class);
        $cache = $reflection->getProperty('cache');
        $cache->setValue(null, []);
        $english = LearningCatalog::assessmentBank('en-GB');
        $again = LearningCatalog::assessmentBank('en-GB');
        $this->assertNotEmpty($english);
        $this->assertEquals($english, $again);
        $this->assertCount(1, $cache->getValue());
        $this->assertNotEmpty(LearningCatalog::assessmentBank('en-US'));
        $this->assertNotEmpty(LearningCatalog::assessmentBank('es-ES'));
        $this->assertNotEmpty(LearningCatalog::assessmentBank('fr'));
        $this->assertNotEmpty(LearningCatalog::assessmentBank('de'));
        $this->assertNotEmpty(LearningCatalog::assessmentBank('it'));
        $this->assertNotEmpty(LearningCatalog::assessmentBank('pt'));
        $japanese = LearningCatalog::assessmentBank('ja-JP');
        $this->assertNotEmpty($japanese);
        $this->assertTrue(collect($japanese)->contains(fn (array $question) => $question['id'] === 'ja-g-a1-001'));
        $korean = LearningCatalog::assessmentBank('ko-KR');
        $this->assertCount(132, $korean);
        $this->assertTrue(collect($korean)->contains(fn (array $question) => $question['id'] === 'ko-g-a1-001'));
        $chinese = LearningCatalog::assessmentBank('zh-CN');
        $this->assertCount(180, $chinese);
        $this->assertTrue(collect($chinese)->contains(fn (array $question) => $question['id'] === 'zh-g-a1-001'));
        $this->assertEquals($english, LearningCatalog::assessmentBank('xx-XX'));
        $this->assertEquals($english, LearningCatalog::assessmentBank(''));
        $this->assertEquals(LearningCatalog::assessmentBank('de'), LearningCatalog::assessmentBank('de-DE'));
        $this->assertEquals(LearningCatalog::assessmentBank('fr'), LearningCatalog::assessmentBank('fr-CA'));
    }

    public function test_curriculum_is_loaded_per_language_and_falls_back_to_english(): void
    {
        $english = LearningCatalog::curriculum('en-US');
        $this->assertArrayHasKey('A1', $english);
        $this->assertArrayHasKey('C2', $english);
        $this->assertCount(8, $english['A1']);
        $this->assertCount(8, $english['C2']);
        $this->assertSame('Saludos y presentaciones', LearningCatalog::curriculum('es-ES')['A1'][0]['title']);
        $this->assertSame('Saluti e presentazioni', LearningCatalog::curriculum('it-IT')['A1'][0]['title']);
        $this->assertSame('Saudações e apresentações', LearningCatalog::curriculum('pt-PT')['A1'][0]['title']);
        $this->assertSame('Se présenter et saluer', LearningCatalog::curriculum('fr-FR')['A1'][0]['title']);
        $german = LearningCatalog::curriculum('de-DE');
        $this->assertCount(8, $german['A1']);
        $this->assertSame('Sich vorstellen und begrüßen', $german['A1'][0]['title']);
        $this->assertSame('Identity & Greetings', LearningCatalog::curriculum('xx-XX')['A1'][0]['title']);
        $japanese = LearningCatalog::curriculum('ja-JP');
        $this->assertSame(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], array_keys($japanese));
        $this->assertSame('文字とあいさつ', $japanese['A1'][0]['title']);
        $this->assertSame('C2総復習と最終統合', $japanese['C2'][7]['title']);
        $korean = LearningCatalog::curriculum('ko-KR');
        $this->assertSame('한글과 기본 인사', $korean['A1'][0]['title']);
        $this->assertSame('C2 종합 복습', $korean['C2'][7]['title']);
        $chinese = LearningCatalog::curriculum('zh-CN');
        $this->assertSame('拼音、声调和问候', $chinese['A1'][0]['title']);
        $this->assertSame('C2综合复习', $chinese['C2'][7]['title']);
        $this->assertNotSame(
            LearningCatalog::units('A1', 'en-US')[0]['title'],
            LearningCatalog::units('A1', 'es-ES')[0]['title'],
        );
        $this->assertCount(8, LearningCatalog::units('A1', 'es-ES'));
    }

    public function test_memories_stay_global_and_conversations_stay_on_their_plan(): void
    {
        [$user] = $this->makeUser();
        $english = $this->seedPlan($user);
        $spanishLanguage = UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => 'es-ES',
            'is_active' => false,
            'created_at' => Clock::now(),
        ]);
        $spanish = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $spanishLanguage->id,
            'cefr_level' => 'A1',
            'target_language' => 'es-ES',
            'goals' => ['grammar'],
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'current_unit' => '',
            'progress_day' => 0,
            'generated_plan' => ['weekly_plan' => []],
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);
        Memory::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $english->id,
            'content' => 'User likes dogs',
            'source' => 'chat',
            'created_at' => Clock::now(),
        ]);
        Memory::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $spanish->id,
            'content' => 'Usuario prefiere gatos',
            'source' => 'chat',
            'created_at' => Clock::now(),
        ]);
        $memories = app(MemoryService::class)->list($user->id);
        $this->assertSame(['User likes dogs', 'Usuario prefiere gatos'], array_map(fn (Memory $memory) => $memory->content, $memories));
        $this->conversation($user->id, $english, 'chat');
        $this->conversation($user->id, $spanish, 'chat');
        $this->assertSame(1, Conversation::query()->where('study_plan_id', $english->id)->count());
        $this->assertSame(1, Conversation::query()->where('study_plan_id', $spanish->id)->count());
    }

    public static function conversationRewardCases(): array
    {
        return [
            'voice' => ['voice', 3, 20],
            'chat' => ['chat', 5, 10],
        ];
    }

    public static function conversationLimitCases(): array
    {
        return [
            'voice' => ['voice', 3, 60],
            'chat' => ['chat', 5, 30],
        ];
    }

    public static function voiceContinuationCases(): array
    {
        return [
            'no voice' => [0],
            'below voice threshold' => [2],
            'voice awarded' => [3],
        ];
    }

    public static function secondReplyCases(): array
    {
        return [
            'fifth unanswered' => [false],
            'fifth answered' => [true],
        ];
    }

    public static function midnightRewardCases(): array
    {
        return [
            'voice' => ['voice', 3, 20],
            'chat' => ['chat', 5, 10],
        ];
    }

    public static function lateSkillCases(): array
    {
        return [
            'new day, later unscored' => [false, false],
            'new day, later scored' => [false, true],
            'existing day, later unscored' => [true, false],
            'existing day, later scored' => [true, true],
        ];
    }

    private function conversation(int $userId, ?StudyPlan $plan, string $source): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $userId,
            'study_plan_id' => $plan?->id,
            'target_language' => $plan?->target_language ?? 'en-US',
            'source' => $source,
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
    }

    private function addTurns(Conversation $conversation, int $count, int $start = 0, ?string $content = null, ?string $modality = null): void
    {
        for ($i = $start; $i < $start + $count; $i++) {
            $userMessage = $this->message($conversation, 'user', $content ?? "I would like to discuss topic {$i}", null, $modality);
            $this->message($conversation, 'assistant', "Let's discuss topic {$i}", $userMessage->id, $modality);
        }
    }

    private function message(
        Conversation $conversation,
        string $role,
        string $content,
        ?int $replyTo = null,
        ?string $modality = null,
        ?Carbon $createdAt = null,
    ): ChatHistory {
        return ChatHistory::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'study_plan_id' => $conversation->study_plan_id,
            'target_language' => $conversation->target_language,
            'role' => $role,
            'content' => $content,
            'modality' => $modality ?? $conversation->source,
            'reply_to_id' => $replyTo,
            'created_at' => $createdAt ?? Clock::now(),
        ]);
    }

    private function latestReply(Conversation $conversation): ChatHistory
    {
        return ChatHistory::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first();
    }
}
