<?php

namespace App\Services\Practice;

use App\Exceptions\ApiException;
use App\Models\ListeningExercise;
use App\Models\ReadingAttempt;
use App\Models\ReadingExercise;
use App\Models\StudyPlan;
use App\Models\User;
use App\Services\Learning\ExerciseGeneration;
use App\Services\Learning\GenerationLease;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmResponseError;
use Illuminate\Support\Carbon;

final class ReadingService
{
    public const XP = 10;

    /** @var array<string, list<string>> */
    private const TYPES = [
        'A1' => ['notice', 'email'],
        'A2' => ['notice', 'email'],
        'B1' => ['email', 'article', 'news'],
        'B2' => ['article', 'news', 'blog_post', 'review'],
        'C1' => ['news', 'blog_post', 'review', 'essay'],
        'C2' => ['review', 'essay'],
    ];

    /** @var array<string, int> */
    private const WORDS = ['A1' => 80, 'A2' => 120, 'B1' => 200, 'B2' => 280, 'C1' => 380, 'C2' => 480];

    /** @var array<string, string> */
    private const TYPE_DESC = [
        'notice' => 'a short public notice, sign, or instruction',
        'email' => 'a short informal or formal email message',
        'article' => 'a short informational or educational article',
        'news' => 'a news report on a current or recent event',
        'blog_post' => 'an informal blog-style opinion or personal piece',
        'review' => 'a review of a book, film, restaurant, or product',
        'essay' => 'a formal argumentative or discursive essay',
    ];

    /** @var array<string, list<string>> */
    private const TOPICS = [
        'A1' => ['daily_routine', 'family', 'shopping', 'home', 'animals'],
        'A2' => ['travel', 'food', 'weather', 'hobbies', 'school'],
        'B1' => ['health', 'work', 'environment', 'sports', 'friendship'],
        'B2' => ['technology', 'culture', 'education', 'media', 'money'],
        'C1' => ['politics', 'science', 'literature', 'psychology', 'urban_life'],
        'C2' => ['philosophy', 'history', 'global_affairs', 'ethics', 'economics'],
    ];

    public function __construct(
        private readonly LlmAdapter $llm,
        private readonly ExerciseGeneration $generation,
        private readonly ProgressService $progress,
        private readonly ProgressRewards $rewards,
    ) {
    }

    public function available(string $level, string $language, int $userId): ?ReadingExercise
    {
        $done = ReadingAttempt::query()->where('user_id', $userId)->pluck('exercise_id');

        return ReadingExercise::query()
            ->where('level', $level)
            ->where('target_language', $language)
            ->when($done->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $done))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{status: string}
     */
    public function startGeneration(StudyPlan $plan): array
    {
        if ($this->available($plan->cefr_level, $plan->target_language, $plan->user_id)) {
            return ['status' => 'available'];
        }
        $key = "reading:generating:{$plan->cefr_level}:{$plan->target_language}";
        $lease = $this->generation->acquire($key);
        if ($lease === null) {
            return ['status' => 'generating'];
        }
        if ($this->available($plan->cefr_level, $plan->target_language, $plan->user_id)) {
            $this->generation->finish($lease);

            return ['status' => 'available'];
        }
        $level = $plan->cefr_level;
        $language = $plan->target_language;
        app()->terminating(function () use ($lease, $level, $language) {
            $this->generation->run($lease, function () use ($lease, $level, $language) {
                $this->generate($level, $language, $lease);
            });
        });

        return ['status' => 'generating'];
    }

