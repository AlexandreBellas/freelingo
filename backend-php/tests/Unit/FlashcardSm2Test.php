<?php

namespace Tests\Unit;

use App\Services\Learning\FlashcardService;
use App\Services\Llm\LlmClient;
use App\Services\Llm\ScriptedLlm;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FlashcardSm2Test extends TestCase
{
    private FlashcardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        $this->cards = new FlashcardService(new ScriptedLlm);
    }

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    public function test_clean_word_strips_quotes_and_parentheticals(): void
    {
        $this->assertSame('hello', $this->cards->cleanWord('"hello"'));
        $this->assertSame('world', $this->cards->cleanWord("'world'"));
        $this->assertSame('run', $this->cards->cleanWord('run (verb)'));
        $this->assertSame('beautiful', $this->cards->cleanWord('beautiful (adjective)'));
        $this->assertSame('fast', $this->cards->cleanWord('  fast  (adj)  '));
        $this->assertSame('hello world', $this->cards->cleanWord('  hello   world  '));
        $this->assertSame('', $this->cards->cleanWord(''));
        $nested = $this->cards->cleanWord('word (some (nested) text)');
        $this->assertStringContainsString('word', $nested);
        $this->assertStringNotContainsString('nested', $nested);
    }

    public function test_sm2_quality_zero_resets(): void
    {
        $card = $this->card(2.5, 10, 3);
        $this->cards->sm2($card, 0);
        $this->assertSame(0, $card->repetitions);
        $this->assertSame(1, $card->interval);
    }

    public function test_sm2_first_and_second_correct(): void
    {
        $first = $this->card();
        $this->cards->sm2($first, 3);
        $this->assertSame(1, $first->repetitions);
        $this->assertSame(1, $first->interval);

        $second = $this->card(2.5, 1, 1);
        $this->cards->sm2($second, 3);
        $this->assertSame(2, $second->repetitions);
        $this->assertSame(6, $second->interval);
    }

    public function test_sm2_progression_uses_half_even_rounding(): void
    {
        $card = $this->card(2.5, 6, 2);
        $this->cards->sm2($card, 3);
        $this->assertSame(3, $card->repetitions);
        $this->assertSame(15, $card->interval);
        $this->assertSame('2026-10-28', $card->next_review);
    }

    public function test_sm2_ease_moves_and_floors_at_one_point_three(): void
    {
        $fail = $this->card(2.5);
        $this->cards->sm2($fail, 2);
        $this->assertLessThan(2.5, $fail->ease_factor);

        $perfect = $this->card(2.5);
        $this->cards->sm2($perfect, 5);
        $this->assertGreaterThan(2.5, $perfect->ease_factor);

        $floor = $this->card(1.3);
        $this->cards->sm2($floor, 1);
        $this->assertEqualsWithDelta(1.3, $floor->ease_factor, 0.00001);
    }

    public function test_generate_cleans_words_and_humanizes_native_language(): void
    {
        $llm = new ScriptedLlm([[
            'flashcards' => [
                ['word' => 'run (verb)', 'definition' => 'move fast', 'example_sentence' => 'I run.', 'translation' => 'correr'],
                ['word' => '"study"', 'definition' => 'learn', 'example_sentence' => 'I study.', 'translation' => 'estudiar'],
            ],
        ]]);
        $service = new FlashcardService($llm);
        $result = $service->generate('routines', 2, 'A1', 'es', 'en-US');
        $this->assertSame('run', $result['flashcards'][0]['word']);
        $this->assertSame('study', $result['flashcards'][1]['word']);
        $this->assertStringContainsString('definition in Spanish', $llm->prompt());
        $this->assertStringContainsString("student's native language (Spanish)", $llm->prompt());
    }

    public function test_lookup_cleans_word_and_falls_back_context(): void
    {
        $llm = new ScriptedLlm([[
            'word' => '"library"',
            'definition' => 'place with books',
            'example_sentence' => 'The library is quiet.',
            'translation' => 'biblioteca',
        ]]);
        $service = new FlashcardService($llm);
        $card = $service->lookup('library', '', 'A1', 'es', 'en-US');
        $this->assertSame('library', $card['word']);
        $this->assertSame('biblioteca', $card['translation']);
        $this->assertStringContainsString('library', $llm->prompt());
    }

    public function test_language_overlays(): void
    {
        $this->app->instance(LlmClient::class, new ScriptedLlm);
        $hints = [
            'de' => 'standard german spelling',
            'fr' => 'french',
            'es' => 'spanish',
            'it' => 'italian',
            'pt' => 'portuguese',
            'en-US' => 'american english',
            'en-GB' => 'british english',
            'de-DE' => 'standard german spelling',
            'fr-FR' => 'french',
        ];
        foreach ($hints as $language => $needle) {
            $prompt = \App\Services\Prompts\FlashcardPrompts::lookup([
                'word' => 'hello',
                'context' => 'hello',
                'cefr_level' => 'A1',
                'native_language' => 'Spanish',
                'target_language_name' => 'English',
                'language_prompt_overlay' => \App\Services\Prompts\PromptCatalog::overlay($language),
            ]);
            $this->assertStringContainsString($needle, strtolower($prompt), $language);
        }
        $this->assertSame('', \App\Services\Prompts\PromptCatalog::overlay('xx'));
        $this->assertSame('', \App\Services\Prompts\PromptCatalog::overlay(''));
    }

    private function card(float $ease = 2.5, int $interval = 0, int $repetitions = 0): object
    {
        return (object) [
            'ease_factor' => $ease,
            'interval' => $interval,
            'repetitions' => $repetitions,
            'next_review' => Clock::today(),
        ];
    }
}
