<?php

namespace App\Services\Practice;

use App\Exceptions\ApiException;
use App\Models\Exercise;
use App\Models\GameAdmission;
use App\Models\GameRequest;
use App\Models\GameSession;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\User;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmException;
use App\Support\Settings;
use Illuminate\Support\Carbon;

final class GamesService
{
    public const TYPES = ['detective', 'sentence-order', 'vocabulary-pairs'];

    public const MODES = ['review', 'prepare', 'free'];

    public function __construct(
        private readonly LlmAdapter $llm,
        private readonly ProgressService $progress,
        private readonly ProgressRewards $rewards,
        private readonly Access $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $user, StudyPlan $plan, string $type, int $skip, int $limit): array
    {
        $this->expire($user->id);
        $modes = [];
        foreach (self::MODES as $mode) {
            $context = $this->sourceContext($plan, $mode, $type);
            $modes[$mode] = ['available' => $context['sources'] !== [], 'reason' => $context['reason']];
        }
        $query = GameSession::query()
            ->where('user_id', $user->id)
            ->where('target_language', $plan->target_language)
            ->where('game_type', $type);
        $total = (clone $query)->count();
        $history = $query->orderByDesc('created_at')->orderBy('id')->skip($skip)->take($limit)->get();

        return [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'level' => $plan->cefr_level,
            'modes' => $modes,
            'quota' => $this->quota($user->id),
            'limited' => $this->limited($user),
            'history' => $history->map(fn (GameSession $session) => $this->output($session))->all(),
            'total' => $total,
        ];
    }

    /**
     * @param  array{request_id: string, study_plan_id: int, mode: string}  $body
     * @return array{0: GameSession, 1: bool}
     */
    public function create(User $user, ?StudyPlan $plan, array $body, string $type): array
    {
        $now = Carbon::now('UTC');
        $this->expire($user->id, $now);
        $requestId = $body['request_id'];
        $previous = GameRequest::query()->find($requestId);
        if ($previous) {
            if ((int) $previous->user_id !== (int) $user->id) {
                throw new ApiException('game_not_found', 404);
            }
            if ((int) $previous->study_plan_id !== (int) $body['study_plan_id']
                || $previous->mode !== $body['mode']
                || $previous->game_type !== $type
                || $previous->session_id === null) {
                throw new ApiException('request_conflict', 409);
            }

            return [$this->owned($user->id, (string) $previous->session_id), false];
        }
        $existing = GameSession::query()->find($requestId);
        if ($existing) {
            if ((int) $existing->user_id !== (int) $user->id) {
                throw new ApiException('game_not_found', 404);
            }
            if ((int) $existing->study_plan_id !== (int) $body['study_plan_id']
                || $existing->mode !== $body['mode']
                || $existing->game_type !== $type) {
                throw new ApiException('request_conflict', 409);
            }

            return [$existing, false];
        }
        if (GameAdmission::query()->find($requestId)) {
            throw new ApiException('request_conflict', 409);
        }
        if ($plan === null) {
            throw new ApiException('No active study plan found', 404);
        }
        if ((int) $plan->id !== (int) $body['study_plan_id']) {
            throw new ApiException('study_context_changed', 409);
        }
        $active = GameSession::query()
            ->where('study_plan_id', $plan->id)
            ->where('game_type', $type)
            ->whereIn('status', ['generating', 'ready'])
            ->first();
        $request = new GameRequest([
            'id' => $requestId,
            'user_id' => $user->id,
            'study_plan_id' => $body['study_plan_id'],
            'mode' => $body['mode'],
            'game_type' => $type,
        ]);
        if ($active) {
            $request->session_id = $active->id;
            $request->save();

            return [$active, false];
        }
        $limited = $this->limited($user);
        $quota = $this->quota($user->id, $now);
        if ($limited && $quota['remaining'] === 0) {
            throw new ApiException(['reason' => 'freemium_exhausted', 'feature' => 'games', ...$quota], 402);
        }
        $context = $this->sourceContext($plan, $body['mode'], $type);
        if ($context['sources'] === []) {
            throw new ApiException($context['reason'] ?? 'noSources', 409);
        }
        $deadline = $now->copy()->addSeconds(Settings::int('exercise_generation_timeout_seconds', 600));
        $session = GameSession::query()->create([
            'id' => $requestId,
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'mode' => $body['mode'],
            'game_type' => $type,
            'target_language' => $plan->target_language,
            'native_language' => $user->native_language,
            'level' => $plan->cefr_level,
            'status' => 'generating',
            'context' => $context,
            'challenges' => [],
            'answers' => [],
            'created_at' => $now,
            'deadline' => $deadline,
            'xp_earned' => 0,
        ]);
        $request->session_id = $session->id;
        $request->save();
        if ($limited) {
            GameAdmission::query()->create([
                'id' => $session->id,
                'user_id' => $user->id,
                'date' => $now->toDateString(),
                'status' => 'reserved',
                'deadline' => $deadline,
            ]);
        }

        return [$session, true];
    }

