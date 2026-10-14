<?php

namespace App\Services\Practice;

use App\Exceptions\ApiException;
use App\Models\ListeningAttempt;
use App\Models\ListeningExercise;
use App\Models\StudyPlan;
use App\Models\User;
use App\Services\Learning\ExerciseGeneration;
use App\Services\Learning\GenerationLease;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmResponseError;
use App\Support\Settings;
use Illuminate\Support\Carbon;

final class ListeningService
{
    public const XP = 10;

    /** @var array<string, list<string>> */
    private const TYPES = [
        'A1' => ['monologue', 'announcement', 'voicemail', 'dialogue', 'story'],
        'A2' => ['monologue', 'announcement', 'voicemail', 'dialogue', 'story'],
        'B1' => ['announcement', 'voicemail', 'story', 'dialogue', 'podcast'],
        'B2' => ['voicemail', 'story', 'podcast', 'interview', 'news'],
        'C1' => ['story', 'podcast', 'interview', 'news', 'monologue'],
        'C2' => ['story', 'podcast', 'interview', 'news', 'monologue'],
    ];

    /** @var array<string, int> */
    private const WORDS = ['A1' => 80, 'A2' => 120, 'B1' => 180, 'B2' => 250, 'C1' => 350, 'C2' => 450];

    /** @var array<string, string> */
    private const TYPE_DESC = [
        'monologue' => 'a first-person narrative or personal account',
        'announcement' => 'a public announcement (e.g. at an airport, shop, or office)',
        'voicemail' => 'someone leaving a recorded voice message',
        'story' => 'a short narrative with characters and plot',
        'podcast' => 'an informal presentation or opinion piece by a single speaker',
        'dialogue' => 'a short informal conversation between two people',
        'interview' => 'a structured interview or Q&A between a host and a guest',
        'news' => 'a short news broadcast or report segment',
    ];

    public function __construct(
        private readonly LlmAdapter $llm,
        private readonly ExerciseGeneration $generation,
        private readonly ProgressService $progress,
        private readonly ProgressRewards $rewards,
        private readonly SpeechGateway $speech,
    ) {
    }

