<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\Memory;
use App\Services\MemoryAlreadyExists;
use App\Services\MemoryService;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemoryController extends Controller
{
    use ResolvesApiUser;

    public function __construct(private readonly MemoryService $memories) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        return response()->json([
            'memories' => array_map($this->present(...), $this->memories->list($user->id)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $content = $request->json('content');
        if (! is_string($content)) {
            throw new ApiException('Validation error', 422);
        }
        $stripped = trim($content);
        if ($stripped === '' || mb_strlen($stripped) > MemoryService::MAX_MEMORY_CHARS) {
            throw new ApiException('Validation error', 422);
        }
        try {
            $memory = $this->memories->create($user->id, $content);
        } catch (MemoryAlreadyExists) {
            throw new ApiException('memory_already_exists', 409);
        }

        return response()->json($this->present($memory), 201);
    }

    public function destroy(Request $request, int $memory_id): Response
    {
        $user = $this->apiUser($request);
        if (! $this->memories->delete($memory_id, $user->id)) {
            throw new ApiException('Memory not found', 404);
        }

        return response()->noContent();
    }

    public function clear(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        return response()->json(['deleted' => $this->memories->clear($user->id)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Memory $memory): array
    {
        return [
            'id' => $memory->id,
            'content' => $memory->content,
            'source' => $memory->source,
            'created_at' => Iso::string($memory->created_at),
        ];
    }
}
