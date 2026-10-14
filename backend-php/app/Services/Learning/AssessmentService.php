<?php

namespace App\Services\Learning;

use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\StructuredOutput;
use App\Services\Prompts\AssessmentPrompts;
use App\Services\Prompts\PromptCatalog;

final class AssessmentService
{
    private StructuredOutput $structured;

    public function __construct(LlmClient $llm)
    {
        $this->structured = new StructuredOutput($llm);
    }

    /**
     * @param  list<array{question_id?: string, skill?: string, difficulty?: string, correct?: bool, dont_know?: bool}>  $answers
     * @return array<string, mixed>
     */
    public function evaluateAdaptiveQuiz(array $answers): array
    {
        $levelScores = [];
        foreach (LearningCatalog::CEFR_LEVELS as $level) {
            $levelScores[$level] = ['correct' => 0, 'total' => 0];
        }
        $skillScores = ['grammar' => [], 'vocabulary' => [], 'reading' => []];
        $skillGaps = ['grammar' => 0, 'vocabulary' => 0, 'reading' => 0];
        foreach ($answers as $answer) {
            $dontKnow = (bool) ($answer['dont_know'] ?? false);
            $correct = (bool) ($answer['correct'] ?? false) && ! $dontKnow;
            $level = strtoupper((string) ($answer['difficulty'] ?? ''));
            if (isset($levelScores[$level])) {
                $levelScores[$level]['total']++;
                if ($correct) {
                    $levelScores[$level]['correct']++;
                }
            }
            $skill = strtolower((string) ($answer['skill'] ?? ''));
            if (isset($skillScores[$skill])) {
                $skillScores[$skill][] = $correct ? 1 : 0;
                if ($dontKnow) {
                    $skillGaps[$skill]++;
                }
            }
        }
        $cefr = 'A1';
        foreach (LearningCatalog::CEFR_LEVELS as $level) {
            $row = $levelScores[$level];
            if ($row['total'] >= 2 && $row['correct'] / $row['total'] >= 0.6) {
                $cefr = $level;
            }
        }
        $profile = [];
        foreach ($skillScores as $skill => $scores) {
            $profile[$skill] = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 2, PHP_ROUND_HALF_EVEN);
        }
        $overall = round(array_sum($profile) / count($profile), 2, PHP_ROUND_HALF_EVEN);
        $strengths = [];
        $weaknesses = [];
        foreach ($profile as $skill => $value) {
            if ($value >= 0.65) {
                $strengths[] = $skill;
            }
            $gap = $skillScores[$skill] !== [] && ($skillGaps[$skill] / count($skillScores[$skill]) >= 0.5);
            if ($value < 0.45 || $gap) {
                $weaknesses[] = $skill;
            }
        }

        return [
            'cefr_level' => $cefr,
            'score' => $overall,
            'skill_profile' => $profile,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'analysis' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateFreeWrite(string $preliminaryLevel, string $writingPrompt, string $answer, string $targetLanguage): array
    {
        $prompt = AssessmentPrompts::freeWrite([
            'preliminary_level' => $preliminaryLevel,
            'prompt' => $writingPrompt,
            'answer' => $answer,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);
        try {
            $raw = $this->structured->chat([['role' => 'system', 'content' => $prompt]]);

            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LlmException('Free-write evaluation failed: malformed JSON response: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  list<string>  $grammarPoints
     * @param  list<string>  $vocabularySets
     * @return list<array<string, mixed>>
     */
    public function generateLevelTest(string $cefrLevel, array $grammarPoints, array $vocabularySets, string $targetLanguage): array
    {
        $index = array_search($cefrLevel, LearningCatalog::CEFR_LEVELS, true);
        $index = $index === false ? 0 : $index;
        $next = LearningCatalog::CEFR_LEVELS[$index + 1] ?? 'higher levels';
        $prompt = AssessmentPrompts::endOfLevel([
            'cefr_level' => $cefrLevel,
            'target_language_name' => LearningCatalog::languageName($targetLanguage),
            'grammar_points_studied' => $grammarPoints !== [] ? implode(', ', $grammarPoints) : 'all grammar for this level',
            'vocabulary_sets_studied' => $vocabularySets !== [] ? implode(', ', $vocabularySets) : 'all vocabulary for this level',
            'next_level' => $next,
            'language_prompt_overlay' => PromptCatalog::overlay($targetLanguage),
        ]);
        try {
            $raw = $this->structured->chat([['role' => 'system', 'content' => $prompt]]);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data['questions'] ?? null) ? $data['questions'] : [];
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LlmException('Level test generation failed: malformed JSON response: '.$e->getMessage(), 0, $e);
        }
    }
}
