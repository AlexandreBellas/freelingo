<?php

namespace Tests\Feature;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\ScriptedLlm;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\LearningHelpers;
use Tests\TestCase;

class FlashcardTest extends TestCase
{
    use LearningHelpers;

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    public function test_create_review_replay_and_delete(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 09:00:00', 'UTC'));
        [$user, $headers] = $this->makeUser();
        $plan = $this->seedPlan($user);
        $created = $this->api('POST', '/api/flashcards', [
            'word' => 'library',
            'definition' => 'place with books',
            'example_sentence' => 'The library is quiet.',
            'translation' => 'biblioteca',
        ], $headers);
        $created->assertOk();
        $created->assertJsonPath('word', 'library');
        $created->assertJsonPath('ease_factor', 2.5);
        $created->assertJsonPath('interval', 0);
        $id = $created->json('id');

        $due = $this->api('GET', '/api/flashcards/due', [], $headers);
        $due->assertOk();
        $due->assertJsonPath('total', 1);
        $due->assertJsonPath('cefr_level', 'A1');
        $this->assertCount(1, $due->json('due'));

        $reviewId = '11111111-1111-4111-8111-111111111111';
        $review = $this->api('POST', "/api/flashcards/{$id}/review", [
            'quality' => 3,
            'review_id' => $reviewId,
        ], $headers);
        $review->assertOk();
        $review->assertJsonPath('repetitions', 1);
        $review->assertJsonPath('interval', 1);
        $review->assertJsonPath('xp_earned', 2);
        $review->assertJsonPath('next_review', '2026-10-14');

        $replay = $this->api('POST', "/api/flashcards/{$id}/review", [
            'quality' => 3,
            'review_id' => $reviewId,
        ], $headers);
        $replay->assertOk();
        $this->assertSame($review->json(), $replay->json());
        $this->assertSame(2, (int) Progress::query()->where('study_plan_id', $plan->id)->sum('xp_earned'));

        $conflict = $this->api('POST', "/api/flashcards/{$id}/review", [
            'quality' => 5,
            'review_id' => $reviewId,
        ], $headers);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('detail', 'flashcard_review_conflict');

        $invalid = $this->api('POST', "/api/flashcards/{$id}/review", [
            'quality' => '3',
            'review_id' => 'not-a-uuid',
        ], $headers);
        $invalid->assertStatus(422);
        $this->assertSame('quality', $invalid->json('detail.0.loc')[count($invalid->json('detail.0.loc')) - 1]);

        $this->api('DELETE', "/api/flashcards/{$id}", [], $headers)->assertNoContent();
        $this->api('DELETE', "/api/flashcards/{$id}", [], $headers)->assertStatus(404)
            ->assertJsonPath('detail', 'Flashcard not found');
    }

