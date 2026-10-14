<?php

namespace App\Services\Learning;

use App\Services\Llm\LlmClient;
use App\Services\Llm\StructuredOutput;
use App\Services\Prompts\FlashcardPrompts;
use App\Services\Prompts\PromptCatalog;
use App\Support\Clock;

final class FlashcardService
{
    private StructuredOutput $structured;

    public function __construct(LlmClient $llm)
    {
        $this->structured = new StructuredOutput($llm);
    }

    /**
     * @param  object  $card  object with ease_factor, interval, repetitions, next_review
     */
    public function sm2(object $card, int $quality): object
    {
        if ($quality < 3) {
            $card->repetitions = 0;
            $card->interval = 1;
        } else {
            if ((int) $card->repetitions === 0) {
                $card->interval = 1;
            } elseif ((int) $card->repetitions === 1) {
                $card->interval = 6;
            } else {
                $card->interval = (int) round(((float) $card->interval) * ((float) $card->ease_factor), 0, PHP_ROUND_HALF_EVEN);
            }
            $card->repetitions = (int) $card->repetitions + 1;
        }
        $delta = 5 - $quality;
        $card->ease_factor = max(1.3, ((float) $card->ease_factor) + 0.1 - $delta * (0.08 + $delta * 0.02));
        $card->next_review = Clock::now()->startOfDay()->addDays((int) $card->interval)->format('Y-m-d');

        return $card;
    }

    public function cleanWord(string $value): string
    {
        $cleaned = trim($value);
        $cleaned = trim($cleaned, "\"'");
        $cleaned = preg_replace('/\s*\([^)]*\)/', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    public function normalizeWord(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($collapsed);
    }

    /**
     * @return array{flashcards: list<array{word: string, definition: string, example_sentence: string, translation: string}>}
     */
    public function generate(string $topic, int $count, string $cefrLevel, string $nativeLanguage, string $targetLanguage = 'en-GB'): array
    {
        $nativeName = LearningCatalog::nativeLanguageName($nativeLanguage);
        $prompt = FlashcardPrompts::generation([
            'topic' => $topic,
            'count' => $count,
            'cefr_level' => $cefrLevel,
            'native_language' => $nativeName,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);
        $result = $this->structured->decode(
            [['role' => 'system', 'content' => $prompt]],
            function (mixed $data) {
                if (! is_array($data) || ! is_array($data['flashcards'] ?? null)) {
                    throw new \InvalidArgumentException('flashcards payload is invalid');
                }
                $cards = [];
                foreach ($data['flashcards'] as $card) {
                    $cards[] = $this->validateCard(is_array($card) ? $card : [], false);
                }

                return ['flashcards' => $cards];
            },
        );
        $result['flashcards'] = array_map(function (array $card) {
            $card['word'] = $this->cleanWord($card['word']);

            return $card;
        }, $result['flashcards']);

        return $result;
    }

    /**
     * @return array{word: string, definition: string, example_sentence: string, translation: string}
     */
    public function lookup(string $word, string $context, string $cefrLevel, string $nativeLanguage, string $targetLanguage = 'en-GB'): array
    {
        $nativeName = LearningCatalog::nativeLanguageName($nativeLanguage);
        $prompt = FlashcardPrompts::lookup([
            'word' => $word,
            'context' => $context !== '' ? $context : $word,
            'cefr_level' => $cefrLevel,
            'native_language' => $nativeName,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);

        return $this->structured->decode(
            [['role' => 'system', 'content' => $prompt]],
            fn (mixed $data) => $this->validateCard(is_array($data) ? $data : [], true),
        );
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{word: string, definition: string, example_sentence: string, translation: string}
     */
    private function validateCard(array $card, bool $cleanLookup): array
    {
        foreach (['word', 'definition', 'example_sentence', 'translation'] as $field) {
            if (! isset($card[$field]) || ! is_string($card[$field])) {
                throw new \InvalidArgumentException($field.' is required');
            }
        }
        if (mb_strlen($card['word']) > 100) {
            throw new \InvalidArgumentException('word is too long');
        }
        if (mb_strlen($card['translation']) > 255) {
            throw new \InvalidArgumentException('translation is too long');
        }
        $word = $cleanLookup ? $this->cleanWord($card['word']) : $card['word'];
        if ($cleanLookup && $word === '') {
            throw new \InvalidArgumentException('Lookup word must not be empty after cleaning');
        }

        return [
            'word' => $word,
            'definition' => $card['definition'],
            'example_sentence' => $card['example_sentence'],
            'translation' => $card['translation'],
        ];
    }
}
