<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\ReadingExercise;
use App\Services\Learning\ExerciseGeneration;
use App\Services\Practice\Access;
use App\Services\Practice\ReadingService;
use App\Services\Practice\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReadingController extends Controller
{
    use ResolvesApiUser;

    public function next(Request $request, Access $access, ReadingService $reading, ExerciseGeneration $generation): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'reading', true);
        $plan = $access->activePlan($user);
        $exercise = $reading->available($plan->cefr_level, $plan->target_language, $user->id);
        $context = $this->context($plan);
        if ($exercise) {
            return response()->json($this->available($context, ReadingService::exerciseOut($exercise)));
        }
        $state = $generation->state("reading:generating:{$plan->cefr_level}:{$plan->target_language}");
        $exercise = $reading->available($plan->cefr_level, $plan->target_language, $user->id);
        if ($exercise) {
            return response()->json($this->available($context, ReadingService::exerciseOut($exercise)));
        }

        return response()->json(['available' => false, 'exercise' => null, 'context' => $context, ...$state]);
    }

    public function generate(Request $request, Access $access, ReadingService $reading): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'reading');
        $plan = $access->activePlan($user);

        return response()->json($reading->startGeneration($plan), 202);
    }

    public function started(Request $request, Access $access): Response
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'reading', true);
        $plan = $access->activePlan($user);
        $body = $request->json()->all();
        $this->attemptHeader($request, true);
        $access->assertStudyContext(
            $plan,
            $body['context']['study_plan_id'] ?? null,
            $body['context']['target_language'] ?? null,
            $body['context']['level'] ?? null,
        );
        $exercise = ReadingExercise::query()->find($body['exercise_id'] ?? 0);
        if (! $exercise) {
            throw new ApiException('exercise_not_found', 404);
        }
        $reading = app(ReadingService::class);
        $reading->assertOwnedExercise($exercise, $plan, (bool) ($body['replay'] ?? false));

        return response()->noContent();
    }

    public function attempt(Request $request, Access $access, ReadingService $reading): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'reading');
        $plan = $access->activePlan($user);
        $body = $request->json()->all();
        $answers = $body['answers'] ?? null;
        if (! is_array($answers) || count($answers) !== 5) {
            throw new ApiException('exactly 5 answers required', 422);
        }
        $access->assertStudyContext(
            $plan,
            $body['context']['study_plan_id'] ?? null,
            $body['context']['target_language'] ?? null,
            $body['context']['level'] ?? null,
        );
        $exercise = ReadingExercise::query()->find($body['exercise_id'] ?? 0);
        $replay = (bool) ($body['replay'] ?? false);
        if ($exercise) {
            $reading->assertOwnedExercise($exercise, $plan, $replay);
        }
        try {
            [$attempt, $exercise] = $reading->submit((int) ($body['exercise_id'] ?? 0), $user, $answers, $replay, $plan->id);
        } catch (\InvalidArgumentException $error) {
            $detail = $error->getMessage();
            throw new ApiException($detail, $detail === 'exercise_not_found' ? 404 : ($detail === 'already_attempted' ? 409 : 400));
        }
        $access->recordFeature($user, 'reading');

        return response()->json([
            'score' => $attempt->score,
            'xp_earned' => $attempt->xp_earned,
            'correct_answers' => ReadingService::correctAnswers($exercise),
        ]);
    }

    public function history(Request $request, Access $access, ReadingService $reading): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'reading', true);
        $plan = $access->activePlan($user);
        $skip = max(0, (int) $request->query('skip', 0));
        $limit = min(50, max(1, (int) $request->query('limit', 10)));
        [$rows, $total] = $reading->history($user->id, $plan->target_language, $skip, $limit);
        $items = [];
        foreach ($rows as [$attempt, $exercise]) {
            $items[] = [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'xp_earned' => $attempt->xp_earned,
                'completed_at' => Text::iso($attempt->completed_at),
                'exercise' => ReadingService::exerciseOut($exercise),
                'answers' => $attempt->answers,
                'correct_answers' => ReadingService::correctAnswers($exercise),
            ];
        }

        return response()->json([
            'context' => $this->context($plan),
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

    private function attemptHeader(Request $request, bool $required): void
    {
        $header = $request->header('X-Exercise-Attempt');
        if ($required && (! is_string($header) || ! preg_match('/^[0-9a-fA-F-]{36}$/', $header))) {
            throw new ApiException('Invalid attempt id', 422);
        }
    }
}
