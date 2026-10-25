<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Progress;
use App\Services\Llm\LlmClient;
use App\Services\Llm\ScriptedLlm;
use Tests\LearningHelpers;
use Tests\TestCase;

class LessonTest extends TestCase
{
    use LearningHelpers;

    public function test_missing_lesson_is_not_found(): void
    {
        [, $headers] = $this->makeUser();
        $this->api('GET', '/api/lessons/1', [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'Lesson not found');
    }

    public function test_multiple_choice_scores_letter_prefixes_and_rejects_a_second_answer(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user, ['cefr_level' => 'A2']);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Test',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A2',
            'week_number' => 1,
            'day_number' => 1,
            'content' => ['exercises' => []],
        ]);
        $exercise = Exercise::query()->create([
            'lesson_id' => $lesson->id,
            'exercise_type' => 'multiple_choice',
            'question' => 'What?',
            'options' => ['A', 'B'],
            'correct_answer' => 'works',
        ]);

        $correct = $this->api('POST', "/api/lessons/exercises/{$exercise->id}/answer", ['answer' => 'a. works'], $headers);
        $correct->assertOk();
        $correct->assertJsonPath('score', 1);
        $correct->assertJsonPath('feedback', 'Correcto!');

        $again = $this->api('POST', "/api/lessons/exercises/{$exercise->id}/answer", ['answer' => 'works'], $headers);
        $again->assertStatus(409);
        $again->assertJsonPath('detail', 'Exercise already answered');

        $missing = $this->api('POST', '/api/lessons/exercises/1/answer', [], $headers);
        $missing->assertStatus(422);
    }

    public function test_fill_blank_falls_back_when_the_model_is_unavailable(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'blank', 'email' => 'blank@example.com']);
        $plan = $this->seedPlan($user);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Blank',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
        ]);
        $exercise = Exercise::query()->create([
            'lesson_id' => $lesson->id,
            'exercise_type' => 'fill_blank',
            'question' => 'I ___ a student.',
            'correct_answer' => 'am/are',
        ]);
        $response = $this->api('POST', "/api/lessons/exercises/{$exercise->id}/answer", ['answer' => 'Am'], $headers);
        $response->assertOk();
        $response->assertJsonPath('score', 1);
        $entry = Progress::query()->where('study_plan_id', $plan->id)->first();
        $this->assertSame(5, $entry->xp_earned);
        $this->assertSame(1, $entry->exercises_correct);
    }

    public function test_complete_awards_lesson_xp_once(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'done', 'email' => 'done@example.com']);
        $plan = $this->seedPlan($user);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Done',
            'lesson_type' => 'vocabulary',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'unit_id' => 'a1-unit-1',
            'content' => [],
        ]);
        $first = $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers);
        $first->assertOk();
        $first->assertJsonPath('is_completed', true);
        $second = $this->api('POST', "/api/lessons/{$lesson->id}/complete", [], $headers);
        $second->assertOk();
        $this->assertSame(20, (int) Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));
    }

    public function test_regenerate_rejects_a_valid_exercise(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'regen', 'email' => 'regen@example.com']);
        $plan = $this->seedPlan($user);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Regen',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => ['exercises' => [['type' => 'multiple_choice']]],
        ]);
        $exercise = Exercise::query()->create([
            'lesson_id' => $lesson->id,
            'exercise_type' => 'multiple_choice',
            'question' => 'Pick one',
            'options' => ['yes', 'no'],
            'correct_answer' => 'yes',
        ]);
        $this->app->instance(LlmClient::class, new ScriptedLlm);
        $response = $this->api('POST', "/api/lessons/exercises/{$exercise->id}/regenerate", [], $headers);
        $response->assertStatus(400);
        $response->assertJsonPath('detail', 'Exercise does not need regeneration');
    }

    public function test_another_user_cannot_read_the_lesson(): void
    {
        [$owner] = $this->makeUser(['username' => 'owner', 'email' => 'owner@example.com']);
        [, $headers] = $this->makeUser(['username' => 'other', 'email' => 'other@example.com']);
        $plan = $this->seedPlan($owner);
        $lesson = Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Private',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
        ]);
        $this->api('GET', "/api/lessons/{$lesson->id}", [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'Lesson not found');
    }
}
