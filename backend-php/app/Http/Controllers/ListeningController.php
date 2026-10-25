<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\ListeningExercise;
use App\Services\Learning\ExerciseGeneration;
use App\Services\Practice\Access;
use App\Services\Practice\ListeningService;
use App\Services\Practice\ReadingService;
use App\Services\Practice\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListeningController extends Controller
{
    use ResolvesApiUser;

    public function next(Request $request, Access $access, ListeningService $listening, ExerciseGeneration $generation): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'listening', true);
        $plan = $access->activePlan($user);
        $exercise = $listening->available($plan->cefr_level, $plan->target_language, $user->id);
        $context = $this->context($plan);
        if ($exercise) {
            return response()->json($this->available($context, ListeningService::exerciseOut($exercise)));
        }
        $state = $generation->state("listening:generating:{$plan->cefr_level}:{$plan->target_language}");
        $exercise = $listening->available($plan->cefr_level, $plan->target_language, $user->id);
        if ($exercise) {
            return response()->json($this->available($context, ListeningService::exerciseOut($exercise)));
        }

        return response()->json(['available' => false, 'exercise' => null, 'context' => $context, ...$state]);
    }

    public function generate(Request $request, Access $access, ListeningService $listening): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'listening');
        $plan = $access->activePlan($user);

        return response()->json($listening->startGeneration($plan, (string) $request->query('voice', '')), 202);
    }

    public function audio(Request $request, int $exerciseId, Access $access, ListeningService $listening): BinaryFileResponse
    {
        $this->apiUser($request);

        return response()->file($listening->audioPath($exerciseId), [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function started(Request $request, Access $access): Response
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'listening', true);
        $plan = $access->activePlan($user);
        $body = $request->json()->all();
        $header = $request->header('X-Exercise-Attempt');
        if (! is_string($header) || ! preg_match('/^[0-9a-fA-F-]{36}$/', $header)) {
            throw new ApiException('Invalid attempt id', 422);
        }
        $access->assertStudyContext(
            $plan,
            $body['context']['study_plan_id'] ?? null,
            $body['context']['target_language'] ?? null,
            $body['context']['level'] ?? null,
        );
        $exercise = ListeningExercise::query()->find($body['exercise_id'] ?? 0);
        if (! $exercise) {
            throw new ApiException('exercise_not_found', 404);
        }
        if ($exercise->target_language !== $plan->target_language || (! ($body['replay'] ?? false) && $exercise->level !== $plan->cefr_level)) {
            throw new ApiException('study_context_changed', 409);
        }

        return response()->noContent();
    }

    public function attempt(Request $request, Access $access, ListeningService $listening): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'listening');
        $plan = $access->activePlan($user);
        $body = $request->json()->all();
        $answers = $body['answers'] ?? [];
        if (! is_array($answers)) {
            throw new ApiException('Invalid answers', 422);
        }
        $access->assertStudyContext(
            $plan,
            $body['context']['study_plan_id'] ?? null,
            $body['context']['target_language'] ?? null,
            $body['context']['level'] ?? null,
        );
        $replay = (bool) ($body['replay'] ?? false);
        $exercise = ListeningExercise::query()->find($body['exercise_id'] ?? 0);
        if ($exercise && ($exercise->target_language !== $plan->target_language || (! $replay && $exercise->level !== $plan->cefr_level))) {
            throw new ApiException('study_context_changed', 409);
        }
        try {
            [$attempt, $saved] = $listening->submit((int) ($body['exercise_id'] ?? 0), $user, $answers, $replay, $plan->id);
        } catch (\InvalidArgumentException $error) {
            $detail = $error->getMessage();
            throw new ApiException($detail, $detail === 'exercise_not_found' ? 404 : ($detail === 'already_attempted' ? 409 : 400));
        }
        $access->recordFeature($user, 'listening');

        return response()->json([
            'score' => $attempt->score,
            'xp_earned' => $attempt->xp_earned,
            'correct_answers' => ReadingService::correctAnswers($saved),
            'text' => $saved->text,
        ]);
    }

    public function history(Request $request, Access $access, ListeningService $listening): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'listening', true);
        $plan = $access->activePlan($user);
        $skip = max(0, (int) $request->query('skip', 0));
        $limit = min(50, (int) $request->query('limit', 10));
        if ($limit < 1) {
            $limit = 10;
        }
        [$rows, $total] = $listening->history($user->id, $plan->target_language, $skip, $limit);
        $items = [];
        foreach ($rows as [$attempt, $exercise]) {
            $items[] = [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'xp_earned' => $attempt->xp_earned,
                'completed_at' => Text::iso($attempt->completed_at),
                'exercise' => ListeningService::exerciseOut($exercise),
                'text' => $exercise->text,
                'answers' => $attempt->answers,
            ];
        }

        return response()->json([
            'context' => [
                'study_plan_id' => $plan->id,
                'target_language' => $plan->target_language,
                'level' => $plan->cefr_level,
            ],
            'items' => $items,
            'total' => $total,
            'skip' => $skip,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(\App\Models\StudyPlan $plan): array
    {
        return [
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'level' => $plan->cefr_level,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $exercise
     * @return array<string, mixed>
     */
    private function available(array $context, array $exercise): array
    {
        return [
            'available' => true,
            'exercise' => $exercise,
            'context' => $context,
            'generation_status' => 'idle',
            'generation_error' => null,
            'generation_deadline' => null,
            'generation_remaining_seconds' => null,
        ];
    }
}
