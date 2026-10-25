<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\StudyPlan;
use App\Models\UserLanguage;
use App\Services\Learning\ActivePlan;
use App\Services\Learning\AssessmentService;
use App\Services\Learning\AssessmentVoiceTrialService;
use App\Services\Learning\CompletionService;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\PlanCapacityException;
use App\Services\Learning\Presenters;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\RequestChecks;
use App\Services\Learning\StudyPlanGenerator;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmTimeoutException;
use App\Services\Llm\LlmUnavailableException;
use App\Services\Llm\StructuredOutput;
use App\Services\Llm\LlmClient;
use App\Services\Prompts\AssessmentPrompts;
use App\Services\Prompts\PromptCatalog;
use App\Services\Redis\ArrayRedis;
use App\Services\UserLanguageService;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AssessmentController extends Controller
{
    private StructuredOutput $structured;

    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly AssessmentService $assessment,
        private readonly AssessmentVoiceTrialService $voiceTrials,
        private readonly StudyPlanGenerator $generator,
        private readonly CompletionService $completion,
        private readonly ProgressRewards $rewards,
        private readonly UserLanguageService $languages,
        private readonly ActivePlan $plans,
        LlmClient $llm,
    ) {
        $this->structured = new StructuredOutput($llm);
    }

    public function start(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $language = $request->query('language');
        if (is_string($language) && $language !== '') {
            $target = $language;
        } else {
            $active = $this->plans->language($user);
            $target = $active?->target_language ?: $user->target_language;
        }
        $prompt = AssessmentPrompts::legacyQuiz(LearningCatalog::languageName($target), PromptCatalog::overlay($target));
        try {
            $quiz = $this->structured->decode(
                [['role' => 'system', 'content' => $prompt]],
                function (mixed $data) {
                    if (! is_array($data) || ! is_array($data['questions'] ?? null)) {
                        throw new \InvalidArgumentException('quiz is invalid');
                    }

                    return $data;
                },
            );
        } catch (LlmTimeoutException) {
            throw new ApiException('LLM timed out generating assessment quiz.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        }
        $sessionId = (string) \Illuminate\Support\Str::uuid();
        $this->redis->delete('assessment:'.$user->id);
        $this->redis->setex('assessment:'.$user->id.':'.$target, 1800, json_encode([
            'session_id' => $sessionId,
            'quiz' => $quiz,
            'target_language' => $target,
        ]));

        return response()->json(['quiz' => $this->stripAnswers($quiz), 'session_id' => $sessionId]);
    }

    public function bank(Request $request): JsonResponse
    {
        Presenters::user($request->attributes->get('user'));
        $language = $request->query('language', 'en-GB');
        if (! is_string($language) || $language === '') {
            $language = 'en-GB';
        }

        return response()->json(['questions' => LearningCatalog::assessmentBank($language)]);
    }

    public function submit(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        if (! isset($data['answers']) || ! is_array($data['answers'])) {
            RequestChecks::fail(['body', 'answers']);
        }
        $targetLanguage = $data['target_language'] ?? null;
        $sessionRaw = null;
        $redisKey = null;
        if (is_string($targetLanguage) && $targetLanguage !== '') {
            $candidate = 'assessment:'.$user->id.':'.$targetLanguage;
            $sessionRaw = $this->redis->get($candidate);
            if ($sessionRaw) {
                $redisKey = $candidate;
            }
        }
        if (! $sessionRaw) {
            $active = $this->plans->language($user);
            if ($active) {
                $candidate = 'assessment:'.$user->id.':'.$active->target_language;
                $sessionRaw = $this->redis->get($candidate);
                if ($sessionRaw) {
                    $redisKey = $candidate;
                }
            }
        }
        if (! $sessionRaw) {
            $candidate = 'assessment:'.$user->id;
            $sessionRaw = $this->redis->get($candidate);
            if ($sessionRaw) {
                $redisKey = $candidate;
            }
        }
        if (! $sessionRaw || ! $redisKey) {
            throw new ApiException('No active assessment session.', 404);
        }
        $session = json_decode($sessionRaw, true);
        $target = $session['target_language'] ?? (is_string($targetLanguage) ? $targetLanguage : null) ?? 'en-GB';
        $overlay = PromptCatalog::overlay($target);
        try {
            $messages = [['role' => 'system', 'content' => AssessmentPrompts::legacyEvalSystem()]];
            if ($overlay !== '') {
                $messages[] = ['role' => 'system', 'content' => $overlay];
            }
            $messages[] = ['role' => 'user', 'content' => AssessmentPrompts::legacyEvalUser($session['session_id'], $session['quiz'], $data)];
            $result = $this->structured->decode($messages, function (mixed $payload) {
                if (! is_array($payload)) {
                    throw new \InvalidArgumentException('evaluation is invalid');
                }

                return $payload;
            });
        } catch (LlmTimeoutException) {
            throw new ApiException('LLM timed out evaluating assessment.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        } finally {
            $this->redis->delete($redisKey);
        }

        return response()->json([
            'cefr_level' => $result['cefr_level'] ?? 'A1',
            'score' => (float) ($result['score'] ?? 0),
            'skill_profile' => new \stdClass,
            'strengths' => $result['strengths'] ?? [],
            'weaknesses' => $result['weaknesses'] ?? [],
            'analysis' => $result['analysis'] ?? '',
        ]);
    }

    public function started(Request $request): Response
    {
        Presenters::user($request->attributes->get('user'));
        $attempt = $request->header('X-Assessment-Attempt');
        RequestChecks::uuid($attempt, ['header', 'X-Assessment-Attempt']);

        return response()->noContent();
    }

    public function evaluate(Request $request): JsonResponse
    {
        Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        if (! isset($data['answers']) || ! is_array($data['answers']) || ! array_is_list($data['answers'])) {
            RequestChecks::fail(['body', 'answers']);
        }
        foreach ($data['answers'] as $index => $answer) {
            if (! is_array($answer) || ! isset($answer['question_id'], $answer['skill'], $answer['difficulty']) || ! array_key_exists('correct', $answer) || ! is_bool($answer['correct'])) {
                RequestChecks::fail(['body', 'answers', $index]);
            }
        }

        return response()->json($this->assessment->evaluateAdaptiveQuiz($data['answers']));
    }

    public function freeWrite(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        foreach (['preliminary_level', 'writing_prompt', 'student_answer'] as $field) {
            RequestChecks::requireString($data, $field);
        }
        $target = $user->target_language;
        $active = $this->plans->language($user);
        if ($active) {
            $target = $active->target_language;
        }
        foreach ([
            'assessment:'.$user->id.':'.$target,
            'assessment:'.$user->id.':'.$user->target_language,
            'assessment:'.$user->id,
        ] as $key) {
            $raw = $this->redis->get($key);
            if ($raw) {
                $session = json_decode($raw, true);
                $target = $session['target_language'] ?? $target;
                break;
            }
        }
        try {
            return response()->json($this->assessment->evaluateFreeWrite(
                $data['preliminary_level'],
                $data['writing_prompt'],
                $data['student_answer'],
                $target,
            ));
        } catch (LlmTimeoutException) {
            throw new ApiException('LLM timed out evaluating free-write.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        }
    }

    public function complete(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $cefr = RequestChecks::cefr($data);
        $weeks = RequestChecks::intWithDefault($data, 'duration_weeks', 12, 1, 100000);
        $days = RequestChecks::intWithDefault($data, 'days_per_week', 4, 1, 100000);
        $goals = $data['goals'] ?? ['grammar', 'vocabulary', 'reading', 'writing'];
        if (! is_array($goals)) {
            RequestChecks::fail(['body', 'goals']);
        }
        $explicit = $data['target_language'] ?? null;
        if ($explicit !== null && ! is_string($explicit)) {
            RequestChecks::fail(['body', 'target_language']);
        }
        $target = $explicit ?: $user->target_language;
        foreach ([
            'assessment:'.$user->id.':'.$target,
            'assessment:'.$user->id.':'.$user->target_language,
            'assessment:'.$user->id,
        ] as $key) {
            $raw = $this->redis->get($key);
            if ($raw) {
                $session = json_decode($raw, true);
                $target = $session['target_language'] ?? $target;
                break;
            }
        }
        $units = LearningCatalog::units($cefr, $target);
        try {
            $this->generator->assertCapacity($units, $weeks, $days);
        } catch (PlanCapacityException $e) {
            throw new ApiException($e->getMessage(), 400);
        }
        $userLang = $this->languages->ensure($user->id, $target);
        StudyPlan::query()->where('user_language_id', $userLang->id)->where('is_active', true)->update(['is_active' => false]);
        $generated = $this->generator->generate([
            'cefr_level' => $cefr,
            'duration_weeks' => $weeks,
            'days_per_week' => $days,
        ], $target);
        $plan = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $userLang->id,
            'cefr_level' => $cefr,
            'target_language' => $target,
            'goals' => array_values($goals),
            'duration_weeks' => $weeks,
            'days_per_week' => $days,
            'current_unit' => $units[0]['id'] ?? '',
            'generated_plan' => $generated,
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);
        $voice = $this->voiceTrials->create(
            $user->id,
            $user->subscription_status,
            (bool) $user->assessment_voice_trial_used,
            $plan->id,
            $target,
            $plan->cefr_level,
        );

        return response()->json([
            'plan_id' => $plan->id,
            'cefr_level' => $plan->cefr_level,
            'voice_trial' => $voice,
        ]);
    }

    public function voiceTrial(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $target = $data['target_language'] ?? $user->target_language;
        if (! is_string($target) || $target === '') {
            RequestChecks::fail(['body', 'target_language']);
        }
        $userLang = UserLanguage::query()->where('user_id', $user->id)->where('target_language', $target)->first();
        if (! $userLang) {
            throw new ApiException('Study plan not found.', 404);
        }
        $plan = StudyPlan::query()->where('user_language_id', $userLang->id)->where('is_active', true)->orderByDesc('created_at')->first();
        if (! $plan) {
            throw new ApiException('Study plan not found.', 404);
        }

        return response()->json([
            'plan_id' => $plan->id,
            'cefr_level' => $plan->cefr_level,
            'voice_trial' => $this->voiceTrials->create(
                $user->id,
                $user->subscription_status,
                (bool) $user->assessment_voice_trial_used,
                $plan->id,
                $plan->target_language,
                $plan->cefr_level,
            ),
        ]);
    }

    public function levelQuestions(Request $request, int $planId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->ownedPlan($user->id, $planId);
        if (! $this->completion->isLevelTestEligible($plan, $this->completion->hasPendingLessons($plan))) {
            throw new ApiException('Level test unlocks at the final day of the study plan once every pending lesson is completed.', 403);
        }
        $grammar = [];
        $vocab = [];
        foreach (LearningCatalog::units($plan->cefr_level, $plan->target_language) as $unit) {
            $grammar = [...$grammar, ...($unit['grammar_points'] ?? [])];
            $vocab = [...$vocab, ...($unit['vocabulary_set_ids'] ?? [])];
        }
        try {
            $questions = $this->assessment->generateLevelTest(
                $plan->cefr_level,
                array_values(array_unique($grammar)),
                array_values(array_unique($vocab)),
                $plan->target_language,
            );
        } catch (LlmTimeoutException) {
            throw new ApiException('LLM timed out generating level test.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        }

        return response()->json(['plan_id' => $planId, 'cefr_level' => $plan->cefr_level, 'questions' => $questions]);
    }

    public function levelSubmit(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $planId = RequestChecks::strictInt($data, 'plan_id', 1, 2147483647);
        if (! isset($data['answers']) || ! is_array($data['answers'])) {
            RequestChecks::fail(['body', 'answers']);
        }
        $plan = $this->ownedPlan($user->id, $planId);
        if (! $this->completion->isLevelTestEligible($plan, $this->completion->hasPendingLessons($plan))) {
            throw new ApiException('Level test unlocks at the final day of the study plan once every pending lesson is completed.', 403);
        }
        $result = $this->assessment->evaluateAdaptiveQuiz($data['answers']);
        $score = (float) $result['score'];
        $recommendation = $score >= 0.75 ? 'advance' : ($score >= 0.55 ? 'extend' : 'repeat');
        $plan->completion_test_taken = true;
        $plan->completion_test_score = $score;
        $plan->completion_test_recommendation = $recommendation;
        $plan->save();
        $this->rewards->rewardPlanCompletion($user->id, $plan->id);

        return response()->json([
            'score' => $score,
            'recommendation' => $recommendation,
            'next_level' => $recommendation === 'advance' ? $this->completion->nextCefrLevel($plan->cefr_level) : null,
        ]);
    }

    public function levelResult(Request $request, int $planId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->ownedPlan($user->id, $planId, false);
        if (! $plan || ! $plan->completion_test_taken) {
            throw new ApiException('Level test result not found.', 404);
        }

        return response()->json([
            'score' => (float) ($plan->completion_test_score ?? 0),
            'recommendation' => $plan->completion_test_recommendation ?: 'repeat',
            'next_level' => $plan->completion_test_recommendation === 'advance' ? $this->completion->nextCefrLevel($plan->cefr_level) : null,
        ]);
    }

    private function ownedPlan(int $userId, int $planId, bool $required = true): ?StudyPlan
    {
        $plan = StudyPlan::query()
            ->select('study_plans.*')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('study_plans.id', $planId)
            ->where('user_languages.user_id', $userId)
            ->first();
        if (! $plan && $required) {
            throw new ApiException('Study plan not found.', 404);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $quiz
     * @return array<string, mixed>
     */
    private function stripAnswers(array $quiz): array
    {
        $questions = [];
        foreach ($quiz['questions'] ?? [] as $question) {
            if (! is_array($question)) {
                continue;
            }
            unset($question['correct_answer'], $question['correct']);
            $questions[] = $question;
        }
        $quiz['questions'] = $questions;

        return $quiz;
    }
}
