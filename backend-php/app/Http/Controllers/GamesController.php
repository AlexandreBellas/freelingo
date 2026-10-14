<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Practice\Access;
use App\Services\Practice\GamesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamesController extends Controller
{
    use ResolvesApiUser;

    public function catalog(Request $request, string $gameType, Access $access, GamesService $games): JsonResponse
    {
        $this->type($gameType);
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $plan = $access->activePlan($user);
        $skip = max(0, (int) $request->query('skip', 0));
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        return response()->json($games->catalog($user, $plan, $gameType, $skip, $limit));
    }

    public function start(Request $request, string $gameType, Access $access, GamesService $games): JsonResponse
    {
        $this->type($gameType);
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $body = $request->json()->all();
        $this->validateCreate($body);
        $plan = $access->activePlanOptional($user);
        [$session, $created] = $games->create($user, $plan, [
            'request_id' => $body['request_id'],
            'study_plan_id' => (int) $body['study_plan_id'],
            'mode' => $body['mode'],
        ], $gameType);
        if ($created) {
            $id = $session->id;
            app()->terminating(fn () => $games->generate($id));
        }

        return response()->json($games->output($session->fresh()), 202);
    }

    public function show(Request $request, string $sessionId, GamesService $games): JsonResponse
    {
        $user = $this->apiUser($request);
        $this->uuid($sessionId);
        $games->expire($user->id);

        return response()->json($games->output($games->owned($user->id, $sessionId)));
    }

    public function answer(Request $request, string $sessionId, GamesService $games): JsonResponse
    {
        $user = $this->apiUser($request);
        $this->uuid($sessionId);
        $result = $games->answer($user->id, $sessionId, $request->json()->all());

        return response()->json($games->output($result['session']));
    }

    public function abandon(Request $request, string $sessionId, GamesService $games): JsonResponse
    {
        $user = $this->apiUser($request);
        $this->uuid($sessionId);

        return response()->json($games->output($games->abandon($user->id, $sessionId)));
    }

    private function type(string $gameType): void
    {
        if (! in_array($gameType, GamesService::TYPES, true)) {
            throw new ApiException('Invalid game type', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function validateCreate(array $body): void
    {
        if (! isset($body['request_id']) || ! is_string($body['request_id']) || ! preg_match('/^[0-9a-fA-F-]{36}$/', $body['request_id'])) {
            throw new ApiException('Invalid request id', 422);
        }
        if (! isset($body['study_plan_id']) || ! is_int($body['study_plan_id']) && ! ctype_digit((string) $body['study_plan_id'])) {
            throw new ApiException('Invalid study plan', 422);
        }
        if (! in_array($body['mode'] ?? '', GamesService::MODES, true)) {
            throw new ApiException('Invalid mode', 422);
        }
    }

    private function uuid(string $value): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            throw new ApiException('Invalid session id', 422);
        }
    }
}
