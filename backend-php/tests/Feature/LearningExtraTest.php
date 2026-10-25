<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Flashcard;
use App\Models\FlashcardReviewReceipt;
use App\Models\Lesson;
use App\Models\Memory;
use App\Models\Progress;
use App\Models\ProgressReward;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserCompetency;
use App\Models\UserLanguage;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmTimeoutException;
use App\Services\Llm\LlmUnavailableException;
use App\Services\Llm\ScriptedLlm;
use App\Services\Redis\ArrayRedis;
use App\Services\Security\Jwt;
use App\Support\Clock;
use App\Support\JsonDate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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

    public function test_start_lesson_returns_the_owned_lesson(): void
    {
        [$user, $headers] = $this->makeUser();
        $lesson = $this->lesson($this->seedPlan($user, ['cefr_level' => 'A2']), 'Start Me', 'A2');
        $started = $this->api('POST', "/api/lessons/{$lesson->id}/start", [], $headers);
        $started->assertOk();
        $started->assertJsonPath('id', $lesson->id);
        $started->assertJsonPath('title', 'Start Me');
    }

    public function test_start_lesson_is_missing_for_unknown_and_foreign_lessons(): void
    {
        [, $headers] = $this->makeUser();
        $this->api('POST', '/api/lessons/99999/start', [], $headers)->assertStatus(404);
        $this->api('POST', '/api/lessons/1/start', [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();

        [$owner] = $this->makeUser(['username' => 'lessonowner', 'email' => 'lessonowner@example.com']);
        $lesson = $this->lesson($this->seedPlan($owner), 'Private');
        $this->api('POST', "/api/lessons/{$lesson->id}/start", [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'Lesson not found');
    }

    #[DataProvider('completionMatrix')]
    public function test_complete_awards_eligible_milestones_once(bool $already, bool $withUnit, bool $testTaken): void
    {
        Clock::freeze(Carbon::parse('2026-10-05 23:59:59', 'UTC'));
        config(['freelingo.stripe_enabled' => true]);
        [$user, $headers] = $this->makeUser();
        [$plan, $lesson] = $this->completionCase($user, $already, $withUnit, $testTaken);
        $originalCompletedAt = JsonDate::dateTime($lesson->completed_at);
        $originalCompetencies = $this->competencySnapshot();

        $first = $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers);
        $first->assertOk();
        $expectedStamp = $already ? $originalCompletedAt : '2026-10-05T23:59:59';
        $first->assertJsonPath('completed_at', $expectedStamp);

        $expected = [];
        if ($withUnit) {
            $expected['unit'] = 30;
        }
        if ($testTaken) {
            $expected['level'] = 100;
        }
        $rewards = [];
        foreach (ProgressReward::query()->get() as $reward) {
            $rewards[$reward->kind] = $reward->xp;
            $this->assertSame($plan->id, $reward->study_plan_id);
            $this->assertSame('2026-10-05', JsonDate::date($reward->date));
        }
        $this->assertSame($expected, $rewards);
        $progress = Progress::query()->orderBy('date')->get();
        $this->assertSame(20 + array_sum($expected), (int) $progress->sum('xp_earned'));
        $this->assertSame(1, (int) $progress->sum('lessons_completed'));
        $this->assertTrue($progress->every(fn (Progress $row) => $row->study_plan_id === $plan->id));
        if ($already) {
            $this->assertSame('2026-09-01', JsonDate::date($progress[0]->date));
            $this->assertSame(20, $progress[0]->xp_earned);
            $this->assertEquals(['grammar' => 0.8], $progress[0]->skills);
            $this->assertEquals($originalCompetencies, $this->competencySnapshot());
        }
        if ($expected !== [] || ! $already) {
            $last = $progress->last();
            $this->assertSame('2026-10-05', JsonDate::date($last->date));
            $this->assertSame(array_sum($expected) + ($already ? 0 : 20), $last->xp_earned);
        }
        if ($withUnit && ! $already) {
            $this->assertNotEmpty($this->competencySnapshot());
        }
        $savedCompetencies = $this->competencySnapshot();
        $savedProgress = $progress->map(fn (Progress $row) => [
            JsonDate::date($row->date),
            $row->xp_earned,
            $row->lessons_completed,
        ])->all();

        Clock::freeze(Carbon::parse('2026-10-06 23:59:59', 'UTC'));
        $repeated = $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers);
        $repeated->assertOk();
        $repeated->assertJsonPath('completed_at', $first->json('completed_at'));
        $this->assertEquals($savedCompetencies, $this->competencySnapshot());
        $this->assertSame($savedProgress, Progress::query()->orderBy('date')->get()->map(fn (Progress $row) => [
            JsonDate::date($row->date),
            $row->xp_earned,
            $row->lessons_completed,
        ])->all());
        $this->assertSame(count($expected), ProgressReward::query()->count());
        $usage = app(ArrayRedis::class)->get('freemium:lessons:'.$user->id.':2026-10-05');
        $this->assertSame($already ? null : '1', $usage);
    }

    #[DataProvider('alreadyCompleted')]
    public function test_complete_does_not_reward_an_unfinished_schedule(bool $already): void
    {
        Clock::freeze(Carbon::parse('2026-10-05 23:59:59', 'UTC'));
        [$user, $headers] = $this->makeUser();
        [$plan, $lesson] = $this->completionCase($user, $already, true, true);
        $plan->generated_plan = [
            'weekly_plan' => [[
                'week' => 1,
                'days' => [
                    ['day' => 1, 'title' => $lesson->title, 'unit_id' => $lesson->unit_id],
                    ['day' => 2, 'title' => 'Still pending', 'unit_id' => $lesson->unit_id],
                    ['day' => 3, 'title' => 'Test', 'unit_id' => 'completion-test'],
                ],
            ]],
        ];
        $plan->save();
        $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers)->assertOk();
        $this->assertSame(0, ProgressReward::query()->count());
        $this->assertSame(20, (int) Progress::query()->sum('xp_earned'));
        $this->assertSame(1, (int) Progress::query()->sum('lessons_completed'));
    }

    #[DataProvider('alreadyCompleted')]
    public function test_completion_and_milestone_rewards_roll_back_together(bool $already): void
    {
        Clock::freeze(Carbon::parse('2026-10-05 23:59:59', 'UTC'));
        config(['freelingo.stripe_enabled' => true]);
        [$user, $headers] = $this->makeUser();
        [, $lesson] = $this->completionCase($user, $already, true, true);
        $originalCompleted = $lesson->is_completed;
        $originalStamp = JsonDate::dateTime($lesson->completed_at);
        $originalCompetencies = $this->competencySnapshot();
        $this->app->instance(ProgressRewards::class, new class(app(ProgressService::class)) extends ProgressRewards
        {
            public function rewardPlanCompletion(int $userId, int $planId, ?string $unitId = null, ?string $activityDate = null): int
            {
                $result = parent::rewardPlanCompletion($userId, $planId, $unitId, $activityDate);
                if ($unitId === null) {
                    if ($result !== 100) {
                        throw new \RuntimeException('expected level award of 100, got '.$result);
                    }
                    throw new \RuntimeException('milestone persistence failed');
                }

                return $result;
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers);
            $this->fail('expected the milestone write to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('milestone persistence failed', $e->getMessage());
        }
        $lesson->refresh();
        $this->assertSame($originalCompleted, (bool) $lesson->is_completed);
        $this->assertSame($originalStamp, JsonDate::dateTime($lesson->completed_at));
        $this->assertSame(0, ProgressReward::query()->count());
        $this->assertSame($already ? 20 : 0, (int) Progress::query()->sum('xp_earned'));
        $this->assertEquals($originalCompetencies, $this->competencySnapshot());
        $this->assertNull(app(ArrayRedis::class)->get('freemium:lessons:'.$user->id.':2026-10-05'));
    }

    public function test_competencies_require_auth_and_return_unit_scores(): void
    {
        $this->api('GET', '/api/progress/competencies', [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        [$user, $headers] = $this->makeUser(['username' => 'skills', 'email' => 'skills@example.com']);
        $plan = $this->seedPlan($user, ['current_unit' => 'a1-unit-1']);
        $lesson = $this->lesson($plan, 'Unit 1 Lesson', 'A1', ['unit_id' => 'a1-unit-1', 'lesson_type' => 'grammar']);
        Exercise::query()->create([
            'lesson_id' => $lesson->id,
            'exercise_type' => 'multiple_choice',
            'question' => 'Q?',
            'options' => ['A', 'B'],
            'correct_answer' => 'A',
            'score' => 1.0,
        ]);
        $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers)->assertOk();
        $response = $this->api('GET', '/api/progress/competencies', [], $headers);
        $response->assertOk();
        $item = $response->json('0');
        $this->assertSame('a1-unit-1', $item['unit_id']);
        $this->assertArrayHasKey('score', $item);
        $this->assertArrayHasKey('mastered_count', $item);
        $this->assertArrayHasKey('total_count', $item);
        $this->assertGreaterThan(0, $item['total_count']);
        $this->assertSame($item['total_count'], $item['mastered_count']);
    }

    #[DataProvider('streakAges')]
    public function test_summary_and_language_summary_expire_the_streak(int $daysAgo, int $expected): void
    {
        Clock::freeze(Carbon::now('UTC'));
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $day = Carbon::parse(Clock::today(), 'UTC')->subDays($daysAgo)->format('Y-m-d');
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => $day,
            'xp_earned' => 20,
            'streak_day' => 4,
            'skills' => [],
            'skill_updates' => [],
        ]);
        $summary = $this->api('GET', '/api/progress/summary', [], $headers);
        $summary->assertOk();
        $summary->assertJsonPath('current_streak', $expected);
        $summary->assertJsonPath('today_xp', $daysAgo === 0 ? 20 : 0);
        $week = $summary->json('activity_week');
        $this->assertCount(7, $week);
        $this->assertSame(Clock::today(), $week[6]['date']);
        $this->assertSame($daysAgo <= 6 ? 1 : 0, array_sum(array_column($week, 'active')));

        $languages = $this->api('GET', '/api/languages', [], $headers);
        $english = collect($languages->json('languages'))->firstWhere('target_language', 'en-US');
        $this->assertSame($expected, $english['progress']['current_streak']);
    }

    public function test_flashcard_library_lists_non_due_cards_and_hides_other_users(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $this->api('GET', '/api/flashcards/all', [], $headers)->assertOk()->assertExactJson([]);
        $this->api('GET', '/api/flashcards/all', [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        Flashcard::query()->create($this->card($user->id, $plan->id, 'serendipity', [
            'next_review' => '2026-10-23',
            'interval' => 10,
            'repetitions' => 2,
        ]));
        $words = collect($this->api('GET', '/api/flashcards/all', [], $headers)->json())->pluck('word');
        $this->assertTrue($words->contains('serendipity'));

        [$other] = $this->makeUser(['username' => 'other2', 'email' => 'other2@example.com']);
        $otherPlan = $this->seedPlan($other);
        Flashcard::query()->create($this->card($other->id, $otherPlan->id, 'foreignword'));
        $own = collect($this->api('GET', '/api/flashcards/all', [], $headers)->json())->pluck('word');
        $this->assertFalse($own->contains('foreignword'));
    }

    public function test_generate_persists_cards_for_the_plan_language_and_counts_duplicates(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user, ['target_language' => 'en-US']);
        $llm = new ScriptedLlm([[
            'flashcards' => [
                ['word' => 'ubiquitous', 'definition' => 'present everywhere', 'example_sentence' => 'Phones are ubiquitous.', 'translation' => 'ubicuo'],
                ['word' => 'ephemeral', 'definition' => 'lasting briefly', 'example_sentence' => 'Trends are ephemeral.', 'translation' => 'efímero'],
            ],
        ]]);
        $this->app->instance(LlmClient::class, $llm);
        $created = $this->api('POST', '/api/flashcards/generate', [
            'topic' => 'technology',
            'count' => 2,
            'cefr_level' => 'B2',
            'target_language' => 'it-IT',
        ], $headers);
        $created->assertOk();
        $created->assertJsonPath('created', 2);
        $created->assertJsonPath('skipped_duplicates', 0);
        $created->assertJsonPath('skipped_empty', 0);
        $this->assertSame(['ubiquitous', 'ephemeral'], $created->json('flashcards.*.word'));
        $this->assertStringContainsString('English (US)', $llm->prompt());
        $this->assertStringNotContainsString('Italian', $llm->prompt());
        $this->assertSame(2, Flashcard::query()->where('user_id', $user->id)->count());

        Flashcard::query()->where('word', 'ephemeral')->delete();
        Flashcard::query()->where('word', 'ubiquitous')->update(['is_suspended' => true]);
        $this->app->instance(LlmClient::class, new ScriptedLlm([[
            'flashcards' => [
                ['word' => 'ubiquitous', 'definition' => 'present everywhere', 'example_sentence' => 'Phones are ubiquitous.', 'translation' => 'ubicuo'],
                ['word' => 'ephemeral', 'definition' => 'lasting briefly', 'example_sentence' => 'Trends are ephemeral.', 'translation' => 'efímero'],
                ['word' => ' EPHEMERAL ', 'definition' => 'lasting briefly', 'example_sentence' => 'Trends are ephemeral.', 'translation' => 'efímero'],
                ['word' => ' ', 'definition' => 'blank', 'example_sentence' => 'x', 'translation' => 'x'],
            ],
        ], [
            'flashcards' => [
                ['word' => 'ubiquitous', 'definition' => 'present everywhere', 'example_sentence' => 'Phones are ubiquitous.', 'translation' => 'ubicuo'],
                ['word' => 'ephemeral', 'definition' => 'lasting briefly', 'example_sentence' => 'Trends are ephemeral.', 'translation' => 'efímero'],
                ['word' => ' EPHEMERAL ', 'definition' => 'lasting briefly', 'example_sentence' => 'x', 'translation' => 'x'],
                ['word' => ' ', 'definition' => 'blank', 'example_sentence' => 'x', 'translation' => 'x'],
            ],
        ]]));
        $counted = $this->api('POST', '/api/flashcards/generate', ['topic' => 'test', 'count' => 5], $headers);
        $counted->assertOk();
        $counted->assertJsonPath('created', 1);
        $counted->assertJsonPath('skipped_duplicates', 2);
        $counted->assertJsonPath('skipped_empty', 1);
        $counted->assertJsonPath('flashcards.0.word', 'ephemeral');
        $again = $this->api('POST', '/api/flashcards/generate', ['topic' => 'test', 'count' => 5], $headers);
        $again->assertOk();
        $again->assertJsonPath('created', 0);
        $again->assertJsonPath('skipped_duplicates', 3);
        $again->assertJsonPath('skipped_empty', 1);
        $this->assertSame($plan->id, Flashcard::query()->where('word', 'ephemeral')->value('study_plan_id'));
    }

    public function test_generate_reports_timeout_and_unavailable_models(): void
    {
        [, $headers] = $this->makeUser(['username' => 'generr', 'email' => 'generr@example.com']);
        $this->seedPlan($user = User::query()->where('email', 'generr@example.com')->first());
        $this->api('POST', '/api/flashcards/generate', [
            'topic' => 'food',
            'count' => 2,
        ], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->app->instance(LlmClient::class, new ScriptedLlm([new LlmTimeoutException('timeout')]));
        $this->api('POST', '/api/flashcards/generate', ['topic' => 'animals', 'count' => 3, 'cefr_level' => 'A2'], $headers)
            ->assertStatus(504)
            ->assertJsonPath('detail', 'The AI model took too long.');
        $this->app->instance(LlmClient::class, new ScriptedLlm([new LlmUnavailableException('down')]));
        $this->api('POST', '/api/flashcards/generate', ['topic' => 'sports', 'count' => 3, 'cefr_level' => 'B1'], $headers)
            ->assertStatus(503)
            ->assertJsonPath('detail', 'ai_service_unavailable');
        $this->assertSame(0, Flashcard::query()->where('user_id', $user->id)->count());
    }

    public function test_library_filters_sources_status_and_literal_search(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $generated = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $saved = Flashcard::query()->create($this->card($user->id, $plan->id, 'apple', ['source' => 'from_text']));
        $italian = $this->languagePlan($user, 'it-IT');
        Flashcard::query()->create($this->card($user->id, $italian->id, 'libro'));
        $library = $this->api('GET', '/api/flashcards/library', [], $headers);
        $library->assertOk();
        $this->assertSame([$saved->id, $generated->id], array_column($library->json('items'), 'id'));
        foreach (['/api/flashcards/vocabulary', '/api/flashcards/library?source=from_text'] as $path) {
            $filtered = $this->api('GET', $path, [], $headers);
            $this->assertSame([$saved->id], array_column($filtered->json('items'), 'id'));
        }

        $failed = Flashcard::query()->create($this->card($user->id, $plan->id, 'failed', ['interval' => 1, 'repetitions' => 0]));
        $suspended = Flashcard::query()->create($this->card($user->id, $plan->id, 'suspended', ['is_suspended' => true]));
        $future = Flashcard::query()->create($this->card($user->id, $plan->id, 'future', [
            'interval' => 6,
            'repetitions' => 2,
            'next_review' => '2026-10-19',
            'translation' => '100%_literal',
        ]));
        $expected = [
            'status=new' => [$saved->id, $generated->id],
            'status=due' => [$saved->id, $generated->id, $failed->id],
            'status=suspended' => [$suspended->id],
            'search=LIBRO' => [$saved->id, $generated->id, $failed->id, $suspended->id],
            'search=%25_' => [$future->id],
        ];
        foreach ($expected as $query => $ids) {
            $response = $this->api('GET', '/api/flashcards/library?'.$query, [], $headers);
            $response->assertOk();
            $this->assertEqualsCanonicalizing($ids, array_column($response->json('items'), 'id'));
        }
        $due = $this->api('GET', '/api/flashcards/due', [], $headers);
        $due->assertJsonPath('total', 5);
        $this->assertEqualsCanonicalizing(
            [$saved->id, $generated->id, $failed->id],
            array_column($due->json('due'), 'id'),
        );
    }

    public function test_library_clamps_the_page_after_deletion(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'pages', 'email' => 'pages@example.com']);
        $plan = $this->seedPlan($user);
        $first = Flashcard::query()->create($this->card($user->id, $plan->id, 'a'));
        $last = Flashcard::query()->create($this->card($user->id, $plan->id, 'z'));
        $this->api('DELETE', "/api/flashcards/{$last->id}", [], $headers)->assertNoContent();
        $page = $this->api('GET', '/api/flashcards/library?page=2&limit=1', [], $headers);
        $page->assertOk();
        $page->assertJsonPath('page', 1);
        $page->assertJsonPath('pages', 1);
        $page->assertJsonPath('total', 1);
        $page->assertJsonPath('items.0.id', $first->id);
    }

    public function test_edit_preserves_schedule_and_accepts_the_same_word_as_a_legacy_duplicate(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser(['username' => 'schedule', 'email' => 'schedule@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book', [
            'source' => 'from_text',
            'is_suspended' => true,
            'repetitions' => 4,
            'interval' => 25,
            'ease_factor' => 2.1,
            'next_review' => '2026-11-07',
        ]));
        $before = $this->schedule($card);
        $plan->is_active = false;
        $plan->save();
        $updated = $this->api('PUT', "/api/flashcards/{$card->id}", $this->content('  Book  ', ['definition' => ' New definition ']), $headers);
        $updated->assertOk();
        $card->refresh();
        $this->assertSame('Book', $card->word);
        $this->assertSame('New definition', $card->definition);
        $this->assertSame('from_text', $card->source);
        $this->assertTrue($card->is_suspended);
        $this->assertSame($before, $this->schedule($card));
        $this->assertSame(0, Progress::query()->count());

        $legacy = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $duplicate = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $this->api('PUT', "/api/flashcards/{$duplicate->id}", $this->content(), $headers)->assertOk();
        $this->assertSame($legacy->id, Flashcard::query()->where('word', 'book')->orderBy('id')->value('id'));
    }

    public function test_edit_duplicate_check_collapses_whitespace_inside_the_plan(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'norm', 'email' => 'norm@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        Flashcard::query()->create($this->card($user->id, $plan->id, "  TAKE\u{00a0}  off ", ['is_suspended' => true]));
        $conflict = $this->api('PUT', "/api/flashcards/{$card->id}", $this->content('take off'), $headers);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('detail', 'flashcard_duplicate');
        $this->assertSame('book', $card->fresh()->word);
        $other = $this->languagePlan($user, 'it-IT');
        Flashcard::query()->create($this->card($user->id, $other->id, 'libro'));
        $this->api('PUT', "/api/flashcards/{$card->id}", $this->content('libro'), $headers)->assertOk();
    }

    #[DataProvider('invalidEdits')]
    public function test_edit_rejects_invalid_content_and_protected_fields(array $changes): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'invalidedit', 'email' => 'invalidedit@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $this->api('PUT', "/api/flashcards/{$card->id}", $this->content('book', $changes), $headers)->assertStatus(422);
        $this->assertSame('An object with pages', $card->fresh()->definition);
    }

    public function test_suspension_preserves_progress_and_a_suspended_save_does_not_reactivate(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser(['username' => 'reactivate', 'email' => 'reactivate@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book', [
            'repetitions' => 4,
            'interval' => 20,
            'ease_factor' => 2.2,
        ]));
        $before = $this->schedule($card);
        foreach ([1, 2] as $ignored) {
            $suspended = $this->api('PUT', "/api/flashcards/{$card->id}/suspension", ['is_suspended' => true], $headers);
            $suspended->assertOk();
            $suspended->assertJsonPath('is_suspended', true);
        }
        $blocked = $this->api('POST', "/api/flashcards/{$card->id}/review", [
            'quality' => 5,
            'review_id' => '33333333-3333-4333-8333-333333333333',
        ], $headers);
        $blocked->assertStatus(409);
        $card->refresh();
        $this->assertSame($before, $this->schedule($card));
        $this->assertSame(0, Progress::query()->count());
        $this->api('PUT', "/api/flashcards/{$card->id}/suspension", ['is_suspended' => false], $headers)->assertOk();
        $card->refresh();
        $this->assertSame($before, $this->schedule($card));
        $due = $this->api('GET', '/api/flashcards/due', [], $headers);
        $this->assertSame([$card->id], array_column($due->json('due'), 'id'));

        $saved = Flashcard::query()->create($this->card($user->id, $plan->id, 'kept', [
            'is_suspended' => true,
            'interval' => 6,
            'repetitions' => 2,
            'definition' => 'unchanged definition',
        ]));
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $fromWord = $this->api('POST', '/api/flashcards/from-word', ['word' => 'KEPT'], $headers);
        $fromWord->assertOk();
        $fromWord->assertJsonPath('id', $saved->id);
        $fromWord->assertJsonPath('is_suspended', true);
        $fromWord->assertJsonPath('definition', 'unchanged definition');
        $fromWord->assertJsonPath('repetitions', 2);
        $this->assertSame([], $llm->calls);
        $bulk = $this->api('POST', '/api/flashcards/bulk', [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'flashcards' => [$this->content('kept')],
        ], $headers);
        $bulk->assertJsonPath('created', 0);
    }

    #[DataProvider('invalidQualities')]
    public function test_review_rejects_invalid_scores_without_awarding_xp(mixed $quality): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'quality', 'email' => 'quality@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $card->refresh();
        $before = $this->schedule($card);
        $this->api('POST', "/api/flashcards/{$card->id}/review", [
            'quality' => $quality,
            'review_id' => '44444444-4444-4444-8444-444444444444',
        ], $headers)->assertStatus(422);
        $card->refresh();
        $this->assertSame($before, $this->schedule($card));
        $this->assertSame(0, Progress::query()->count());
    }

    public function test_failed_progress_update_does_not_commit_the_review(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'rollback', 'email' => 'rollback@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $card->refresh();
        $before = $this->schedule($card);
        $this->app->instance(ProgressService::class, new class extends ProgressService
        {
            public function updateDaily(
                int $userId,
                ?int $studyPlanId = null,
                bool $lessonCompleted = false,
                ?bool $exerciseCorrect = null,
                bool $flashcardReviewed = false,
                int $xp = 0,
                ?string $skill = null,
                ?float $skillScore = null,
                ?string $activityDate = null,
            ): Progress {
                throw new \RuntimeException('failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->api('POST', "/api/flashcards/{$card->id}/review", [
                'quality' => 4,
                'review_id' => '55555555-5555-4555-8555-555555555555',
            ], $headers);
            $this->fail('expected the progress write to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $e->getMessage());
        }
        $card->refresh();
        $this->assertSame($before, $this->schedule($card));
        $this->assertSame(0, Progress::query()->count());
        $this->assertSame(0, FlashcardReviewReceipt::query()->count());
    }

    #[DataProvider('managementActions')]
    public function test_management_cannot_access_another_users_cards(string $action): void
    {
        [$user] = $this->makeUser(['username' => 'owner2', 'email' => 'owner2@example.com']);
        [, $adminHeaders] = $this->makeAdmin();
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'book'));
        $path = "/api/flashcards/{$card->id}";
        $response = match ($action) {
            'edit' => $this->api('PUT', $path, $this->content(), $adminHeaders),
            'suspend' => $this->api('PUT', $path.'/suspension', ['is_suspended' => true], $adminHeaders),
            default => $this->api('DELETE', $path, [], $adminHeaders),
        };
        $response->assertStatus(404);
        $this->api('GET', '/api/flashcards/library', [], $adminHeaders)->assertStatus(404)
            ->assertJsonPath('detail', 'No active study plan found');
    }

    public function test_preferences_belong_to_the_account_and_need_no_plan(): void
    {
        [, $headers] = $this->makeUser(['username' => 'prefs', 'email' => 'prefs@example.com']);
        [, $adminHeaders] = $this->makeAdmin();
        $this->api('GET', '/api/flashcards/preferences', [], $headers)->assertExactJson(['back_content' => 'definition']);
        $this->api('PUT', '/api/flashcards/preferences', ['back_content' => 'translation'], $headers)->assertOk();
        $this->api('GET', '/api/flashcards/preferences', [], $headers)->assertExactJson(['back_content' => 'translation']);
        $this->api('GET', '/api/flashcards/preferences', [], $adminHeaders)->assertExactJson(['back_content' => 'definition']);
        $this->api('PUT', '/api/flashcards/preferences', ['back_content' => 'invalid'], $headers)->assertStatus(422);
        $this->api('GET', '/api/flashcards/library')->assertUnauthorized();
        $this->api('GET', '/api/flashcards/preferences')->assertUnauthorized();
    }

    #[DataProvider('oversizedFields')]
    public function test_oversized_content_rejects_the_whole_request(string $endpoint, string $field, int $limit): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'wide', 'email' => 'wide@example.com']);
        $plan = $this->seedPlan($user);
        $invalid = $this->content('hello', [$field => str_repeat('界', $limit + 1)]);
        $body = $endpoint === '' ? $invalid : [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'flashcards' => [$this->content('valid'), $invalid],
        ];
        $response = $this->api('POST', '/api/flashcards'.$endpoint, $body, $headers);
        $response->assertStatus(422);
        $locations = array_map(fn (array $error) => $error['loc'][array_key_last($error['loc'])], $response->json('detail'));
        $this->assertContains($field, $locations);
        $this->assertSame(0, Flashcard::query()->count());
    }

    #[DataProvider('createEndpoints')]
    public function test_storage_boundary_accepts_unicode_without_truncation(string $endpoint): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'unicode', 'email' => 'unicode@example.com']);
        $plan = $this->seedPlan($user);
        $card = $this->content('hello', ['word' => str_repeat('界', 100), 'translation' => str_repeat('🙂', 255)]);
        $body = $endpoint === '' ? $card : [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'flashcards' => [$card],
        ];
        $this->api('POST', '/api/flashcards'.$endpoint, $body, $headers)->assertOk();
        $saved = Flashcard::query()->first();
        $this->assertSame($card['word'], $saved->word);
        $this->assertSame($card['translation'], $saved->translation);
    }

    public function test_selected_word_is_validated_before_the_model_is_called(): void
    {
        [, $headers] = $this->makeUser(['username' => 'longword', 'email' => 'longword@example.com']);
        $this->seedPlan(User::query()->where('email', 'longword@example.com')->first());
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $this->api('POST', '/api/flashcards/from-word', ['word' => str_repeat('a', 101)], $headers)->assertStatus(422);
        $this->assertSame([], $llm->calls);
    }

    #[DataProvider('modelContentCases')]
    public function test_invalid_model_content_retries_and_never_persists_a_partial_set(string $endpoint, string $field, int $limit, bool $recovers): void
    {
        [$user, $headers] = $this->makeUser();
        $this->seedPlan($user);
        $shape = [
            'word' => 'hello',
            'definition' => 'a greeting',
            'example_sentence' => 'Hello!',
            'translation' => 'hola',
        ];
        $invalid = array_merge($shape, [$field => str_repeat('x', $limit + 1)]);
        $valid = $shape;
        $payload = function (array $card) use ($endpoint, $shape): string {
            $body = $endpoint === '/generate'
                ? ['flashcards' => [array_merge($shape, ['word' => 'first']), $card]]
                : $card;

            return json_encode($body, JSON_UNESCAPED_UNICODE);
        };
        $llm = new ScriptedLlm([$payload($invalid), $payload($recovers ? $valid : $invalid)]);
        $this->app->instance(LlmClient::class, $llm);
        $body = $endpoint === '/generate' ? ['topic' => 'greetings', 'count' => 2] : ['word' => 'hello'];
        $response = $this->api('POST', '/api/flashcards'.$endpoint, $body, $headers);
        $this->assertCount(2, $llm->calls);
        if ($recovers) {
            $response->assertOk();
            $cards = Flashcard::query()->get();
            $this->assertCount($endpoint === '/generate' ? 2 : 1, $cards);
            $this->assertTrue($cards->contains(fn (Flashcard $card) => $card->word === 'hello' && $card->translation === 'hola'));
        } else {
            $response->assertStatus(502);
            $response->assertJsonPath('detail', 'ai_service_error');
            $this->assertSame(0, Flashcard::query()->count());
        }
    }

    #[DataProvider('cleanedWords')]
    public function test_lookup_validates_the_cleaned_word_inside_the_model_retry(string $word, bool $recovers): void
    {
        [$user, $headers] = $this->makeUser();
        $this->seedPlan($user);
        $invalid = json_encode($this->content($word));
        $recovered = json_encode($this->content('  book (noun)  '));
        $llm = new ScriptedLlm([$invalid, $recovers ? $recovered : $invalid]);
        $this->app->instance(LlmClient::class, $llm);
        $response = $this->api('POST', '/api/flashcards/from-word', ['word' => 'book'], $headers);
        $this->assertCount(2, $llm->calls);
        if ($recovers) {
            $response->assertOk();
            $response->assertJsonPath('word', 'book');
            $response->assertJsonPath('source', 'from_text');
        } else {
            $response->assertStatus(502);
            $response->assertJsonPath('detail', 'ai_service_error');
        }
        $cards = Flashcard::query()->get();
        $this->assertCount($recovers ? 1 : 0, $cards);
        $this->assertTrue($cards->every(fn (Flashcard $card) => trim($card->word) !== '' && $card->source === 'from_text'));
    }

    public function test_generation_keeps_empty_and_duplicate_counters(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'counters', 'email' => 'counters@example.com']);
        $this->seedPlan($user);
        $llm = new ScriptedLlm([json_encode([
            'flashcards' => [
                $this->content('(noun)'),
                $this->content('book'),
                $this->content('book (noun)'),
            ],
        ])]);
        $this->app->instance(LlmClient::class, $llm);
        $response = $this->api('POST', '/api/flashcards/generate', ['topic' => 'objects', 'count' => 3], $headers);
        $response->assertOk();
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('skipped_empty', 1);
        $response->assertJsonPath('skipped_duplicates', 1);
        $this->assertSame(['book'], $response->json('flashcards.*.word'));
        $this->assertCount(1, $llm->calls);
        $this->assertSame(1, Flashcard::query()->count());
    }

    public function test_review_receipts_replay_once_and_keep_ownership(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser(['username' => 'receipt', 'email' => 'receipt@example.com']);
        [, $adminHeaders] = $this->makeAdmin();
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'hello', [
            'definition' => 'greeting',
            'example_sentence' => 'Hello!',
            'translation' => 'hola',
        ]));
        $payload = ['quality' => 4, 'review_id' => '66666666-6666-4666-8666-666666666666'];
        $path = "/api/flashcards/{$card->id}/review";
        $first = $this->api('POST', $path, $payload, $headers);
        $first->assertOk();
        $retry = $this->api('POST', $path, $payload, $headers);
        $retry->assertOk();
        $this->assertSame($first->json(), $retry->json());
        $card->refresh();
        $this->assertSame(1, $card->repetitions);
        $this->assertSame(1, $card->interval);
        $this->assertSame('2026-10-14', JsonDate::date($card->next_review));
        $this->assertSame(2, (int) Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));
        $this->assertSame(1, FlashcardReviewReceipt::query()->count());

        $second = $this->api('POST', $path, [
            'quality' => 5,
            'review_id' => '77777777-7777-4777-8777-777777777777',
        ], $headers);
        $second->assertOk();
        $this->api('POST', $path, $payload, $headers)->assertJson($first->json());
        $this->api('POST', $path, ['quality' => 0, 'review_id' => $payload['review_id']], $headers)->assertStatus(409);
        $card->refresh();
        $this->assertSame(2, $card->repetitions);
        $this->assertSame(6, $card->interval);
        $this->assertSame(4, (int) Progress::query()->sum('xp_earned'));

        $card->word = 'edited';
        $card->is_suspended = true;
        $card->save();
        $this->api('POST', $path, $payload, $headers)->assertJson($first->json());
        $this->api('POST', $path, $payload, $adminHeaders)->assertStatus(404);
        $card->refresh();
        $this->assertSame('edited', $card->word);
        $this->assertTrue($card->is_suspended);
        $this->api('DELETE', "/api/flashcards/{$card->id}", [], $headers)->assertNoContent();
        $this->assertSame(0, FlashcardReviewReceipt::query()->count());
    }

    #[DataProvider('invalidReviewIds')]
    public function test_review_requires_a_valid_attempt_identity(array $extra): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'identity', 'email' => 'identity@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create($this->card($user->id, $plan->id, 'hello'));
        $this->api('POST', "/api/flashcards/{$card->id}/review", ['quality' => 4, ...$extra], $headers)->assertStatus(422);
    }

    public function test_stale_bulk_import_is_rejected_after_the_language_changes(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'stale', 'email' => 'stale@example.com']);
        $english = $this->seedPlan($user, ['target_language' => 'en-US']);
        Flashcard::query()->create($this->card($user->id, $english->id, 'hello'));
        $this->api('POST', '/api/languages', ['target_language' => 'it-IT'], $headers)->assertStatus(201);
        $italian = $this->seedPlan($user->fresh(), ['target_language' => 'it-IT']);
        $captured = [
            'study_plan_id' => $english->id,
            'target_language' => 'en-US',
            'flashcards' => [[
                'word' => 'goodbye',
                'definition' => 'farewell',
                'example_sentence' => 'Goodbye!',
                'translation' => 'adiós',
            ]],
        ];
        $stale = $this->api('POST', '/api/flashcards/bulk', $captured, $headers);
        $stale->assertStatus(409);
        $stale->assertJsonPath('detail', 'flashcard_context_changed');
        $this->assertSame(0, Flashcard::query()->where('study_plan_id', $italian->id)->count());
        $current = [
            'study_plan_id' => $italian->id,
            'target_language' => 'it-IT',
            'flashcards' => [[
                'word' => 'arrivederci',
                'definition' => 'farewell',
                'example_sentence' => 'Arrivederci!',
                'translation' => 'adiós',
            ]],
        ];
        $this->api('POST', '/api/flashcards/bulk', $current, $headers)->assertJsonPath('created', 1);
        $this->api('POST', '/api/flashcards/bulk', $current, $headers)->assertJsonPath('created', 0);
    }

    #[DataProvider('bulkMismatches')]
    public function test_bulk_validates_plan_and_language_before_writing(string $field, int|string $value): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'mismatch', 'email' => 'mismatch@example.com']);
        $plan = $this->seedPlan($user);
        Flashcard::query()->create($this->card($user->id, $plan->id, 'hello'));
        $payload = [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'flashcards' => [[
                'word' => 'new',
                'definition' => 'new',
                'example_sentence' => '',
                'translation' => '',
            ]],
            $field => $value,
        ];
        $this->api('POST', '/api/flashcards/bulk', $payload, $headers)->assertStatus(409);
        $this->assertSame(1, Flashcard::query()->count());
    }

    public function test_assessment_bank_and_language_sessions_stay_isolated(): void
    {
        [, $headers] = $this->makeUser(['username' => 'bank', 'email' => 'bank@example.com']);
        $this->api('GET', '/api/assessment/bank')->assertUnauthorized();
        $bank = $this->api('GET', '/api/assessment/bank?language=ja-JP', [], $headers);
        $bank->assertOk();
        $this->assertTrue(collect($bank->json('questions'))->contains(fn (array $question) => $question['id'] === 'ja-g-a1-001'));
        $fallback = $this->api('GET', '/api/assessment/bank?language=xx-XX', [], $headers);
        $this->assertSame(
            LearningCatalog::assessmentBank('en-GB')[0]['id'],
            $fallback->json('questions.0.id'),
        );

        $quiz = static fn (string $question): array => ['questions' => [[
            'id' => 1,
            'type' => 'multiple_choice',
            'difficulty' => 'A1',
            'question' => $question,
            'options' => ['A', 'B'],
            'correct_answer' => 'A',
        ]]];
        $this->app->instance(LlmClient::class, new ScriptedLlm([$quiz('Choose the verb.'), $quiz('Elige el verbo.')]));
        $this->api('GET', '/api/assessment/start?language=en-US', [], $headers)->assertOk();
        $this->api('GET', '/api/assessment/start?language=es-ES', [], $headers)->assertOk();
        $redis = app(ArrayRedis::class);
        $userId = User::query()->where('email', 'bank@example.com')->value('id');
        $english = json_decode($redis->get('assessment:'.$userId.':en-US'), true);
        $spanish = json_decode($redis->get('assessment:'.$userId.':es-ES'), true);
        $this->assertSame('en-US', $english['target_language']);
        $this->assertSame('es-ES', $spanish['target_language']);
        $this->app->instance(LlmClient::class, new ScriptedLlm([[
            'cefr_level' => 'B1',
            'score' => 0.8,
            'analysis' => 'Good',
            'strengths' => ['grammar'],
            'weaknesses' => [],
        ]]));
        $submit = $this->api('POST', '/api/assessment/submit', [
            'answers' => [['question_id' => 1, 'answer' => 'A']],
            'target_language' => 'en-US',
        ], $headers);
        $submit->assertOk();
        $submit->assertJsonPath('cefr_level', 'B1');
        $this->assertNull($redis->get('assessment:'.$userId.':en-US'));
        $this->assertSame('es-ES', json_decode($redis->get('assessment:'.$userId.':es-ES'), true)['target_language']);
    }

    #[DataProvider('completionSlots')]
    public function test_today_does_not_generate_a_lesson_for_the_completion_slot(string $unitId, string $lessonType, string $title): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->completionPlan($user, $unitId, $lessonType, $title);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertOk();
        $today->assertJsonPath('lessons', []);
        $today->assertJsonPath('completion.state', 'ready');
        $this->assertSame([], $llm->calls);
        $this->assertSame(0, Lesson::query()->where('study_plan_id', $plan->id)->count());
    }

    public function test_today_final_slot_is_idempotent(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'idem', 'email' => 'idem@example.com']);
        $plan = $this->completionPlan($user);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $first = $this->api('GET', '/api/study-plan/today', [], $headers);
        $second = $this->api('GET', '/api/study-plan/today', [], $headers);
        $this->assertSame($first->json(), $second->json());
        $first->assertJsonPath('progress_day', 1);
        $this->assertSame([], $llm->calls);
        $this->assertSame(0, Lesson::query()->where('study_plan_id', $plan->id)->count());
    }

    #[DataProvider('completionSlots')]
    public function test_today_returns_a_legacy_completion_lesson_without_regenerating_it(string $unitId, string $lessonType, string $title): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->completionPlan($user, $unitId, $lessonType, $title);
        $legacy = $this->legacyLesson($plan, false, $unitId, $lessonType, $title);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertOk();
        $this->assertCount(1, $today->json('lessons'));
        $today->assertJsonPath('lessons.0.id', $legacy->id);
        $today->assertJsonPath('completion.state', 'ready');
        $this->assertSame([], $llm->calls);
        $this->assertSame(1, Lesson::query()->where('study_plan_id', $plan->id)->count());
    }

    #[DataProvider('completionSlots')]
    public function test_today_advances_past_a_completed_legacy_completion_lesson(string $unitId, string $lessonType, string $title): void
    {
        [$user, $headers] = $this->makeUser();
        $this->completionPlan($user, $unitId, $lessonType, $title);
        $plan = StudyPlan::query()->where('user_id', $user->id)->first();
        $this->legacyLesson($plan, true, $unitId, $lessonType, $title);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertJsonPath('progress_day', 2);
        $today->assertJsonPath('lessons', []);
        $today->assertJsonPath('completion.state', 'ready');
        $this->assertSame([], $llm->calls);
    }

    #[DataProvider('completionSlots')]
    public function test_today_reports_taken_state_and_hides_the_legacy_lesson(string $unitId, string $lessonType, string $title): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->completionPlan($user, $unitId, $lessonType, $title, [
            'completion_test_taken' => true,
            'completion_test_score' => 0.82,
            'completion_test_recommendation' => 'advance',
        ]);
        $this->legacyLesson($plan, false, $unitId, $lessonType, $title);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertJsonPath('lessons', []);
        $this->assertSame('taken', $today->json('completion.state'));
        $this->assertEquals(0.82, $today->json('completion.score'));
        $this->assertSame('advance', $today->json('completion.recommendation'));
        $this->assertSame('A2', $today->json('completion.next_level'));
        $this->assertSame([], $llm->calls);
        $this->assertSame(1, Lesson::query()->where('study_plan_id', $plan->id)->count());
    }

    public function test_today_reports_taken_extend_and_ready_after_the_plan_is_exhausted(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'exhausted', 'email' => 'exhausted@example.com']);
        $this->completionPlan($user, 'completion-test', 'review', 'Level A1 Completion Test', [
            'progress_day' => 2,
            'completion_test_taken' => true,
            'completion_test_score' => 0.55,
            'completion_test_recommendation' => 'extend',
        ]);
        $taken = $this->api('GET', '/api/study-plan/today', [], $headers);
        $taken->assertJsonPath('lessons', []);
        $taken->assertJsonPath('completion.state', 'taken');
        $taken->assertJsonPath('completion.recommendation', 'extend');
        $taken->assertJsonPath('completion.next_level', null);

        StudyPlan::query()->where('user_id', $user->id)->update([
            'completion_test_taken' => false,
            'completion_test_score' => null,
            'completion_test_recommendation' => null,
        ]);
        $ready = $this->api('GET', '/api/study-plan/today', [], $headers);
        $ready->assertJsonPath('lessons', []);
        $ready->assertJsonPath('completion.state', 'ready');
    }

    public function test_pending_lessons_block_the_completion_test_until_they_are_done(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'pending', 'email' => 'pending@example.com']);
        $plan = $this->completionPlan($user);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Day 1 Lesson',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'unit_id' => 'a1_unit_1',
            'content' => [],
            'is_completed' => false,
        ]);
        $llm = new ScriptedLlm;
        $this->app->instance(LlmClient::class, $llm);
        $blocked = $this->api('GET', '/api/study-plan/today', [], $headers);
        $blocked->assertJsonPath('pending_count', 1);
        $blocked->assertJsonPath('completion.state', 'in_progress');
        $blocked->assertJsonPath('lessons', []);
        $this->assertSame([], $llm->calls);
        $lesson->is_completed = true;
        $lesson->save();
        $unlocked = $this->api('GET', '/api/study-plan/today', [], $headers);
        $unlocked->assertJsonPath('pending_count', 0);
        $unlocked->assertJsonPath('completion.state', 'ready');

        $lesson->is_completed = false;
        $lesson->save();
        $plan->progress_day = 2;
        $plan->save();
        $exhausted = $this->api('GET', '/api/study-plan/today', [], $headers);
        $exhausted->assertJsonPath('progress_day', 2);
        $exhausted->assertJsonPath('pending_count', 1);
        $exhausted->assertJsonPath('completion.state', 'in_progress');
        $exhausted->assertJsonPath('lessons', []);
    }

    #[DataProvider('completionSlots')]
    public function test_pending_lessons_keep_the_legacy_slot_until_the_test_is_taken(string $unitId, string $lessonType, string $title): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->completionPlan($user, $unitId, $lessonType, $title, ['progress_day' => 2]);
        $legacy = $this->legacyLesson($plan, false, $unitId, $lessonType, $title);
        $pending = $this->api('GET', '/api/study-plan/pending-lessons', [], $headers);
        $pending->assertOk();
        $this->assertSame([$legacy->id], array_column($pending->json(), 'id'));

        $plan->completion_test_taken = true;
        $plan->completion_test_score = 0.4;
        $plan->completion_test_recommendation = 'repeat';
        $plan->save();
        $hidden = $this->api('GET', '/api/study-plan/pending-lessons', [], $headers);
        $hidden->assertExactJson([]);
        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertJsonPath('pending_count', 0);
    }

    public function test_completion_state_follows_the_active_language(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'langs', 'email' => 'langs@example.com']);
        $english = $this->completionPlan($user, 'completion-test', 'review', 'Level A1 Completion Test', [
            'completion_test_taken' => true,
            'completion_test_score' => 0.9,
            'completion_test_recommendation' => 'advance',
        ]);
        $spanishLanguage = UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => 'es-ES',
            'is_active' => false,
            'created_at' => Clock::now(),
        ]);
        $spanish = $this->completionPlan($user, 'completion-test', 'review', 'Examen de nivel A1', [
            'target_language' => 'es-ES',
            'user_language_id' => $spanishLanguage->id,
        ]);
        $enToday = $this->api('GET', '/api/study-plan/today', [], $headers);
        $enToday->assertJsonPath('plan_id', $english->id);
        $enToday->assertJsonPath('completion.state', 'taken');
        UserLanguage::query()->where('user_id', $user->id)->update(['is_active' => false]);
        UserLanguage::query()->where('id', $spanishLanguage->id)->update(['is_active' => true]);
        $esToday = $this->api('GET', '/api/study-plan/today', [], $headers);
        $esToday->assertJsonPath('plan_id', $spanish->id);
        $esToday->assertJsonPath('completion.state', 'ready');
    }

    public function test_languages_plans_and_progress_stay_isolated(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'multi', 'email' => 'multi@example.com']);
        $english = $this->seedPlan($user, ['cefr_level' => 'B1']);
        $this->api('POST', '/api/languages', ['target_language' => 'es-ES'], $headers)->assertStatus(201)
            ->assertJsonPath('is_active', true);
        $this->api('POST', '/api/languages', ['target_language' => 'es-ES'], $headers)->assertStatus(409);
        $this->api('GET', '/api/languages/active', [], $headers)->assertJsonPath('target_language', 'es-ES');
        $this->api('PUT', '/api/languages/active', ['target_language' => 'en-US'], $headers)->assertOk()
            ->assertJsonPath('target_language', 'en-US');
        $this->api('POST', '/api/languages', ['target_language' => 'xx-XX'], $headers)->assertStatus(422);
        $this->api('POST', '/api/languages', ['target_language' => 'ja-JP'], $headers)->assertStatus(201);
        $this->api('POST', '/api/languages', ['target_language' => 'ko-KR'], $headers)->assertStatus(201);
        $this->api('POST', '/api/languages', ['target_language' => 'zh-CN'], $headers)->assertStatus(201);
        $this->api('PUT', '/api/languages/active', ['target_language' => 'en-US'], $headers)->assertOk();

        $listed = $this->api('GET', '/api/languages', [], $headers);
        $codes = array_column($listed->json('languages'), 'target_language');
        $this->assertContains('en-US', $codes);
        $this->assertContains('ja-JP', $listed->json('all_supported_languages'));
        $this->assertContains('ko-KR', $listed->json('all_supported_languages'));
        $this->assertContains('zh-CN', $listed->json('all_supported_languages'));

        $spanishLanguage = UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->first();
        $spanish = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $spanishLanguage->id,
            'cefr_level' => 'A1',
            'target_language' => 'es-ES',
            'goals' => ['vocabulary'],
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'current_unit' => '',
            'progress_day' => 0,
            'generated_plan' => ['weekly_plan' => []],
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);
        $this->assertTrue($english->fresh()->is_active);
        $this->assertTrue($spanish->is_active);
        try {
            StudyPlan::query()->create([
                'user_id' => $user->id,
                'user_language_id' => $english->user_language_id,
                'cefr_level' => 'A1',
                'target_language' => 'en-US',
                'goals' => ['grammar'],
                'duration_weeks' => 4,
                'days_per_week' => 4,
                'current_unit' => '',
                'progress_day' => 0,
                'generated_plan' => ['weekly_plan' => []],
                'is_active' => true,
                'created_at' => Clock::now(),
            ]);
            $this->fail('expected the active-plan unique index to reject a second English plan');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $english->id,
            'date' => Clock::today(),
            'xp_earned' => 100,
            'streak_day' => 5,
            'lessons_completed' => 3,
            'skills' => [],
            'skill_updates' => [],
        ]);
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $spanish->id,
            'date' => Clock::today(),
            'xp_earned' => 50,
            'streak_day' => 2,
            'lessons_completed' => 1,
            'skills' => [],
            'skill_updates' => [],
        ]);
        $this->assertSame(1, Progress::query()->where('study_plan_id', $english->id)->count());
        $this->assertSame(1, Progress::query()->where('study_plan_id', $spanish->id)->count());
        $this->api('GET', '/api/progress/summary', [], $headers)->assertJsonPath('total_xp', 100);
        UserLanguage::query()->where('user_id', $user->id)->update(['is_active' => false]);
        $spanishLanguage->is_active = true;
        $spanishLanguage->save();
        $this->api('GET', '/api/progress/summary', [], $headers)->assertJsonPath('total_xp', 50);
        UserLanguage::query()->where('user_id', $user->id)->update(['is_active' => false]);
        UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'en-US')->update(['is_active' => true]);

        Flashcard::query()->create($this->card($user->id, $english->id, 'dog'));
        Flashcard::query()->create($this->card($user->id, $spanish->id, 'casa'));
        $words = collect($this->api('GET', '/api/flashcards/all', [], $headers)->json())->pluck('word');
        $this->assertTrue($words->contains('dog'));
        $this->assertFalse($words->contains('casa'));
        $this->assertSame(1, Flashcard::query()->where('study_plan_id', $english->id)->count());
        $this->assertSame(1, Flashcard::query()->where('study_plan_id', $spanish->id)->count());

        $generated = $this->api('POST', '/api/study-plan/generate', [
            'cefr_level' => 'A2',
            'goals' => ['vocabulary'],
            'target_language' => 'es-ES',
            'duration_weeks' => 4,
            'days_per_week' => 4,
        ], $headers);
        $generated->assertOk();
        $generated->assertJsonPath('cefr_level', 'A2');
        $this->assertNotNull(StudyPlan::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->where('cefr_level', 'A2')->first());
        $this->assertTrue($english->fresh()->is_active);

        Memory::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $spanish->id,
            'content' => 'User enjoys cooking',
            'source' => 'chat',
            'created_at' => Clock::now(),
        ]);
        $this->api('DELETE', '/api/languages/en-US', [], $headers)->assertStatus(400);
        $this->api('PUT', '/api/languages/active', ['target_language' => 'ja-JP'], $headers)->assertOk();
        $this->api('DELETE', '/api/languages/ja-JP', [], $headers)->assertStatus(400);
        $this->api('PUT', '/api/languages/active', ['target_language' => 'en-US'], $headers)->assertOk();
        $this->api('DELETE', '/api/languages/es-ES', [], $headers)->assertNoContent();
        $this->assertNull(UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->first());
        $memory = Memory::query()->where('user_id', $user->id)->where('content', 'User enjoys cooking')->first();
        $this->assertNotNull($memory);
        $this->assertNull($memory->study_plan_id);
    }

    public function test_onboarding_creates_the_user_language(): void
    {
        $user = User::query()->create([
            'username' => 'freshuser',
            'email' => 'fresh@example.com',
            'display_name' => 'Fresh',
            'hashed_password' => 'hashed',
            'role' => 'user',
            'native_language' => 'fr',
            'target_language' => 'en-US',
            'is_active' => true,
            'is_verified' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        $headers = ['Authorization' => 'Bearer '.Jwt::createAccessToken($user->id, $user->role)];
        $this->api('PATCH', '/api/auth/me', ['target_language' => 'es-ES'], $headers)->assertOk();
        $language = UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->first();
        $this->assertNotNull($language);
        $this->assertTrue($language->is_active);
    }

    public static function completionMatrix(): array
    {
        $cases = [];
        foreach ([false, true] as $already) {
            foreach ([false, true] as $withUnit) {
                foreach ([false, true] as $testTaken) {
                    $name = ($already ? 'done' : 'fresh').' '.($withUnit ? 'unit' : 'no-unit').' '.($testTaken ? 'tested' : 'untested');
                    $cases[$name] = [$already, $withUnit, $testTaken];
                }
            }
        }

        return $cases;
    }

    public static function alreadyCompleted(): array
    {
        return ['fresh' => [false], 'done' => [true]];
    }

    public static function streakAges(): array
    {
        return [
            'today' => [0, 4],
            'yesterday' => [1, 4],
            'two days' => [2, 0],
            'twenty days' => [20, 0],
        ];
    }

    public static function invalidEdits(): array
    {
        return [
            'blank word' => [['word' => '  ']],
            'long word' => [['word' => str_repeat('w', 101)]],
            'blank definition' => [['definition' => "\t"]],
            'long translation' => [['translation' => str_repeat('t', 256)]],
            'long example' => [['example_sentence' => str_repeat('x', 10001)]],
            'plan id' => [['study_plan_id' => 999]],
            'repetitions' => [['repetitions' => 20]],
            'source' => [['source' => 'from_text']],
            'null definition' => [['definition' => null]],
        ];
    }

    public static function invalidQualities(): array
    {
        return [
            'negative' => [-1],
            'six' => [6],
            'hundred' => [100],
            'fraction' => [3.5],
            'string' => ['4'],
            'bool' => [true],
        ];
    }

    public static function managementActions(): array
    {
        return ['edit' => ['edit'], 'suspend' => ['suspend'], 'delete' => ['delete']];
    }

    public static function oversizedFields(): array
    {
        $cases = [];
        foreach (['', '/bulk'] as $endpoint) {
            foreach ([['word', 100], ['translation', 255]] as [$field, $limit]) {
                $cases[$endpoint.' '.$field] = [$endpoint, $field, $limit];
            }
        }

        return $cases;
    }

    public static function createEndpoints(): array
    {
        return ['single' => [''], 'bulk' => ['/bulk']];
    }

    public static function modelContentCases(): array
    {
        $cases = [];
        foreach (['/generate', '/from-word'] as $endpoint) {
            foreach ([['word', 100], ['translation', 255]] as [$field, $limit]) {
                foreach ([true, false] as $recovers) {
                    $cases[$endpoint.' '.$field.' '.($recovers ? 'recovers' : 'fails')] = [$endpoint, $field, $limit, $recovers];
                }
            }
        }

        return $cases;
    }

    public static function cleanedWords(): array
    {
        $cases = [];
        foreach (['', " \u{3000} ", '(noun)', '""', "'  '"] as $word) {
            foreach ([true, false] as $recovers) {
                $cases[json_encode($word).' '.($recovers ? 'recovers' : 'fails')] = [$word, $recovers];
            }
        }

        return $cases;
    }

    public static function invalidReviewIds(): array
    {
        return [
            'missing' => [[]],
            'invalid' => [['review_id' => 'invalid']],
            'null' => [['review_id' => null]],
        ];
    }

    public static function bulkMismatches(): array
    {
        return [
            'plan' => ['study_plan_id', 999999],
            'language' => ['target_language', 'it-IT'],
        ];
    }

    public static function completionSlots(): array
    {
        return [
            'current' => ['completion-test', 'review', 'Level A1 Completion Test'],
            'legacy' => ['level-test', 'level_test', 'Level Completion Test — A1'],
        ];
    }

    private function lesson(StudyPlan $plan, string $title, string $level = 'A1', array $overrides = []): Lesson
    {
        return Lesson::query()->create(array_merge([
            'study_plan_id' => $plan->id,
            'title' => $title,
            'lesson_type' => 'vocabulary',
            'cefr_level' => $level,
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
        ], $overrides));
    }

    /**
     * @return array{0: StudyPlan, 1: Lesson}
     */
    private function completionCase(User $user, bool $already, bool $withUnit, bool $testTaken): array
    {
        $unitId = $withUnit ? LearningCatalog::units('A1', 'en-GB')[0]['id'] : null;
        $plan = $this->seedPlan($user, [
            'target_language' => 'en-GB',
            'cefr_level' => 'A1',
            'completion_test_taken' => $testTaken,
            'completion_test_score' => $testTaken ? 0.2 : null,
            'generated_plan' => [
                'weekly_plan' => [[
                    'week' => 1,
                    'days' => [
                        ['day' => 1, 'title' => 'Last lesson', 'unit_id' => $unitId],
                        ['day' => 2, 'title' => 'Test', 'unit_id' => 'completion-test'],
                    ],
                ]],
            ],
        ]);
        $historical = Carbon::parse('2026-09-01 12:00:00', 'UTC');
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Last lesson',
            'unit_id' => $unitId,
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
            'is_completed' => $already,
            'completed_at' => $already ? $historical : null,
        ]);
        if ($already) {
            Progress::query()->create([
                'user_id' => $user->id,
                'study_plan_id' => $plan->id,
                'date' => '2026-09-01',
                'xp_earned' => 20,
                'lessons_completed' => 1,
                'skills' => ['grammar' => 0.8],
                'skill_updates' => [],
                'streak_day' => 1,
            ]);
            if ($withUnit) {
                UserCompetency::query()->create([
                    'user_id' => $user->id,
                    'study_plan_id' => $plan->id,
                    'unit_id' => $unitId,
                    'competency_text' => LearningCatalog::units('A1', 'en-GB')[0]['competency_checklist'][0],
                    'score' => 0.8,
                    'mastered' => true,
                    'updated_at' => $historical,
                ]);
            }
        }

        return [$plan, $lesson];
    }

    /**
     * @return list<array{0: string, 1: float, 2: bool, 3: string|null}>
     */
    private function competencySnapshot(): array
    {
        return UserCompetency::query()->orderBy('id')->get()->map(fn (UserCompetency $row) => [
            $row->competency_text,
            (float) $row->score,
            (bool) $row->mastered,
            JsonDate::dateTime($row->updated_at),
        ])->all();
    }

    private function completionPlan(User $user, string $unitId = 'completion-test', string $lessonType = 'review', string $title = 'Level A1 Completion Test', array $overrides = []): StudyPlan
    {
        return $this->seedPlan($user, array_merge([
            'cefr_level' => 'A1',
            'duration_weeks' => 1,
            'days_per_week' => 2,
            'progress_day' => 1,
            'current_unit' => 'a1_unit_1',
            'generated_plan' => [
                'title' => 'Test',
                'cefr_level' => 'A1',
                'duration_weeks' => 1,
                'days_per_week' => 2,
                'ends_with_test' => true,
                'weekly_plan' => [[
                    'week' => 1,
                    'theme' => 'basics',
                    'days' => [
                        [
                            'day' => 1,
                            'lesson_type' => 'grammar',
                            'title' => 'Day 1 Lesson',
                            'unit_id' => 'a1_unit_1',
                        ],
                        [
                            'day' => 2,
                            'lesson_type' => $lessonType,
                            'title' => $title,
                            'unit_id' => $unitId,
                        ],
                    ],
                ]],
            ],
        ], $overrides));
    }

    private function legacyLesson(StudyPlan $plan, bool $completed, string $unitId, string $lessonType, string $title): Lesson
    {
        return Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => $title,
            'lesson_type' => $lessonType,
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 2,
            'unit_id' => $unitId,
            'content' => [],
            'is_completed' => $completed,
        ]);
    }

    private function languagePlan(User $user, string $language): StudyPlan
    {
        $row = UserLanguage::query()->firstOrCreate(
            ['user_id' => $user->id, 'target_language' => $language],
            ['is_active' => false, 'created_at' => Clock::now()],
        );

        return StudyPlan::query()->create([
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
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function card(int $userId, int $planId, string $word, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $userId,
            'study_plan_id' => $planId,
            'word' => $word,
            'definition' => 'An object with pages',
            'example_sentence' => 'I read a book.',
            'translation' => 'libro',
            'next_review' => Clock::today(),
            'created_at' => Clock::now(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function content(string $word = 'book', array $changes = []): array
    {
        return array_merge([
            'word' => $word,
            'definition' => 'A better explanation',
            'example_sentence' => '',
            'translation' => 'libro',
        ], $changes);
    }

    /**
     * @return array{0: float, 1: int, 2: int, 3: string|null}
     */
    private function schedule(Flashcard $card): array
    {
        return [(float) $card->ease_factor, (int) $card->interval, (int) $card->repetitions, JsonDate::date($card->next_review)];
    }

}
