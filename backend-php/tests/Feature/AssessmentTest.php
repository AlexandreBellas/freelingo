<?php

namespace Tests\Feature;

use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmTimeoutException;
use App\Services\Llm\ScriptedLlm;
use Tests\LearningHelpers;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use LearningHelpers;

    public function test_start_is_unavailable_without_a_model(): void
    {
        [, $headers] = $this->makeUser();
        $this->api('GET', '/api/assessment/start', [], $headers)->assertStatus(503)
            ->assertJsonPath('detail', 'ai_service_unavailable');
    }

    public function test_start_strips_answers_when_scripted(): void
    {
        [, $headers] = $this->makeUser(['username' => 'quiz', 'email' => 'quiz@example.com']);
        $this->app->instance(LlmClient::class, new ScriptedLlm([[
            'questions' => [[
                'id' => 'q1',
                'skill' => 'grammar',
                'prompt' => 'Choose',
                'options' => ['a', 'b'],
                'correct' => 'a',
                'correct_answer' => 'a',
            ]],
        ]]));
        $started = $this->api('GET', '/api/assessment/start', [], $headers);
        $started->assertOk();
        $question = $started->json('quiz.questions.0');
        $this->assertArrayNotHasKey('correct', $question);
        $this->assertArrayNotHasKey('correct_answer', $question);
        $this->assertNotEmpty($started->json('session_id'));
    }

    public function test_start_timeout_is_504(): void
    {
        [, $headers] = $this->makeUser(['username' => 'slow', 'email' => 'slow@example.com']);
        $this->app->instance(LlmClient::class, new ScriptedLlm([new LlmTimeoutException('slow')]));
        $this->api('GET', '/api/assessment/start', [], $headers)->assertStatus(504)
            ->assertJsonPath('detail', 'LLM timed out generating assessment quiz.');
    }

    public function test_evaluate_picks_the_highest_reliable_level(): void
    {
        [, $headers] = $this->makeUser(['username' => 'eval', 'email' => 'eval@example.com']);
        $answers = [];
        foreach (['A1', 'A2'] as $level) {
            foreach ([true, true] as $correct) {
                $answers[] = [
                    'question_id' => $level.($correct ? 'a' : 'b'),
                    'skill' => 'grammar',
                    'difficulty' => $level,
                    'correct' => $correct,
                ];
            }
        }
        $response = $this->api('POST', '/api/assessment/evaluate', ['answers' => $answers], $headers);
        $response->assertOk();
        $response->assertJsonPath('cefr_level', 'A2');
        $response->assertJsonPath('skill_profile.grammar', 1);
        $response->assertJsonPath('strengths', ['grammar']);
    }

    public function test_complete_creates_a_plan_without_a_voice_trial_when_stripe_is_off(): void
    {
        [, $headers] = $this->makeUser(['username' => 'complete', 'email' => 'complete@example.com']);
        $response = $this->api('POST', '/api/assessment/complete', [
            'cefr_level' => 'A1',
            'goals' => ['grammar'],
        ], $headers);
        $response->assertOk();
        $response->assertJsonPath('cefr_level', 'A1');
        $response->assertJsonPath('voice_trial.available', false);
        $current = $this->api('GET', '/api/study-plan/current', [], $headers);
        $current->assertJsonPath('cefr_level', 'A1');
    }

    public function test_level_test_stays_locked_and_missing_results_are_explicit(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'level', 'email' => 'level@example.com']);
        $plan = $this->seedPlan($user, ['duration_weeks' => 4, 'days_per_week' => 4, 'progress_day' => 0]);
        $locked = $this->api('GET', "/api/assessment/level-test/questions/{$plan->id}", [], $headers);
        $locked->assertStatus(403);
        $locked->assertJsonPath('detail', 'Level test unlocks at the final day of the study plan once every pending lesson is completed.');
        $missing = $this->api('GET', "/api/assessment/level-test/result/{$plan->id}", [], $headers);
        $missing->assertStatus(404);
        $missing->assertJsonPath('detail', 'Level test result not found.');
        $this->api('GET', '/api/assessment/level-test/result/99999', [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'Level test result not found.');
    }

    public function test_submit_without_a_session_is_not_found(): void
    {
        [, $headers] = $this->makeUser(['username' => 'nosession', 'email' => 'nosession@example.com']);
        $this->api('POST', '/api/assessment/submit', ['answers' => []], $headers)->assertStatus(404);
    }
}
