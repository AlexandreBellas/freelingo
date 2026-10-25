<?php

namespace Tests\Unit;

use App\Models\StudyPlan;
use App\Services\Learning\CompletionService;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\PlanCapacityException;
use App\Services\Learning\StudyPlanGenerator;
use App\Services\Prompts\LessonPrompts;
use Tests\TestCase;

class CompletionAndPlanTest extends TestCase
{
    public function test_completion_states_and_cefr_boundaries(): void
    {
        $service = new CompletionService;
        $plan = new StudyPlan([
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'progress_day' => 14,
            'cefr_level' => 'A1',
            'completion_test_taken' => false,
        ]);
        $this->assertSame(16, $service->totalPlanDays($plan));
        $this->assertFalse($service->positionReached($plan));
        $plan->progress_day = 15;
        $this->assertTrue($service->positionReached($plan));
        $this->assertSame('ready', $service->state($plan)['state']);
        $this->assertSame('in_progress', $service->state($plan, true)['state']);
        $this->assertFalse($service->isLevelTestEligible($plan, true));
        $plan->completion_test_taken = true;
        $plan->completion_test_score = 0.82;
        $plan->completion_test_recommendation = 'advance';
        $taken = $service->state($plan, true);
        $this->assertSame('taken', $taken['state']);
        $this->assertEquals(0.82, $taken['score']);
        $this->assertSame('advance', $taken['recommendation']);
        $this->assertSame('A2', $taken['next_level']);
        $plan->completion_test_recommendation = 'extend';
        $this->assertNull($service->state($plan)['next_level']);
        $plan->cefr_level = 'C2';
        $plan->completion_test_recommendation = 'advance';
        $this->assertNull($service->state($plan)['next_level']);
        $plan->progress_day = 10;
        $this->assertSame('in_progress', $service->state($plan)['state']);
        $this->assertTrue($service->isLevelTestEligible($plan));
        $this->assertSame('A2', $service->nextCefrLevel('A1'));
        $this->assertSame('C2', $service->nextCefrLevel('C1'));
        $this->assertNull($service->nextCefrLevel('C2'));
        $this->assertNull($service->nextCefrLevel('Z9'));
    }

    public function test_a1_english_distribution_reserves_the_completion_test(): void
    {
        $generator = new StudyPlanGenerator;
        $units = LearningCatalog::units('A1', 'en-GB');
        $this->assertCount(8, $units);
        $slots = $generator->distribute($units, 12, 4, 'en-GB');
        $this->assertCount(48, $slots);
        $this->assertSame('grammar', $slots[0]['lesson_type']);
        $this->assertSame('Identity & Greetings - Lesson 1', $slots[0]['title']);
        $this->assertSame('vocabulary', $slots[1]['lesson_type']);
        $this->assertSame('Identity & Greetings - Lesson 2', $slots[1]['title']);
        $this->assertSame('listening', $slots[2]['lesson_type']);
        $this->assertSame('Identity & Greetings - Lesson 3', $slots[2]['title']);
        $this->assertSame('completion-test', $slots[47]['unit_id']);
        $this->assertSame('Level A1 Completion Test', $slots[47]['title']);
        $this->assertSame('review', $slots[47]['lesson_type']);
        $plan = $generator->generate([
            'cefr_level' => 'A1',
            'duration_weeks' => 12,
            'days_per_week' => 4,
        ], 'en-GB');
        $this->assertTrue($plan['ends_with_test']);
        $this->assertSame('English (UK) A1 — 12-week programme', $plan['title']);
    }

    public function test_capacity_errors_use_the_python_messages(): void
    {
        $generator = new StudyPlanGenerator;
        try {
            $generator->assertCapacity([['id' => 'u']], 0, 4);
            $this->fail('expected capacity error');
        } catch (PlanCapacityException $e) {
            $this->assertSame(
                'Plan dimensions must be positive: both duration_weeks and days_per_week must be at least 1.',
                $e->getMessage(),
            );
        }
        try {
            $generator->assertCapacity([], 12, 4);
            $this->fail('expected empty curriculum error');
        } catch (PlanCapacityException $e) {
            $this->assertSame(
                'This level has no curriculum units, so no study plan can be built for it.',
                $e->getMessage(),
            );
        }
        try {
            $generator->assertCapacity(array_fill(0, 8, ['id' => 'u']), 1, 1);
            $this->fail('expected short plan error');
        } catch (PlanCapacityException $e) {
            $this->assertStringContainsString('This plan is too short to cover all 8 curriculum units', $e->getMessage());
        }
    }

    public function test_lesson_prompt_includes_previous_lessons_and_native_language(): void
    {
        $prompt = LessonPrompts::lessonGeneration([
            'cefr_level' => 'A1',
            'lesson_type' => 'grammar',
            'title' => 'Identity & Greetings - Lesson 1',
            'week' => 1,
            'day' => 1,
            'unit_id' => 'a1-unit-1',
            'grammar_points' => 'to be',
            'vocabulary_themes' => 'greetings',
            'target_language_name' => 'English (UK)',
            'native_language_name' => 'Spanish',
            'language_prompt_overlay' => '',
            'previous_lessons_summary' => 'Lesson 0 covered greetings.',
        ]);
        $this->assertStringContainsString('ALREADY GENERATED LESSONS OF THIS UNIT', $prompt);
        $this->assertStringContainsString('Native language (student\'s language): Spanish', $prompt);
        $this->assertStringContainsString('LESSON TYPE FOCUS', $prompt);
    }
}
