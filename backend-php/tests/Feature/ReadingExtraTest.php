<?php

namespace Tests\Feature;

use App\Models\ReadingExercise;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Redis\ArrayRedis;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReadingExtraTest extends TestCase
{
    public function test_generate_locked(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->plan($user, 'B1', 'en-US');
        $key = 'reading:generating:B1:en-US';
        $held = json_encode([
            'owner' => 'held',
            'until' => Clock::now()->addSeconds(120)->getTimestamp(),
        ]);
        app(ArrayRedis::class)->set($key, (string) $held);

        $response = $this->api('POST', '/api/reading/generate', [], $headers);

        $response->assertStatus(202);
        $this->assertSame('generating', $response->json('status'));
        $this->assertSame($held, app(ArrayRedis::class)->get($key));
    }

    public function test_next_wait_returns_exercise(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->plan($user, 'B1', 'en-US');
        app(ArrayRedis::class)->set('reading:generating:B1:en-US', json_encode([
            'owner' => 'held',
            'until' => Clock::now()->addSeconds(120)->getTimestamp(),
        ]));
        ReadingExercise::query()->create([
            'level' => 'B1',
            'target_language' => 'en-US',
            'exercise_type' => 'article',
            'topic' => 'Fast topic',
            'text' => 'Text for long-poll test.',
            'questions' => $this->questions(),
            'view_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
        app(ArrayRedis::class)->delete('reading:generating:B1:en-US');

        $response = $this->api('GET', '/api/reading/next?wait=true', [], $headers);

        $response->assertOk();
        $this->assertTrue($response->json('available'));
        $this->assertSame('Fast topic', $response->json('exercise.topic'));
    }

    public function test_submit_not_found(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->plan($user, 'B1', 'en-US');
        $next = $this->api('GET', '/api/reading/next', [], $headers);
        $next->assertOk();
        $answers = [];
        for ($index = 0; $index < 5; $index++) {
            $answers[(string) $index] = 'B';
        }

        $response = $this->api('POST', '/api/reading/attempt', [
            'exercise_id' => 99999,
            'answers' => $answers,
            'context' => $next->json('context'),
        ], $headers);

        $response->assertStatus(404);
        $this->assertSame('exercise_not_found', $response->json('detail'));
    }

    public function test_history_pagination(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->plan($user, 'B1', 'en-US');

        $response = $this->api('GET', '/api/reading/history?skip=0&limit=5', [], $headers);

        $response->assertOk();
        $this->assertSame(0, $response->json('skip'));
        $this->assertSame(5, $response->json('limit'));
        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('items'));
    }

    public function test_paywall_blocked(): void
    {
        [$user, $headers] = $this->makeUser(['subscription_status' => 'none']);
        $this->plan($user, 'B1', 'en-US');
        config(['freelingo.stripe_enabled' => true]);
        try {
            $this->api('GET', '/api/reading/next', [], $headers)->assertOk();
        } finally {
            config(['freelingo.stripe_enabled' => false]);
        }
    }

    private function plan(User $user, string $level, string $language): StudyPlan
    {
        $active = UserLanguage::query()->where('user_id', $user->id)->where('is_active', true)->firstOrFail();

        return StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $active->id,
            'cefr_level' => $level,
            'target_language' => $language,
            'goals' => ['grammar'],
            'current_unit' => '',
            'progress_day' => 0,
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'generated_plan' => [],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(): array
    {
        $rows = [];
        for ($index = 0; $index < 5; $index++) {
            $rows[] = [
                'index' => $index,
                'question' => 'Q'.$index.'?',
                'options' => ['A' => 'a', 'B' => 'correct', 'C' => 'c', 'D' => 'd'],
                'correct' => 'B',
            ];
        }

        return $rows;
    }
}