    public function test_suspension_blocks_review_and_preferences_are_global(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'suspend', 'email' => 'suspend@example.com']);
        $plan = $this->seedPlan($user);
        $card = Flashcard::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'word' => 'hello',
            'definition' => 'a greeting',
            'example_sentence' => 'Hello there.',
            'translation' => 'hola',
            'ease_factor' => 2.5,
            'interval' => 0,
            'repetitions' => 0,
            'next_review' => Clock::today(),
            'created_at' => Clock::now(),
        ]);
        $suspended = $this->api('PUT', "/api/flashcards/{$card->id}/suspension", ['is_suspended' => true], $headers);
        $suspended->assertOk();
        $suspended->assertJsonPath('is_suspended', true);
        $blocked = $this->api('POST', "/api/flashcards/{$card->id}/review", [
            'quality' => 4,
            'review_id' => '22222222-2222-4222-8222-222222222222',
        ], $headers);
        $blocked->assertStatus(409);
        $blocked->assertJsonPath('detail', 'flashcard_suspended');

        $prefs = $this->api('PUT', '/api/flashcards/preferences', ['back_content' => 'translation'], $headers);
        $prefs->assertOk();
        $prefs->assertJsonPath('back_content', 'translation');
        $this->api('GET', '/api/flashcards/preferences', [], $headers)->assertJsonPath('back_content', 'translation');
        $extra = $this->api('PUT', '/api/flashcards/preferences', ['back_content' => 'definition', 'ease_factor' => 3], $headers);
        $extra->assertStatus(422);
    }

    public function test_bulk_rejects_a_stale_plan_and_skips_duplicates(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'bulk', 'email' => 'bulk@example.com']);
        $plan = $this->seedPlan($user, ['target_language' => 'en-US']);
        $card = [
            'word' => 'book',
            'definition' => 'pages',
            'example_sentence' => 'I read a book.',
            'translation' => 'libro',
        ];
        $created = $this->api('POST', '/api/flashcards/bulk', [
            'study_plan_id' => $plan->id,
            'target_language' => 'en-US',
            'flashcards' => [$card, $card],
        ], $headers);
        $created->assertOk();
        $created->assertJsonPath('created', 1);
        $again = $this->api('POST', '/api/flashcards/bulk', [
            'study_plan_id' => $plan->id,
            'target_language' => 'en-US',
            'flashcards' => [['word' => '  Book  ', 'definition' => 'pages', 'example_sentence' => 'A book.', 'translation' => 'libro']],
        ], $headers);
        $again->assertJsonPath('created', 0);

        $stale = $this->api('POST', '/api/flashcards/bulk', [
            'study_plan_id' => $plan->id,
            'target_language' => 'fr-FR',
            'flashcards' => [$card],
        ], $headers);
        $stale->assertStatus(409);
        $stale->assertJsonPath('detail', 'flashcard_context_changed');

        $missing = $this->api('POST', '/api/flashcards/bulk', ['flashcards' => []], $headers);
        $missing->assertStatus(422);
    }

    public function test_generate_skips_empty_words_and_reports_model_errors(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'gen', 'email' => 'gen@example.com']);
        $this->seedPlan($user);
        $llm = new ScriptedLlm([[
            'flashcards' => [
                ['word' => '(noun)', 'definition' => 'empty', 'example_sentence' => 'x', 'translation' => 'x'],
                ['word' => 'run (verb)', 'definition' => 'move', 'example_sentence' => 'I run.', 'translation' => 'correr'],
            ],
        ]]);
        $this->app->instance(LlmClient::class, $llm);
        $response = $this->api('POST', '/api/flashcards/generate', ['topic' => 'verbs', 'count' => 2], $headers);
        $response->assertOk();
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('skipped_empty', 1);
        $response->assertJsonPath('flashcards.0.word', 'run');

        $this->app->instance(LlmClient::class, new ScriptedLlm([new LlmException('bad'), new LlmException('bad')]));
        $failed = $this->api('POST', '/api/flashcards/generate', ['topic' => 'verbs'], $headers);
        $failed->assertStatus(502);
        $failed->assertJsonPath('detail', 'ai_service_error');
    }

    public function test_edit_duplicate_is_plan_scoped(): void
    {
        [$user, $headers] = $this->makeUser(['username' => 'edit', 'email' => 'edit@example.com']);
        $plan = $this->seedPlan($user);
        $first = Flashcard::query()->create($this->card($user->id, $plan->id, 'alpha'));
        $second = Flashcard::query()->create($this->card($user->id, $plan->id, 'beta'));
        $duplicate = $this->api('PUT', "/api/flashcards/{$second->id}", [
            'word' => ' Alpha ',
            'definition' => 'changed',
            'example_sentence' => 'An alpha.',
            'translation' => 'alfa',
        ], $headers);
        $duplicate->assertStatus(409);
        $duplicate->assertJsonPath('detail', 'flashcard_duplicate');
        $updated = $this->api('PUT', "/api/flashcards/{$first->id}", [
            'word' => 'alpha',
            'definition' => 'changed',
            'example_sentence' => 'An alpha.',
            'translation' => 'alfa',
        ], $headers);
        $updated->assertOk();
        $updated->assertJsonPath('definition', 'changed');
        $this->assertSame(0, $first->fresh()->repetitions);
    }

    private function card(int $userId, int $planId, string $word): array
    {
        return [
            'user_id' => $userId,
            'study_plan_id' => $planId,
            'word' => $word,
            'definition' => 'd',
            'example_sentence' => 'e',
            'translation' => 't',
            'next_review' => Clock::today(),
            'created_at' => Clock::now(),
        ];
    }
}