    public function generate(string $sessionId): void
    {
        $session = GameSession::query()->find($sessionId);
        if (! $session || $session->status !== 'generating') {
            return;
        }
        $error = null;
        $content = null;
        try {
            if (Carbon::parse($session->deadline)->utc()->lessThanOrEqualTo(Carbon::now('UTC'))) {
                throw new \RuntimeException('timeout');
            }
            $content = $this->generateContent($session);
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $error = str_contains($message, 'timeout') || str_contains($message, 'timed out') ? 'timeout' : 'generation_failed';
        }
        $session = GameSession::query()->find($sessionId);
        $admission = GameAdmission::query()->find($sessionId);
        if (! $session || $session->status !== 'generating') {
            if ($admission && $admission->status === 'reserved') {
                $admission->status = 'released';
                $admission->save();
            }

            return;
        }
        if ($error || $content === null) {
            $session->status = 'failed';
            $session->error = $error ?: 'generation_failed';
            if ($admission) {
                $admission->status = 'released';
                $admission->save();
            }
        } else {
            $challenges = $content;
            if ($session->game_type === 'vocabulary-pairs') {
                shuffle($challenges);
                $choices = range(0, count($challenges) - 1);
                shuffle($choices);
                foreach ($challenges as $index => $challenge) {
                    $challenges[$index]['choice_index'] = $choices[$index];
                }
            }
            $session->challenges = $challenges;
            $session->answers = array_fill(0, count($challenges), []);
            $session->status = 'ready';
            if ($admission) {
                $admission->status = 'consumed';
                $admission->save();
            }
        }
        $session->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateContent(GameSession $session): array
    {
        $context = $session->context ?? [];
        $prompt = match ($session->game_type) {
            'sentence-order' => Prompts::sentenceOrderPrompt($session->target_language, $session->native_language, $session->level, $session->mode, $context),
            'vocabulary-pairs' => Prompts::vocabularyPairsPrompt($session->target_language, $session->native_language, $session->level, $session->mode, $context),
            default => Prompts::detectivePrompt($session->target_language, $session->native_language, $session->level, $session->mode, $context),
        };
        $reviewer = match ($session->game_type) {
            'sentence-order' => fn (string $base, array $content) => Prompts::sentenceOrderReviewPrompt($base, $content),
            'vocabulary-pairs' => fn (string $base, array $content) => Prompts::vocabularyPairsReviewPrompt($base, $content),
            default => fn (string $base, array $content) => Prompts::detectiveReviewPrompt($base, $content),
        };
        $permitted = [];
        foreach ($context['sources'] ?? [] as $source) {
            $permitted[$source['source_id']] = true;
        }
        $rejection = '';
        $deadline = Carbon::parse($session->deadline)->utc()->getTimestamp();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $content = $this->llm->structuredOutput(
                [['role' => 'system', 'content' => $prompt.$rejection]],
                deadline: (float) $deadline,
            );
            $challenges = $content['challenges'] ?? null;
            if (! is_array($challenges) || $challenges === []) {
                $rejection = "\nPrevious candidate rejected: use only supplied source IDs.";

                continue;
            }
            $badSource = false;
            foreach ($challenges as $challenge) {
                if (! isset($permitted[$challenge['source_id'] ?? ''])) {
                    $badSource = true;
                }
            }
            if ($badSource) {
                $rejection = "\nPrevious candidate rejected: use only supplied source IDs.";

                continue;
            }
            $recent = $context['recent_sentences'] ?? [];
            $repeated = false;
            foreach ($challenges as $challenge) {
                if (in_array($challenge['sentence'] ?? '', $recent, true)) {
                    $repeated = true;
                }
            }
            if ($repeated) {
                $rejection = "\nPrevious candidate rejected: do not repeat recent sentences.";

                continue;
            }
            try {
                if ($session->game_type === 'sentence-order') {
                    self::validateSentenceOrderContent(['challenges' => $challenges]);
                } elseif ($session->game_type === 'vocabulary-pairs') {
                    self::validateVocabularyPairs(['challenges' => $challenges]);
                } else {
                    self::validateDetectiveContent(['challenges' => $challenges]);
                }
            } catch (\InvalidArgumentException $exception) {
                $rejection = "\nPrevious candidate rejected: ".$exception->getMessage();

                continue;
            }
            $review = $this->llm->structuredOutput(
                [['role' => 'system', 'content' => $reviewer($prompt, ['challenges' => $challenges])]],
                deadline: (float) $deadline,
            );
            if (($review['valid'] ?? false) === true) {
                if ($session->game_type === 'sentence-order') {
                    $challenges = array_map(fn (array $challenge) => $this->shuffleOrderChallenge($challenge), $challenges);
                }

                return $challenges;
            }
            $rejection = "\nPrevious candidate rejected; create a new one. Audit: ".($review['reason'] ?? '');
        }
        throw new LlmException('Game content did not pass review');
    }

