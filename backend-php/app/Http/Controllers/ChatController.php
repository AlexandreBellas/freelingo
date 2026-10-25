<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Practice\Access;
use App\Services\Practice\ChatService;
use App\Services\Practice\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChatController extends Controller
{
    use ResolvesApiUser;

    public function conversations(Request $request, Access $access, ChatService $chat): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'chat', true);
        $rows = [];
        foreach ($chat->listConversations($user) as $conversation) {
            $rows[] = $this->conversation($conversation);
        }

        return response()->json($rows);
    }

    public function createConversation(Request $request, Access $access, ChatService $chat): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'chat', true);
        $title = $request->json('title');

        return response()->json($this->conversation($chat->createConversation($user, is_string($title) ? $title : null)));
    }

    public function deleteConversation(Request $request, int $conversationId, Access $access, ChatService $chat): Response
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'chat', true);
        $chat->deleteConversation($user, $conversationId);

        return response()->noContent();
    }

    public function messages(Request $request, int $conversationId, Access $access, ChatService $chat): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'chat', true);

        return response()->json(['messages' => $chat->messages($user, $conversationId)]);
    }

    public function chat(Request $request, Access $access, ChatService $chat): Response
    {
        $user = $this->apiUser($request);
        $message = $request->json('message');
        if (! is_string($message) || trim($message) === '') {
            throw new ApiException('Message is required', 422);
        }
        $conversationId = $request->json('conversation_id');
        $body = $chat->stream($user, $message, is_numeric($conversationId) ? (int) $conversationId : null);

        return response($body, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    public function history(Request $request, Access $access, ChatService $chat): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $access->assertFeature($user, 'chat', true);

        return response()->json(['messages' => $chat->history($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(\App\Models\Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'source' => $conversation->source,
            'study_plan_id' => $conversation->study_plan_id,
            'target_language' => $conversation->target_language,
            'created_at' => Text::iso($conversation->created_at),
            'updated_at' => Text::iso($conversation->updated_at),
        ];
    }
}