    public function generate(string $level, string $language, ?GenerationLease $lease = null): ReadingExercise
    {
        $types = self::TYPES[$level] ?? ['article'];
        $type = $types[array_rand($types)];
        $topics = array_merge(self::TOPICS[$level] ?? ['daily_routine'], $this->cultural($language));
        $topic = $topics[array_rand($topics)];
        $words = self::WORDS[$level] ?? 200;
        $prompt = Prompts::readingGeneration(
            Languages::name($language),
            $level,
            $type,
            self::TYPE_DESC[$type] ?? $type,
            $topic,
            $words,
            Languages::comprehensionLengthGuidance($language, $words),
            Prompts::languageOverlay($language),
        );
        try {
            $parsed = $this->llm->structuredOutput([['role' => 'user', 'content' => $prompt]]);
        } catch (LlmResponseError $error) {
            throw new \InvalidArgumentException('LLM failed to produce valid reading exercise JSON: '.$error->getMessage(), previous: $error);
        }
        if ($lease) {
            $this->generation->ensureOwner($lease);
        }
        $questions = $parsed['questions'] ?? [];
        if (! is_array($questions) || $questions === []) {
            throw new \InvalidArgumentException('LLM failed to produce valid reading exercise JSON');
        }

        return ReadingExercise::query()->create([
            'level' => $level,
            'target_language' => $language,
            'exercise_type' => $type,
            'topic' => (string) ($parsed['topic'] ?? $topic),
            'text' => (string) ($parsed['text'] ?? ''),
            'questions' => array_values($questions),
            'view_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, string>  $answers
     * @return array{0: int, 1: int}
     */
    public static function score(array $questions, array $answers): array
    {
        $score = 0;
        foreach ($questions as $question) {
            $index = (string) ($question['index'] ?? '');
            $given = strtoupper((string) ($answers[$index] ?? ''));
            if ($given === strtoupper((string) ($question['correct'] ?? ''))) {
                $score++;
            }
        }

        return [$score, $score * self::XP];
    }

    /**
     * @param  array<string, string>  $answers
     * @return array{0: ReadingAttempt, 1: ReadingExercise}
     */
    public function submit(int $exerciseId, User $user, array $answers, bool $replay, int $planId): array
    {
        $completed = Carbon::now('UTC');
        $date = $completed->format('Y-m-d');
        $exercise = ReadingExercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new \InvalidArgumentException('exercise_not_found');
        }
        if (! $replay && ReadingAttempt::query()->where('user_id', $user->id)->where('exercise_id', $exerciseId)->exists()) {
            throw new \InvalidArgumentException('already_attempted');
        }
        [$score, $xp] = self::score($exercise->questions ?? [], $answers);
        if ($replay) {
            $xp = 0;
            $earlier = ReadingAttempt::query()
                ->where('user_id', $user->id)
                ->where('study_plan_id', $planId)
                ->where('exercise_id', $exerciseId)
                ->where('completed_at', '<', Carbon::parse($date, 'UTC')->startOfDay())
                ->exists();
            if ($earlier) {
                $xp = $this->rewards->award($user->id, $planId, 'reading_replay', "{$exerciseId}:{$date}", 5, $date);
            }
        }
        $attempt = ReadingAttempt::query()->create([
            'user_id' => $user->id,
            'exercise_id' => $exerciseId,
            'study_plan_id' => $planId,
            'answers' => $answers,
            'score' => $score,
            'xp_earned' => $xp,
            'completed_at' => $completed,
        ]);
        $exercise->view_count = (int) $exercise->view_count + 1;
        $exercise->save();
        $this->progress->updateDaily($user->id, $planId, xp: $replay ? 0 : $xp, activityDate: $date);

        return [$attempt, $exercise];
    }

    /**
     * @return array{0: list<array{0: ReadingAttempt, 1: ReadingExercise}>, 1: int}
     */
    public function history(int $userId, string $language, int $skip, int $limit): array
    {
        $query = ReadingAttempt::query()
            ->select('reading_attempts.*')
            ->join('reading_exercises', 'reading_exercises.id', '=', 'reading_attempts.exercise_id')
            ->where('reading_attempts.user_id', $userId)
            ->where('reading_exercises.target_language', $language);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('reading_attempts.completed_at')->orderByDesc('reading_attempts.id')
            ->skip($skip)->take($limit)->get();
        $items = [];
        foreach ($rows as $attempt) {
            $exercise = ReadingExercise::query()->find($attempt->exercise_id);
            if ($exercise) {
                $items[] = [$attempt, $exercise];
            }
        }

        return [$items, $total];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exerciseOut(ReadingExercise $exercise, bool $withAnswers = false): array
    {
        $questions = [];
        foreach ($exercise->questions ?? [] as $question) {
            $item = [
                'index' => $question['index'],
                'question' => $question['question'],
                'options' => $question['options'],
            ];
            if ($withAnswers) {
                $item['correct'] = $question['correct'];
            }
            $questions[] = $item;
        }

        return [
            'id' => $exercise->id,
            'level' => $exercise->level,
            'target_language' => $exercise->target_language,
            'exercise_type' => $exercise->exercise_type,
            'topic' => $exercise->topic,
            'text' => $exercise->text,
            'questions' => $questions,
        ];
    }

    /**
     * @return list<array{index: int, correct: string}>
     */
    public static function correctAnswers(ReadingExercise|ListeningExercise $exercise): array
    {
        $items = [];
        foreach ($exercise->questions ?? [] as $question) {
            $items[] = ['index' => $question['index'], 'correct' => $question['correct']];
        }

        return $items;
    }

    public function assertOwnedExercise(ReadingExercise $exercise, StudyPlan $plan, bool $replay): void
    {
        if ($exercise->target_language !== $plan->target_language || (! $replay && $exercise->level !== $plan->cefr_level)) {
            throw new ApiException('study_context_changed', 409);
        }
    }

    /**
     * @return list<string>
     */
    private function cultural(string $language): array
    {
        $iso = strtolower(explode('-', $language)[0]);
        $map = [
            'de' => ['German festivals and holiday traditions', 'daily life and customs in German cities'],
            'fr' => ['French cuisine and regional food culture', 'daily life and customs in French cities'],
            'es' => ['Spanish festivals and local traditions', 'daily life and customs in Spanish cities'],
            'en' => ['British and American everyday culture', 'English-speaking cities and traditions'],
        ];

        return $map[$language] ?? $map[$iso] ?? $map['en'];
    }
}
