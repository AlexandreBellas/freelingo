<?php

namespace Tests\Feature;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Services\Learning\LearningCatalog;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\LearningHelpers;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use LearningHelpers;

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    public function test_summary_and_history_are_empty_without_a_plan(): void
    {
        [, $headers] = $this->makeUser();
        $summary = $this->api('GET', '/api/progress/summary', [], $headers);
        $summary->assertOk();
        $summary->assertJsonPath('total_xp', 0);
        $summary->assertJsonPath('current_streak', 0);
        $this->assertSame([], $summary->json('activity_week') ? array_filter($summary->json('activity_week'), fn ($day) => $day['active']) : []);
        $history = $this->api('GET', '/api/progress/history', [], $headers);
        $history->assertOk();
        $history->assertJsonPath('entries', []);
        $this->api('GET', '/api/progress/competencies', [], $headers)->assertOk()->assertExactJson([]);
    }

    public function test_summary_uses_the_active_plan_streak_and_vocabulary(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser(['username' => 'xp', 'email' => 'xp@example.com']);
        $plan = $this->seedPlan($user);
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => '2026-10-11',
            'xp_earned' => 20,
            'lessons_completed' => 1,
            'exercises_correct' => 4,
            'exercises_total' => 5,
            'streak_day' => 1,
            'skills' => ['grammar' => 0.5],
            'skill_updates' => [],
        ]);
        Progress::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'date' => '2026-10-13',
            'xp_earned' => 30,
            'lessons_completed' => 0,
            'exercises_correct' => 4,
            'exercises_total' => 5,
            'streak_day' => 3,
            'skills' => ['grammar' => 0.7],
            'skill_updates' => [],
        ]);
        $words = LearningCatalog::vocabularyWords('A1', 'en-US');
        Flashcard::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'word' => $words[0],
            'definition' => 'Definition',
            'example_sentence' => 'Example sentence.',
            'translation' => 'Translation',
            'repetitions' => 1,
            'next_review' => '2026-10-13',
            'created_at' => Clock::now(),
        ]);
        $summary = $this->api('GET', '/api/progress/summary', [], $headers);
        $summary->assertOk();
        $summary->assertJsonPath('total_xp', 50);
        $summary->assertJsonPath('current_streak', 3);
        $summary->assertJsonPath('accuracy', 0.8);
        $summary->assertJsonPath('today_xp', 30);
        $summary->assertJsonPath('vocabulary_level', 'A1');
        $summary->assertJsonPath('vocabulary_mastered', 1);
        $summary->assertJsonPath('vocabulary_total', count($words));

        $history = $this->api('GET', '/api/progress/history', [], $headers);
        $history->assertOk();
        $this->assertSame('2026-10-13', $history->json('entries.0.date'));
        $this->assertArrayNotHasKey('study_plan_id', $history->json('entries.0'));
        $this->assertArrayNotHasKey('skill_updates', $history->json('entries.0'));

        $this->api('GET', '/api/progress/competencies', [], $headers)->assertOk()->assertExactJson([]);
    }
}