    public function available(string $level, string $language, int $userId): ?ListeningExercise
    {
        $done = ListeningAttempt::query()->where('user_id', $userId)->pluck('exercise_id');

        return ListeningExercise::query()
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
    public function startGeneration(StudyPlan $plan, string $voice = ''): array
    {
        if ($this->available($plan->cefr_level, $plan->target_language, $plan->user_id)) {
            return ['status' => 'available'];
        }
        $key = "listening:generating:{$plan->cefr_level}:{$plan->target_language}";
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
        app()->terminating(function () use ($lease, $level, $language, $voice) {
            $this->generation->run($lease, function () use ($lease, $level, $language, $voice) {
                $this->generate($level, $language, $voice, $lease);
            });
        });

        return ['status' => 'generating'];
    }

    public function generate(string $level, string $language, string $voice = '', ?GenerationLease $lease = null): ListeningExercise
    {
        $types = self::TYPES[$level] ?? ['monologue', 'story'];
        $type = $types[array_rand($types)];
        $words = self::WORDS[$level] ?? 200;
        $prompt = Prompts::listeningGeneration(
            Languages::name($language),
            $level,
            $type,
            self::TYPE_DESC[$type] ?? $type,
            $words,
            Languages::comprehensionLengthGuidance($language, $words),
            Prompts::languageOverlay($language),
        );
        try {
            $parsed = $this->llm->structuredOutput([['role' => 'user', 'content' => $prompt]]);
        } catch (LlmResponseError $error) {
            throw new \InvalidArgumentException('LLM failed to produce valid listening exercise JSON: '.$error->getMessage(), previous: $error);
        }
        $text = (string) ($parsed['text'] ?? '');
        $audio = $this->speech->synthesize($text, $voice !== '' ? $voice : null, $language);
        if ($lease) {
            $this->generation->ensureOwner($lease);
        }
        $exercise = ListeningExercise::query()->create([
            'level' => $level,
            'target_language' => $language,
            'exercise_type' => $type,
            'topic' => (string) ($parsed['topic'] ?? ''),
            'text' => $text,
            'audio_path' => '',
            'duration_seconds' => 0,
            'questions' => array_values($parsed['questions'] ?? []),
            'play_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
        $directory = rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/listening';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = $directory.'/'.$exercise->id.'.mp3';
        file_put_contents($path, $audio);
        $exercise->audio_path = $path;
        $exercise->save();

        return $exercise;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, string>  $answers
     * @return array{0: int, 1: int}
     */
    public static function score(array $questions, array $answers): array
    {
        return ReadingService::score($questions, $answers);
    }

    /**
     * @param  array<string, string>  $answers
     * @return array{0: ListeningAttempt, 1: ListeningExercise}
     */
    public function submit(int $exerciseId, User $user, array $answers, bool $replay, int $planId): array
    {
        $completed = Carbon::now('UTC');
        $date = $completed->format('Y-m-d');
        $exercise = ListeningExercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new \InvalidArgumentException('exercise_not_found');
        }
        if (! $replay && ListeningAttempt::query()->where('user_id', $user->id)->where('exercise_id', $exerciseId)->exists()) {
            throw new \InvalidArgumentException('already_attempted');
        }
        [$score, $xp] = self::score($exercise->questions ?? [], $answers);
        if ($replay) {
            $xp = 0;
            $earlier = ListeningAttempt::query()
                ->where('user_id', $user->id)
                ->where('study_plan_id', $planId)
                ->where('exercise_id', $exerciseId)
                ->where('completed_at', '<', Carbon::parse($date, 'UTC')->startOfDay())
                ->exists();
            if ($earlier) {
                $xp = $this->rewards->award($user->id, $planId, 'listening_replay', "{$exerciseId}:{$date}", 5, $date);
            }
        }
        $attempt = ListeningAttempt::query()->create([
            'user_id' => $user->id,
            'exercise_id' => $exerciseId,
            'study_plan_id' => $planId,
            'answers' => $answers,
            'score' => $score,
            'xp_earned' => $xp,
            'completed_at' => $completed,
        ]);
        $exercise->play_count = (int) $exercise->play_count + 1;
        $exercise->save();
        $this->progress->updateDaily($user->id, $planId, xp: $replay ? 0 : $xp, activityDate: $date);

        return [$attempt, $exercise];
    }

    /**
     * @return array{0: list<array{0: ListeningAttempt, 1: ListeningExercise}>, 1: int}
     */
    public function history(int $userId, string $language, int $skip, int $limit): array
    {
        $query = ListeningAttempt::query()
            ->select('listening_attempts.*')
            ->join('listening_exercises', 'listening_exercises.id', '=', 'listening_attempts.exercise_id')
            ->where('listening_attempts.user_id', $userId)
            ->where('listening_exercises.target_language', $language);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('listening_attempts.completed_at')->orderByDesc('listening_attempts.id')
            ->skip($skip)->take($limit)->get();
        $items = [];
        foreach ($rows as $attempt) {
            $exercise = ListeningExercise::query()->find($attempt->exercise_id);
            if ($exercise) {
                $items[] = [$attempt, $exercise];
            }
        }

        return [$items, $total];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exerciseOut(ListeningExercise $exercise, bool $includeText = false): array
    {
        $questions = [];
        foreach ($exercise->questions ?? [] as $question) {
            $questions[] = [
                'index' => $question['index'],
                'question' => $question['question'],
                'options' => $question['options'],
            ];
        }
        $payload = [
            'id' => $exercise->id,
            'level' => $exercise->level,
            'target_language' => $exercise->target_language,
            'exercise_type' => $exercise->exercise_type,
            'topic' => $exercise->topic,
            'duration_seconds' => $exercise->duration_seconds,
            'questions' => $questions,
        ];
        if ($includeText) {
            $payload['text'] = $exercise->text;
        }

        return $payload;
    }

    public function audioPath(int $id): string
    {
        $exercise = ListeningExercise::query()->find($id);
        if (! $exercise) {
            throw new ApiException('exercise_not_found', 404);
        }
        $path = rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/listening/'.$id.'.mp3';
        $root = realpath(rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/listening');
        $real = is_file($path) ? realpath($path) : false;
        if ($root === false || $real === false || ! str_starts_with($real, $root)) {
            throw new ApiException('audio_not_found', 404);
        }

        return $real;
    }
}