    /**
     * @param  array<string, mixed>  $challenge
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $challenge
     */
    public static function validateDetectiveChallenge(array $challenge): void
    {
        $sentence = $challenge['sentence'] ?? null;
        $fragments = $challenge['fragments'] ?? null;
        $options = $challenge['options'] ?? null;
        $explanation = $challenge['explanation'] ?? '';
        $source = $challenge['source_id'] ?? '';
        $errorIndex = $challenge['error_index'] ?? null;
        $correctIndex = $challenge['correct_index'] ?? null;
        $corrected = $challenge['corrected_sentence'] ?? null;
        if (! is_string($sentence) || mb_strlen($sentence) < 3 || mb_strlen($sentence) > 500
            || ! is_array($fragments) || count($fragments) < 3 || count($fragments) > 20
            || ! is_array($options) || count($options) !== 3
            || ! is_int($errorIndex) || $errorIndex < 0 || $errorIndex > 19
            || ! is_int($correctIndex) || $correctIndex < 0 || $correctIndex > 2
            || ! is_string($corrected) || mb_strlen($corrected) < 3
            || ! is_string($explanation) || mb_strlen($explanation) < 10
            || ! is_string($source) || $source === '') {
            throw new \InvalidArgumentException('Invalid detective challenge');
        }
        $parts = [];
        foreach ($fragments as $part) {
            if (! is_string($part) || trim($part) === '' || mb_strlen($part) > 200) {
                throw new \InvalidArgumentException('Each fragment must contain selectable text');
            }
            $parts[] = $part;
        }
        if (implode('', $parts) !== $sentence) {
            throw new \InvalidArgumentException('Fragments must concatenate exactly to the sentence');
        }
        if ($errorIndex >= count($parts)) {
            throw new \InvalidArgumentException('Invalid error index');
        }
        $normalized = [];
        foreach ($options as $option) {
            if (! is_string($option)) {
                throw new \InvalidArgumentException('Options must be nonempty and distinct');
            }
            $folded = self::fold($option);
            if ($folded === '' || mb_strlen($option) > 200) {
                throw new \InvalidArgumentException('Options must be nonempty and distinct');
            }
            $normalized[] = $folded;
        }
        if (count(array_unique($normalized)) !== 3) {
            throw new \InvalidArgumentException('Options must be nonempty and distinct');
        }
        if (in_array(self::fold($parts[$errorIndex]), $normalized, true)) {
            throw new \InvalidArgumentException('No option may repeat the erroneous fragment');
        }
        $replaced = $parts;
        $replaced[$errorIndex] = $options[$correctIndex];
        if (implode('', $replaced) !== $corrected) {
            throw new \InvalidArgumentException('The correction must replace exactly one fragment');
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function validateDetectiveContent(array $content): void
    {
        self::validateDistinctSentences($content, fn (array $challenge) => self::validateDetectiveChallenge($challenge));
    }

    /**
     * @param  array<string, mixed>  $challenge
     */
    public static function validateSentenceOrderChallenge(array $challenge): void
    {
        $sentence = $challenge['sentence'] ?? null;
        $clue = $challenge['clue'] ?? null;
        $fragments = $challenge['fragments'] ?? null;
        $separator = $challenge['separator'] ?? null;
        $orders = $challenge['accepted_orders'] ?? null;
        $explanation = $challenge['explanation'] ?? '';
        $source = $challenge['source_id'] ?? '';
        if (! is_string($sentence) || mb_strlen($sentence) < 3
            || ! is_string($clue) || mb_strlen($clue) < 3
            || ! is_array($fragments) || count($fragments) < 3 || count($fragments) > 12
            || ! in_array($separator, ['', ' '], true)
            || ! is_array($orders) || $orders === [] || count($orders) > 8
            || ! is_string($explanation) || mb_strlen($explanation) < 10
            || ! is_string($source) || $source === '') {
            throw new \InvalidArgumentException('Invalid sentence-order challenge');
        }
        $parts = [];
        foreach ($fragments as $part) {
            if (! is_string($part) || trim($part) === '' || $part !== trim($part) || mb_strlen($part) > 200) {
                throw new \InvalidArgumentException('Fragments must be nonempty, bounded and have no boundary whitespace');
            }
            $parts[] = $part;
        }
        $sentences = [];
        foreach ($orders as $order) {
            if (! is_array($order) || ! array_is_list($order)) {
                throw new \InvalidArgumentException('Every solution must use each fragment exactly once');
            }
            $indexes = [];
            foreach ($order as $index) {
                if (! is_int($index)) {
                    throw new \InvalidArgumentException('Every solution must use each fragment exactly once');
                }
                $indexes[] = $index;
            }
            $sorted = $indexes;
            sort($sorted);
            if ($sorted !== range(0, count($parts) - 1)) {
                throw new \InvalidArgumentException('Every solution must use each fragment exactly once');
            }
            $sentences[] = implode($separator, array_map(fn (int $index) => $parts[$index], $indexes));
        }
        if ($sentences[0] !== $sentence) {
            throw new \InvalidArgumentException('The first solution must reconstruct the sentence exactly');
        }
        if (count(array_unique($sentences)) !== count($sentences)) {
            throw new \InvalidArgumentException('Solutions must be textually distinct');
        }
        if (count(array_unique($parts)) < 2) {
            throw new \InvalidArgumentException('The challenge must admit an incorrect arrangement');
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function validateSentenceOrderContent(array $content): void
    {
        self::validateDistinctSentences($content, fn (array $challenge) => self::validateSentenceOrderChallenge($challenge));
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function validateVocabularyPairs(array $content): void
    {
        $challenges = $content['challenges'] ?? null;
        if (! is_array($challenges) || count($challenges) !== 5) {
            throw new \InvalidArgumentException('Five challenges are required');
        }
        foreach (['term', 'meaning'] as $field) {
            $values = [];
            foreach ($challenges as $challenge) {
                if (! is_array($challenge)) {
                    throw new \InvalidArgumentException('Five distinct nonempty terms and meanings are required');
                }
                $raw = $challenge[$field] ?? null;
                $sentence = $challenge['sentence'] ?? '';
                $translation = $challenge['translation'] ?? '';
                $source = $challenge['source_id'] ?? '';
                if (! is_string($raw) || ! is_string($sentence) || mb_strlen($sentence) < 3
                    || ! is_string($translation) || mb_strlen($translation) < 3
                    || ! is_string($source) || $source === '') {
                    throw new \InvalidArgumentException('Five distinct nonempty terms and meanings are required');
                }
                $folded = self::fold($raw);
                if ($folded === '') {
                    throw new \InvalidArgumentException('Five distinct nonempty terms and meanings are required');
                }
                $values[] = $folded;
            }
            if (count(array_unique($values)) !== 5) {
                throw new \InvalidArgumentException('Five distinct nonempty terms and meanings are required');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  callable(array<string, mixed>): void  $validate
     */
    private static function validateDistinctSentences(array $content, callable $validate): void
    {
        $challenges = $content['challenges'] ?? null;
        if (! is_array($challenges) || count($challenges) !== 5) {
            throw new \InvalidArgumentException('Five challenges are required');
        }
        $sentences = [];
        foreach ($challenges as $challenge) {
            if (! is_array($challenge)) {
                throw new \InvalidArgumentException('Five different sentences are required');
            }
            $validate($challenge);
            $sentences[] = mb_strtolower(trim((string) $challenge['sentence']));
        }
        if (count(array_unique($sentences)) !== 5) {
            throw new \InvalidArgumentException('Five different sentences are required');
        }
    }

    private static function fold(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        return mb_strtolower($value, 'UTF-8');
    }

    public function shuffleOrderChallenge(array $challenge): array
    {
        $fragments = array_values($challenge['fragments'] ?? []);
        $separator = (string) ($challenge['separator'] ?? ' ');
        $accepted = [];
        foreach ($challenge['accepted_orders'] ?? [] as $order) {
            $accepted[] = implode($separator, array_map(fn ($i) => $fragments[$i], $order));
        }
        $indices = array_keys($fragments);
        for ($try = 0; $try < 100; $try++) {
            shuffle($indices);
            $shuffled = array_map(fn ($i) => $fragments[$i], $indices);
            if (! in_array(implode($separator, $shuffled), $accepted, true)) {
                $positions = [];
                foreach ($indices as $new => $old) {
                    $positions[$old] = $new;
                }
                $challenge['fragments'] = $shuffled;
                $challenge['accepted_orders'] = array_map(
                    fn (array $order) => array_map(fn ($i) => $positions[$i], $order),
                    $challenge['accepted_orders'],
                );

                return $challenge;
            }
        }
        throw new LlmException('Sentence Order challenge cannot be shuffled into an unsolved order');
    }

    public function owned(int $userId, string $sessionId): GameSession
    {
        $request = GameRequest::query()->find($sessionId);
        if ($request) {
            if ((int) $request->user_id !== $userId || $request->session_id === null) {
                throw new ApiException('game_not_found', 404);
            }
            $sessionId = (string) $request->session_id;
        }
        $session = GameSession::query()->where('id', $sessionId)->where('user_id', $userId)->first();
        if (! $session) {
            throw new ApiException('game_not_found', 404);
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function output(GameSession $session): array
    {
        $challenges = [];
        $answers = $session->answers ?? [];
        foreach ($session->challenges ?? [] as $index => $challenge) {
            $answer = $answers[$index] ?? [];
            if ($session->game_type === 'vocabulary-pairs') {
                $item = ['index' => $index, 'term' => $challenge['term'], 'matched' => (bool) ($answer['matched'] ?? false)];
                if ($item['matched']) {
                    $item['choice'] = $challenge['choice_index'];
                    $item['assisted'] = (bool) ($answer['assisted'] ?? false);
                }
                if ($session->status === 'completed') {
                    $item['sentence'] = $challenge['sentence'];
                    $item['translation'] = $challenge['translation'];
                }
                $challenges[] = $item;

                continue;
            }
            if ($session->game_type === 'sentence-order') {
                $item = [
                    'index' => $index,
                    'clue' => $challenge['clue'],
                    'fragments' => $challenge['fragments'],
                    'separator' => $challenge['separator'],
                    'order' => $answer['order'] ?? null,
                ];
                if (array_key_exists('order', $answer)) {
                    $item['correct'] = $answer['correct'];
                    $item['corrected_sentence'] = $answer['correct'] ? $answer['sentence'] : $challenge['sentence'];
                    $item['explanation'] = $challenge['explanation'];
                }
                $challenges[] = $item;

                continue;
            }
            $item = [
                'index' => $index,
                'sentence' => $challenge['sentence'],
                'fragments' => $challenge['fragments'],
                'detection' => $answer['detection'] ?? null,
                'correction' => $answer['correction'] ?? null,
            ];
            if (array_key_exists('detection', $answer)) {
                $item['error_index'] = $challenge['error_index'];
                $item['options'] = $challenge['options'];
            }
            if (array_key_exists('correction', $answer)) {
                $item['correct_index'] = $challenge['correct_index'];
                $item['corrected_sentence'] = $challenge['corrected_sentence'];
                $item['explanation'] = $challenge['explanation'];
            }
            $challenges[] = $item;
        }
        $output = [
            'id' => $session->id,
            'game_type' => $session->game_type,
            'study_plan_id' => $session->study_plan_id,
            'target_language' => $session->target_language,
            'native_language' => $session->native_language,
            'level' => $session->level,
            'mode' => $session->mode,
            'status' => $session->status,
            'error' => $session->error,
            'xp_earned' => $session->xp_earned,
            'challenges' => $challenges,
            'created_at' => Text::iso($session->created_at, true),
            'remaining_seconds' => $session->status === 'generating'
                ? max(0, Carbon::parse($session->deadline)->utc()->getTimestamp() - Carbon::now('UTC')->getTimestamp())
                : null,
        ];
        if ($session->game_type === 'vocabulary-pairs') {
            $meanings = [];
            $attemptRows = [];
            foreach ($session->challenges ?? [] as $index => $challenge) {
                $meanings[] = ['index' => $challenge['choice_index'], 'text' => $challenge['meaning']];
                foreach (($answers[$index]['attempts'] ?? []) as $attempt) {
                    $attemptRows[] = $attempt + ['challenge' => $index];
                }
            }
            usort($meanings, fn ($a, $b) => $a['index'] <=> $b['index']);
            usort($attemptRows, fn ($a, $b) => $a['attempt'] <=> $b['attempt']);
            $output['meanings'] = $meanings;
            $output['attempts'] = $attemptRows;
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{session: GameSession, answer_number: ?int, correct: bool}
     */
    public function answer(int $userId, string $sessionId, array $body): array
    {
        $completedAt = Carbon::now('UTC');
        $session = $this->owned($userId, $sessionId);
        $session = GameSession::query()->find($session->id);
        if (! $session) {
            throw new ApiException('game_not_found', 404);
        }
        $this->assertAnswerShape($session, $body);
        if (! in_array($session->status, ['ready', 'completed'], true)) {
            throw new ApiException('game_not_ready', 409);
        }
        $ordering = $session->game_type === 'sentence-order';
        $pairing = $session->game_type === 'vocabulary-pairs';
        $answers = $session->answers ?? [];
        $previousCount = 0;
        if ($pairing) {
            foreach ($answers as $answer) {
                $previousCount += count($answer['attempts'] ?? []);
            }
        } elseif ($ordering) {
            foreach ($answers as $answer) {
                $previousCount += array_key_exists('order', $answer) ? 1 : 0;
            }
        } else {
            foreach ($answers as $answer) {
                $previousCount += (array_key_exists('detection', $answer) ? 1 : 0) + (array_key_exists('correction', $answer) ? 1 : 0);
            }
        }
        if ($pairing !== (($body['step'] ?? '') === 'match')) {
            throw new ApiException('invalid_game_answer', 422);
        }
        if ($pairing) {
            $next = $this->matchPair($session, $body);
            if ($next === null) {
                return ['session' => $session, 'answer_number' => null, 'correct' => false];
            }
            $session->answers = $next;
            $complete = true;
            $correctCount = 0;
            foreach ($next as $answer) {
                if (! ($answer['matched'] ?? false)) {
                    $complete = false;
                }
                if (! ($answer['assisted'] ?? false)) {
                    $correctCount++;
                }
            }
            $this->finish($session, $completedAt, $complete, $correctCount);
            $last = $next[$body['challenge']]['attempts'];
            $correct = (bool) $last[array_key_last($last)]['correct'];

            return ['session' => $session->fresh(), 'answer_number' => $previousCount + 1, 'correct' => $correct];
        }
        if ($ordering !== (($body['step'] ?? '') === 'order')) {
            throw new ApiException('invalid_game_answer', 422);
        }
        $challengeIndex = (int) $body['challenge'];
        $answer = $answers[$challengeIndex] ?? [];
        $key = $ordering ? 'order' : (($body['step'] ?? '') === 'detect' ? 'detection' : 'correction');
        $choice = $ordering ? $body['order'] : $body['choice'];
        if (array_key_exists($key, $answer)) {
            if ($answer[$key] !== $choice) {
                throw new ApiException('already_answered', 409);
            }

            return ['session' => $session, 'answer_number' => null, 'correct' => false];
        }
        $completionKey = $ordering ? 'order' : 'correction';
        $first = 5;
        foreach ($answers as $index => $row) {
            if (! array_key_exists($completionKey, $row)) {
                $first = $index;
                break;
            }
        }
        if ($challengeIndex !== $first) {
            throw new ApiException('answer_out_of_order', 409);
        }
        $challenge = $session->challenges[$challengeIndex];
        if ($ordering) {
            $expected = range(0, count($challenge['fragments']) - 1);
            $sorted = $body['order'];
            sort($sorted);
            if ($sorted !== $expected) {
                throw new ApiException('invalid_order', 422);
            }
            $sentence = implode($challenge['separator'], array_map(fn ($i) => $challenge['fragments'][$i], $body['order']));
            $accepted = [];
            foreach ($challenge['accepted_orders'] as $order) {
                $accepted[] = implode($challenge['separator'], array_map(fn ($i) => $challenge['fragments'][$i], $order));
            }
            $answers[$challengeIndex]['sentence'] = $sentence;
            $answers[$challengeIndex]['correct'] = in_array($sentence, $accepted, true);
        } else {
            if (($body['step'] ?? '') === 'correct' && ! array_key_exists('detection', $answer)) {
                throw new ApiException('detect_first', 409);
            }
            $choices = ($body['step'] ?? '') === 'detect' ? $challenge['fragments'] : $challenge['options'];
            if ((int) $body['choice'] >= count($choices)) {
                throw new ApiException('invalid_choice', 422);
            }
        }
        $answers[$challengeIndex][$key] = $choice;
        $session->answers = $answers;
        $correctCount = 0;
        foreach ($answers as $index => $row) {
            $ok = $ordering
                ? (bool) ($row['correct'] ?? false)
                : (($row['detection'] ?? null) === $session->challenges[$index]['error_index']
                    && ($row['correction'] ?? null) === $session->challenges[$index]['correct_index']);
            if ($ok) {
                $correctCount++;
            }
        }
        $complete = true;
        foreach ($answers as $row) {
            if (! array_key_exists($completionKey, $row)) {
                $complete = false;
            }
        }
        $this->finish($session, $completedAt, $complete, $correctCount);
        $actionCorrect = $ordering
            ? (bool) $answers[$challengeIndex]['correct']
            : $choice === $challenge[($body['step'] ?? '') === 'detect' ? 'error_index' : 'correct_index'];

        return ['session' => $session->fresh(), 'answer_number' => $previousCount + 1, 'correct' => $actionCorrect];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertAnswerShape(GameSession $session, array $body): void
    {
        $step = $body['step'] ?? null;
        if ($session->game_type === 'vocabulary-pairs') {
            if ($step !== 'match') {
                throw new ApiException('invalid_game_answer', 422);
            }
            foreach (['attempt' => [0, 24], 'challenge' => [0, 4], 'choice' => [0, 4]] as $field => [$min, $max]) {
                $value = $body[$field] ?? null;
                if (! is_int($value) || $value < $min || $value > $max) {
                    throw new ApiException('invalid_game_answer', 422);
                }
            }

            return;
        }
        $challenge = $body['challenge'] ?? null;
        if (! is_int($challenge) || $challenge < 0 || $challenge > 4) {
            throw new ApiException('invalid_game_answer', 422);
        }
        if ($session->game_type === 'sentence-order') {
            if ($step !== 'order' || ! is_array($body['order'] ?? null) || ! array_is_list($body['order'])) {
                throw new ApiException('invalid_game_answer', 422);
            }
            $order = $body['order'];
            if (count($order) < 3 || count($order) > 12) {
                throw new ApiException('invalid_game_answer', 422);
            }
            foreach ($order as $index) {
                if (! is_int($index) || $index < 0 || $index > 11) {
                    throw new ApiException('invalid_game_answer', 422);
                }
            }

            return;
        }
        $choice = $body['choice'] ?? null;
        if (! in_array($step, ['detect', 'correct'], true) || ! is_int($choice) || $choice < 0 || $choice > 19) {
            throw new ApiException('invalid_game_answer', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>|null
     */
    private function matchPair(GameSession $session, array $body): ?array
    {
        $attempts = [];
        foreach ($session->answers ?? [] as $index => $answer) {
            foreach ($answer['attempts'] ?? [] as $attempt) {
                $attempts[] = [$index, $attempt];
            }
        }
        foreach ($attempts as [$index, $attempt]) {
            if ((int) $attempt['attempt'] === (int) $body['attempt']) {
                if ($index !== (int) $body['challenge'] || (int) $attempt['choice'] !== (int) $body['choice']) {
                    throw new ApiException('attempt_conflict', 409);
                }

                return null;
            }
        }
        if ((int) $body['attempt'] !== count($attempts)) {
            throw new ApiException('attempt_out_of_order', 409);
        }
        $other = null;
        foreach ($session->challenges as $index => $challenge) {
            if ((int) $challenge['choice_index'] === (int) $body['choice']) {
                $other = $index;
            }
        }
        if ($other === null) {
            throw new ApiException('invalid_game_answer', 422);
        }
        $answers = $session->answers;
        if (($answers[$body['challenge']]['matched'] ?? false) || ($answers[$other]['matched'] ?? false)) {
            throw new ApiException('pair_already_matched', 409);
        }
        foreach ($attempts as [$index, $attempt]) {
            if ($index === (int) $body['challenge'] && (int) $attempt['choice'] === (int) $body['choice']) {
                throw new ApiException('pair_already_tried', 409);
            }
        }
        $correct = $other === (int) $body['challenge'];
        $answers[$body['challenge']]['attempts'] = [
            ...($answers[$body['challenge']]['attempts'] ?? []),
            ['attempt' => (int) $body['attempt'], 'choice' => (int) $body['choice'], 'correct' => $correct],
        ];
        if ($correct) {
            $answers[$body['challenge']]['matched'] = true;
        } else {
            $answers[$body['challenge']]['assisted'] = true;
            $answers[$other]['assisted'] = true;
        }

        return $answers;
    }

    public function finish(GameSession $session, Carbon $completedAt, bool $complete, int $correct): void
    {
        $date = $completedAt->format('Y-m-d');
        $this->progress->updateDaily((int) $session->user_id, (int) $session->study_plan_id, activityDate: $date);
        if ($complete) {
            $session->status = 'completed';
            $session->completed_at = $completedAt;
            $earned = (int) \App\Models\ProgressReward::query()
                ->where('study_plan_id', $session->study_plan_id)
                ->where('kind', 'games')
                ->whereDate('date', $date)
                ->sum('xp');
            $xp = min(5 + 2 * $correct, max(0, 45 - $earned));
            $session->xp_earned = $this->rewards->award(
                (int) $session->user_id,
                (int) $session->study_plan_id,
                'games',
                $session->id,
                $xp,
                $date,
            );
        }
        $session->save();
    }

    public function abandon(int $userId, string $sessionId): GameSession
    {
        $session = $this->owned($userId, $sessionId);
        if ($session->status === 'generating') {
            throw new ApiException('game_not_ready', 409);
        }
        if ($session->status === 'ready') {
            $session->status = 'abandoned';
            $session->save();
        }

        return $session->fresh();
    }

    public function expire(int $userId, ?Carbon $now = null): void
    {
        $now ??= Carbon::now('UTC');
        $rows = GameSession::query()
            ->where('user_id', $userId)
            ->where('status', 'generating')
            ->where('deadline', '<=', $now)
            ->get();
        foreach ($rows as $row) {
            $row->status = 'failed';
            $row->error = 'timeout';
            $row->save();
            $admission = GameAdmission::query()->find($row->id);
            if ($admission && $admission->status === 'reserved') {
                $admission->status = 'released';
                $admission->save();
            }
        }
    }

    /**
     * @return array{remaining: int, limit: int}
     */
    public function quota(int $userId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now('UTC');
        $limit = Settings::int('freemium_games_daily', 3);
        $used = GameAdmission::query()
            ->where('user_id', $userId)
            ->whereDate('date', $now->toDateString())
            ->where(function ($query) use ($now) {
                $query->where('status', 'consumed')
                    ->orWhere(function ($reserved) use ($now) {
                        $reserved->where('status', 'reserved')->where('deadline', '>', $now);
                    });
            })
            ->count();

        return ['remaining' => max(0, $limit - $used), 'limit' => $limit];
    }

    public function limited(User $user): bool
    {
        return ! $this->access->isSubscribed($user) && ! $this->access->freemiumTrialActive($user);
    }

    /**
     * @return array{sources: list<array<string, mixed>>, upcoming: mixed, mistakes: list<array<string, mixed>>, recent_sentences: list<string>, reason: ?string}
     */
    public function sourceContext(StudyPlan $plan, string $mode, string $type): array
    {
        $completed = Lesson::query()
            ->where('study_plan_id', $plan->id)
            ->where('is_completed', true)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();
        $sources = [];
        $upcoming = null;
        if ($mode === 'free' && $type === 'vocabulary-pairs') {
            $sets = Catalog::vocabularyByLevel($plan->cefr_level, $plan->target_language);
            shuffle($sets);
            foreach (array_slice($sets, 0, min(4, count($sets))) as $group) {
                $words = [];
                foreach (array_slice($group['words'] ?? [], 0, 20) as $word) {
                    $words[] = ['term' => $word['word'], 'meaning' => $word['definition'], 'example' => $word['example']];
                }
                $sources[] = ['source_id' => 'vocabulary:'.$group['id'], 'title' => $group['topic'], 'vocabulary' => $words];
            }
        } elseif ($mode === 'free') {
            $topics = array_values(array_filter(
                Catalog::grammarTopics($plan->target_language),
                fn (array $topic) => ($topic['level'] ?? '') === $plan->cefr_level,
            ));
            shuffle($topics);
            foreach (array_slice($topics, 0, min(8, count($topics))) as $topic) {
                $sources[] = [
                    'source_id' => 'grammar:'.$topic['slug'],
                    'title' => $topic['title'],
                    'rules' => array_slice($topic['rules'] ?? [], 0, 6),
                    'explanation' => mb_substr((string) ($topic['explanation'] ?? ''), 0, 1500),
                ];
            }
        } else {
            $eligible = $completed;
            if ($mode === 'prepare') {
                $completedSlots = [];
                foreach ($completed as $lesson) {
                    $completedSlots[$lesson->week_number.'|'.$lesson->day_number.'|'.$lesson->title] = true;
                }
                $slots = [];
                foreach (($plan->generated_plan['weekly_plan'] ?? []) as $week) {
                    foreach ($week['days'] ?? [] as $day) {
                        $slots[] = [$week['week'], $day];
                    }
                }
                usort($slots, fn ($a, $b) => [$a[0], $a[1]['day']] <=> [$b[0], $b[1]['day']]);
                $upcoming = null;
                foreach ($slots as [$week, $day]) {
                    $position = ($week - 1) * $plan->days_per_week + $day['day'] - 1;
                    $slotKey = $week.'|'.$day['day'].'|'.($day['title'] ?? '');
                    if ($position >= $plan->progress_day
                        && ! in_array($day['unit_id'] ?? '', Catalog::COMPLETION_UNIT_IDS, true)
                        && ! isset($completedSlots[$slotKey])) {
                        $upcoming = $day;
                        break;
                    }
                }
                if ($upcoming === null) {
                    return ['sources' => [], 'upcoming' => null, 'mistakes' => [], 'recent_sentences' => [], 'reason' => 'noUpcoming'];
                }
                $allowed = [$upcoming['unit_id'] ?? ''];
                foreach (Catalog::curriculumUnits($plan->cefr_level, $plan->target_language) as $unit) {
                    if (($unit['id'] ?? '') === ($upcoming['unit_id'] ?? '') && ! empty($unit['prerequisite_unit'])) {
                        $allowed[] = $unit['prerequisite_unit'];
                    }
                }
                $eligible = $completed->filter(fn (Lesson $lesson) => in_array($lesson->unit_id, $allowed, true))->values();
            }
            foreach ($eligible->take(12) as $lesson) {
                $content = $lesson->content ?? [];
                $sources[] = [
                    'source_id' => 'lesson:'.$lesson->id,
                    'title' => $lesson->title,
                    'explanation' => mb_substr((string) ($content['explanation'] ?? ''), 0, 1500),
                    'vocabulary' => mb_substr(is_string($content['vocabulary'] ?? null) ? $content['vocabulary'] : Text::pyJson($content['vocabulary'] ?? ''), 0, 800),
                ];
            }
        }
        $lessonIds = [];
        foreach ($sources as $source) {
            if (str_starts_with($source['source_id'], 'lesson:')) {
                $lessonIds[] = (int) substr($source['source_id'], 7);
            }
        }
        $mistakes = [];
        if ($lessonIds !== []) {
            $exercises = Exercise::query()
                ->whereIn('lesson_id', $lessonIds)
                ->whereNotNull('user_answer')
                ->where('score', '<', 0.8)
                ->orderByDesc('answered_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get();
            foreach ($exercises as $exercise) {
                $mistakes[] = [
                    'question' => mb_substr((string) $exercise->question, 0, 250),
                    'answer' => mb_substr((string) $exercise->user_answer, 0, 100),
                    'correction' => mb_substr((string) $exercise->correct_answer, 0, 200),
                ];
            }
        }
        $recent = [];
        $previous = GameSession::query()
            ->where('study_plan_id', $plan->id)
            ->whereIn('status', ['completed', 'abandoned'])
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(5)
            ->get();
        foreach ($previous as $session) {
            $challenges = $session->challenges ?? [];
            $sessionAnswers = $session->answers ?? [];
            foreach ($challenges as $index => $challenge) {
                $answer = $sessionAnswers[$index] ?? [];
                if (isset($challenge['sentence'])) {
                    $recent[] = $challenge['sentence'];
                }
                if ($session->game_type === 'vocabulary-pairs' && ($answer['assisted'] ?? false)) {
                    $mistakes[] = ['question' => $challenge['term'], 'correction' => $challenge['meaning']];
                } elseif ($session->game_type === 'sentence-order' && array_key_exists('order', $answer) && ! ($answer['correct'] ?? false)) {
                    $mistakes[] = ['question' => $challenge['clue'], 'correction' => $challenge['sentence']];
                } elseif (array_key_exists('correction', $answer) && (
                    ($answer['detection'] ?? null) !== ($challenge['error_index'] ?? null)
                    || ($answer['correction'] ?? null) !== ($challenge['correct_index'] ?? null)
                )) {
                    $mistakes[] = ['question' => $challenge['sentence'], 'correction' => $challenge['corrected_sentence']];
                }
            }
        }

        return [
            'sources' => $sources,
            'upcoming' => $upcoming,
            'mistakes' => array_slice($mistakes, 0, 16),
            'recent_sentences' => $recent,
            'reason' => $sources === [] ? 'noSources' : null,
        ];
    }
}
