<?php

namespace Tests\Feature;

use Tests\LearningHelpers;
use Tests\TestCase;

class StudyPlanTest extends TestCase
{
    use LearningHelpers;

    public function test_current_plan_is_null_until_one_is_generated(): void
    {
        [, $headers] = $this->makeUser();
        $empty = $this->api('GET', '/api/study-plan/current', [], $headers);
        $empty->assertOk();
        $this->assertSame('null', $empty->getContent());

        $generated = $this->api('POST', '/api/study-plan/generate', [
            'cefr_level' => 'A2',
            'goals' => ['grammar', 'vocabulary'],
        ], $headers);
        $generated->assertOk();
        $generated->assertJsonPath('cefr_level', 'A2');
        $generated->assertJsonPath('goals', ['grammar', 'vocabulary']);
        $generated->assertJsonPath('is_active', true);
        $generated->assertJsonPath('duration_weeks', 12);
        $generated->assertJsonPath('days_per_week', 4);
        $this->assertArrayNotHasKey('target_language', $generated->json());

        $current = $this->api('GET', '/api/study-plan/current', [], $headers);
        $current->assertOk();
        $current->assertJsonPath('cefr_level', 'A2');
    }

    public function test_generate_requires_auth_and_rejects_unknown_levels_and_short_plans(): void
    {
        $this->api('POST', '/api/study-plan/generate', ['cefr_level' => 'A1'])->assertUnauthorized();
        [, $headers] = $this->makeUser(['username' => 'planner', 'email' => 'planner@example.com']);
        $unknown = $this->api('POST', '/api/study-plan/generate', ['cefr_level' => 'Z9'], $headers);
        $unknown->assertStatus(422);
        $this->assertStringContainsString('Unknown CEFR level', $unknown->json('detail.0.msg'));

        $short = $this->api('POST', '/api/study-plan/generate', [
            'cefr_level' => 'A1',
            'duration_weeks' => 1,
            'days_per_week' => 1,
        ], $headers);
        $short->assertStatus(400);
        $this->assertStringContainsString('too short', $short->json('detail'));
    }

    public function test_today_skip_and_pending_follow_the_active_plan(): void
    {
        [, $headers] = $this->makeUser(['username' => 'today', 'email' => 'today@example.com']);
        $this->api('GET', '/api/study-plan/today', [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'No active study plan found');
        $this->api('POST', '/api/study-plan/skip-day', [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'No active study plan found');

        $this->api('POST', '/api/study-plan/generate', [
            'cefr_level' => 'A1',
            'goals' => ['vocabulary'],
        ], $headers)->assertOk();

        $today = $this->api('GET', '/api/study-plan/today', [], $headers);
        $today->assertOk();
        $today->assertJsonPath('progress_day', 0);
        $today->assertJsonPath('total_days', 48);
        $today->assertJsonPath('pending_count', 0);
        $today->assertJsonPath('completion.state', 'in_progress');

        $skipped = $this->api('POST', '/api/study-plan/skip-day', [], $headers);
        $skipped->assertOk();
        $skipped->assertJsonPath('progress_day', 1);

        $pending = $this->api('GET', '/api/study-plan/pending-lessons', [], $headers);
        $pending->assertOk();
        $this->assertIsArray($pending->json());
    }

    public function test_skip_does_not_pass_the_final_day(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'finalday', 'email' => 'finalday@example.com']);
        $this->seedPlan($user, ['duration_weeks' => 1, 'days_per_week' => 1, 'progress_day' => 1, 'cefr_level' => 'A1']);
        $response = $this->api('POST', '/api/study-plan/skip-day', [], $headers);
        $response->assertOk();
        $response->assertJsonPath('progress_day', 1);
    }
}
