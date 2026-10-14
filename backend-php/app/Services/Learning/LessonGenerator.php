<?php

namespace App\Services\Learning;

use App\Services\Llm\LlmClient;
use App\Services\Llm\StructuredOutput;
use App\Services\Prompts\LessonPrompts;
use App\Services\Prompts\PromptCatalog;

final class LessonGenerator
{
    private StructuredOutput $structured;

    public function __construct(LlmClient $llm)
    {
        $this->structured = new StructuredOutput($llm);
    }

    /**
     * @param  list<array<string, mixed>>  $previousLessons
     * @return array<string, mixed>
     */
    public function generate(
        string $cefrLevel,
        string $lessonType,
        string $topic,
        int $week,
        int $day,
        string $unitId = '',
        ?array $grammarPoints = null,
        ?array $vocabularySetIds = null,
        string $targetLanguage = 'en-GB',
        ?string $nativeLanguage = null,
        array $previousLessons = [],
    ): array {
        $valid = LearningCatalog::grammarSlugs($targetLanguage);
        sort($valid);
        $prompt = LessonPrompts::lessonGeneration([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'native_language_name' => $nativeLanguage ? LearningCatalog::nativeLanguageName($nativeLanguage) : 'none',
            'lesson_type' => $lessonType,
            'topic' => $topic,
            'unit_id' => $unitId !== '' ? $unitId : '—',
            'grammar_points' => $grammarPoints ? implode(', ', $grammarPoints) : 'none specified',
            'vocabulary_set_ids' => $vocabularySetIds ? implode(', ', $vocabularySetIds) : 'general',
            'week' => $week,
            'day' => $day,
            'valid_slugs' => implode(', ', $valid),
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
            'previous_lessons_summary' => $this->previousSummary($previousLessons),
        ]);
        $lesson = $this->structured->decode(
            [['role' => 'system', 'content' => $prompt]],
            fn (mixed $data) => $this->validateLesson(is_array($data) ? $data : []),
        );
        $allowed = array_flip($valid);
        $lesson['grammar_refs'] = array_values(array_filter(
            $lesson['grammar_refs'] ?? [],
            fn ($slug) => isset($allowed[$slug]),
        ));
        $lesson['exercises'] = array_map(fn (array $exercise) => $this->sanitizeExercise($exercise), $lesson['exercises']);

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $lessonExplanation
     * @param  list<array<string, mixed>>|null  $lessonVocabulary
     * @param  array<string, mixed>  $invalidExercise
     * @return array<string, mixed>
     */
    public function regenerate(
        string $cefrLevel,
        string $lessonType,
        string $topic,
        string $exerciseType,
        array $lessonExplanation,
        ?array $lessonVocabulary,
        array $invalidExercise,
        string $targetLanguage = 'en-GB',
        ?string $nativeLanguage = null,
    ): array {
        $options = [
            'multiple_choice' => '["option 1", "option 2", "option 3", "option 4"]',
            'fill_blank' => 'null',
            'free_write' => '["grading criterion 1", "grading criterion 2"]',
            'pronunciation' => '["short pronunciation hint"]',
        ][$exerciseType] ?? 'null';
        $prompt = LessonPrompts::regenerateExercise([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'native_language_name' => $nativeLanguage ? LearningCatalog::nativeLanguageName($nativeLanguage) : 'none',
            'lesson_type' => $lessonType,
            'topic' => $topic,
            'exercise_type' => $exerciseType,
            'lesson_explanation' => json_encode($lessonExplanation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lesson_vocabulary' => json_encode($lessonVocabulary ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'invalid_exercise' => json_encode($invalidExercise, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'options_schema' => $options,
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);
        $exercise = $this->structured->decode(
            [['role' => 'system', 'content' => $prompt]],
            fn (mixed $data) => $this->validateExercise(is_array($data) ? $data : []),
        );
        if (($exercise['type'] ?? null) !== $exerciseType) {
            throw new \InvalidArgumentException('Regenerated exercise type does not match original type');
        }

        return $this->sanitizeExercise($exercise);
    }

    /**
     * @return array{score: float, feedback: string, corrections: list<array<string, string>>}
     */
    public function evaluateFreeWrite(
        string $cefrLevel,
        string $prompt,
        array $criteria,
        string $answer,
        string $targetLanguage = 'en-GB',
        ?string $nativeLanguage = null,
    ): array {
        $eval = LessonPrompts::freeWriteEval([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'native_language_name' => $nativeLanguage ? LearningCatalog::nativeLanguageName($nativeLanguage) : 'English',
            'prompt' => $prompt,
            'criteria' => implode(', ', $criteria),
            'answer' => $answer,
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);

        return $this->structured->decode(
            [['role' => 'system', 'content' => $eval]],
            function (mixed $data) {
                if (! is_array($data) || ! isset($data['score'], $data['feedback'])) {
                    throw new \InvalidArgumentException('free write evaluation is incomplete');
                }
                $corrections = [];
                foreach (is_array($data['corrections'] ?? null) ? $data['corrections'] : [] as $item) {
                    if ($this->usableCorrection($item)) {
                        $corrections[] = [
                            'original' => $item['original'],
                            'corrected' => $item['corrected'],
                            'explanation' => (string) ($item['explanation'] ?? ''),
                        ];
                    }
                }

                return [
                    'score' => (float) $data['score'],
                    'feedback' => (string) $data['feedback'],
                    'corrections' => $corrections,
                ];
            },
        );
    }

    /**
     * @return array{score: float, feedback: string, is_correct: bool}
     */
    public function evaluatePronunciation(
        string $cefrLevel,
        string $target,
        string $transcription,
        string $targetLanguage = 'en-GB',
        ?string $nativeLanguage = null,
    ): array {
        $eval = LessonPrompts::pronunciationEval([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'native_language_name' => $nativeLanguage ? LearningCatalog::nativeLanguageName($nativeLanguage) : 'English',
            'target' => $target,
            'transcription' => $transcription,
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);

        return $this->structured->decode(
            [['role' => 'system', 'content' => $eval]],
            function (mixed $data) {
                if (! is_array($data) || ! isset($data['score'], $data['feedback'], $data['is_correct'])) {
                    throw new \InvalidArgumentException('pronunciation evaluation is incomplete');
                }

                return [
                    'score' => (float) $data['score'],
                    'feedback' => (string) $data['feedback'],
                    'is_correct' => (bool) $data['is_correct'],
                ];
            },
        );
    }

    /**
     * @return array{is_correct: bool, score: float, feedback: string}
     */
    public function evaluateFillBlank(
        string $cefrLevel,
        string $question,
        string $correctAnswer,
        string $studentAnswer,
        string $targetLanguage = 'en-GB',
        ?string $nativeLanguage = null,
    ): array {
        $eval = LessonPrompts::fillBlankEval([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'native_language_name' => $nativeLanguage ? LearningCatalog::nativeLanguageName($nativeLanguage) : 'English',
            'question' => $question,
            'correct_answer' => $correctAnswer,
            'student_answer' => $studentAnswer,
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);

        return $this->structured->decode(
            [['role' => 'system', 'content' => $eval]],
            function (mixed $data) {
                if (! is_array($data) || ! isset($data['is_correct'], $data['score'], $data['feedback'])) {
                    throw new \InvalidArgumentException('fill blank evaluation is incomplete');
                }

                return [
                    'is_correct' => (bool) $data['is_correct'],
                    'score' => (float) $data['score'],
                    'feedback' => (string) $data['feedback'],
                ];
            },
        );
    }

    public function hintRevealsAnswer(?string $nativeHint, ?string $correctAnswer): bool
    {
        if (! $nativeHint || ! $correctAnswer) {
            return false;
        }
        $hint = mb_strtolower($nativeHint);
        foreach (explode('/', $correctAnswer) as $part) {
            $answer = mb_strtolower(trim($part));
            if ($answer === '') {
                continue;
            }
            $stripped = str_replace("'", '', $answer);
            $alnum = (bool) preg_match('/^[\p{L}\p{N}]+$/u', $stripped);
            if (preg_match('/\s/u', $answer) || ! $alnum) {
                if (str_contains($hint, $answer)) {
                    return true;
                }

                continue;
            }
            $pattern = '/(?<!\w)'.preg_quote($answer, '/').'(?!\w)/u';
            if (preg_match($pattern, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $previousLessons
     */
    public function previousSummary(array $previousLessons): string
    {
        $entries = [];
        $unitWords = [];
        $seen = [];
        $detailedFrom = max(count($previousLessons) - 6, 0);
        foreach ($previousLessons as $index => $previous) {
            $content = is_array($previous['content'] ?? null) ? $previous['content'] : [];
            $words = [];
            foreach ($this->asList($content['vocabulary'] ?? null) as $item) {
                if (is_array($item) && ! empty($item['word'])) {
                    $words[] = $this->compact($item['word'], 40);
                }
            }
            foreach ($words as $word) {
                $key = mb_strtolower($word);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $unitWords[] = $word;
                }
            }
            if ($index < $detailedFrom) {
                continue;
            }
            $explanation = is_array($content['explanation'] ?? null) ? $content['explanation'] : [];
            $native = is_array($content['native_explanation'] ?? null) ? $content['native_explanation'] : [];
            $title = $this->compact($previous['title'] ?? '', 80) ?: 'untitled';
            $lessonType = $this->compact($previous['lesson_type'] ?? '', 20) ?: 'unknown';
            $lines = ['- "'.$title.'" ('.$lessonType.')'];
            $focus = $this->compact($explanation['text'] ?? '', 140);
            if ($focus !== '') {
                $lines[] = '  explained: '.$focus;
            }
            $sentences = [];
            foreach ($this->asList($explanation['examples'] ?? null) as $example) {
                if (is_array($example) && ! empty($example['sentence'])) {
                    $sentences[] = $this->compact($example['sentence'], 120);
                }
            }
            $sentences = array_slice($sentences, 0, 3);
            if ($sentences !== []) {
                $lines[] = '  example sentences used: '.implode(' | ', $sentences);
            }
            if ($words !== []) {
                $lines[] = '  vocabulary taught: '.implode(', ', array_slice($words, 0, 6));
            }
            $traps = [];
            foreach ($this->asList($native['common_traps'] ?? null) as $trap) {
                if (is_array($trap) && ! empty($trap['mistake'])) {
                    $traps[] = $this->compact($trap['mistake'], 90);
                }
            }
            $traps = array_slice($traps, 0, 2);
            if ($traps !== []) {
                $lines[] = '  common traps listed: '.implode(' | ', $traps);
            }
            $entries[] = implode("\n", $lines);
        }
        if ($entries === []) {
            return '';
        }
        $summary = implode("\n", $entries);
        if ($unitWords !== []) {
            $summary .= "\n\nVocabulary already introduced in this unit: ".implode(', ', array_slice($unitWords, 0, 40));
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $exercise
     * @return array{question: string, explanation: ?string}
     */
    public function normalizedText(string $type, string $question, ?string $explanation): array
    {
        if ($type === 'fill_blank' && ! str_contains($question, '___') && $explanation && str_contains($explanation, '___')) {
            return ['question' => $explanation, 'explanation' => $question];
        }

        return ['question' => $question, 'explanation' => $explanation];
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    private function validateLesson(array $lesson): array
    {
        if (! isset($lesson['exercises']) || ! is_array($lesson['exercises'])) {
            throw new \InvalidArgumentException('lesson must include exercises');
        }
        $lesson['exercises'] = array_map(fn ($exercise) => $this->validateExercise(is_array($exercise) ? $exercise : []), $lesson['exercises']);

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $exercise
     * @return array<string, mixed>
     */
    private function validateExercise(array $exercise): array
    {
        $question = (string) ($exercise['question'] ?? '');
        $correct = (string) ($exercise['correct'] ?? '');
        if (trim($question) === '') {
            throw new \InvalidArgumentException('exercises must include a question');
        }
        if (trim($correct) === '') {
            throw new \InvalidArgumentException('exercises must include a correct answer');
        }
        $type = (string) ($exercise['type'] ?? '');
        if ($type === 'fill_blank' && ! str_contains($question, '___')) {
            $explanation = $exercise['explanation'] ?? null;
            if (is_string($explanation) && str_contains($explanation, '___')) {
                $exercise['question'] = $explanation;
                $exercise['explanation'] = $question;
            } else {
                throw new \InvalidArgumentException('fill_blank exercises must include ___ in the question');
            }
        }
        if ($type === 'multiple_choice') {
            $options = array_values(array_filter(
                is_array($exercise['options'] ?? null) ? $exercise['options'] : [],
                fn ($option) => is_string($option) && trim($option) !== '',
            ));
            if (count($options) < 2) {
                throw new \InvalidArgumentException('multiple_choice exercises must include at least two options');
            }
            if (! in_array($correct, $options, true)) {
                throw new \InvalidArgumentException('multiple_choice correct answer must match one option exactly');
            }
            $exercise['options'] = $options;
        }

        return $exercise;
    }

    /**
     * @param  array<string, mixed>  $exercise
     * @return array<string, mixed>
     */
    private function sanitizeExercise(array $exercise): array
    {
        if (($exercise['type'] ?? '') === 'fill_blank' && ! str_contains((string) $exercise['question'], '___')) {
            $explanation = $exercise['explanation'] ?? null;
            if (is_string($explanation) && str_contains($explanation, '___')) {
                $exercise['explanation'] = $exercise['question'];
                $exercise['question'] = $explanation;
            }
        }
        if ($this->hintRevealsAnswer(
            is_string($exercise['native_hint'] ?? null) ? $exercise['native_hint'] : null,
            is_string($exercise['correct'] ?? null) ? $exercise['correct'] : null,
        )) {
            $exercise['native_hint'] = null;
        }

        return $exercise;
    }

    private function usableCorrection(mixed $item): bool
    {
        if (! is_array($item)) {
            return false;
        }
        $original = $item['original'] ?? null;
        $corrected = $item['corrected'] ?? null;

        return is_string($original) && trim($original) !== '' && is_string($corrected) && trim($corrected) !== '';
    }

    private function compact(mixed $value, int $limit): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) ($value ?? ''))) ?? '';
        if (mb_strlen($text) > $limit) {
            return rtrim(mb_substr($text, 0, $limit - 3)).'...';
        }

        return $text;
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
