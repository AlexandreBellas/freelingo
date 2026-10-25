<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Practice\Access;
use App\Services\Practice\ConversationHandshake;
use App\Services\Practice\SpeechGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    use ResolvesApiUser;

    public function warmup(Request $request, Access $access, SpeechGateway $speech): JsonResponse
    {
        $user = $this->apiUser($request);
        $access->assertNotMaintenance($user);
        $token = $request->json('trial_token');
        [$allowed] = $access->voiceAccess($user, is_string($token) ? $token : null);
        if (! $allowed) {
            return response()->json(['detail' => 'subscription_required'], 402);
        }
        if ($speech->tts) {
            try {
                if (property_exists($speech->tts, 'model')) {
                    $speech->tts->health();
                } else {
                    $speech->tts->synthesize('ready');
                }
            } catch (\Throwable) {
            }
        }
        if ($speech->stt) {
            try {
                $speech->stt->transcribe(self::silenceWav(), 'warmup.wav', 'audio/wav', 'en');
            } catch (\Throwable) {
            }
        }

        return response()->json(['status' => 'ready']);
    }

    /**
     * HTTP-testable stand-in for the websocket handshake.
     *
     * @return array{events: list<array<string, mixed>>, close: ?int, pipeline: mixed}
     */
    public function open(Request $request, ConversationHandshake $handshake): array
    {
        return $handshake->open($request->json()->all());
    }

    public static function silenceWav(int $durationMs = 100, int $sampleRate = 16000): string
    {
        $samples = intdiv($sampleRate * $durationMs, 1000);
        $data = str_repeat("\0", $samples * 2);
        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            36 + strlen($data),
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            $sampleRate,
            $sampleRate * 2,
            2,
            16,
            'data',
            strlen($data),
        );

        return $header.$data;
    }
}
