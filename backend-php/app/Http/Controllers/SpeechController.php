<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\Conversation;
use App\Models\StudyPlan;
use App\Services\Practice\ChatMarkdown;
use App\Services\Practice\Languages;
use App\Services\Practice\Prompts;
use App\Services\Practice\SpeechGateway;
use App\Services\Practice\TourAudio;
use App\Services\Speech\OpenAiTtsService;
use App\Services\Speech\SpeechTimeout;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpeechController extends Controller
{
    use ResolvesApiUser;

    private const PREVIEW = null;

    public function tts(Request $request, SpeechGateway $speech): Response
    {
        $user = $this->apiUser($request);
        if ($speech->tts === null) {
            throw new ApiException('TTS service is not enabled', 503);
        }
        $body = $request->json()->all();
        $text = $body['text'] ?? null;
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 5000) {
            throw new ApiException('Text is required', 422);
        }
        $conversationId = $this->contextId($body['conversation_id'] ?? null);
        $studyPlanId = $this->contextId($body['study_plan_id'] ?? null);
        if ($conversationId !== null && $studyPlanId !== null) {
            throw new ApiException('Provide either study_plan_id or conversation_id, not both', 422);
        }
        $language = null;
        if ($conversationId !== null) {
            $conversation = Conversation::query()->where('id', $conversationId)->where('user_id', $user->id)->first();
            if (! $conversation) {
                throw new ApiException('Conversation not found', 404);
            }
            $studyPlanId = $conversation->study_plan_id;
            $language = $conversation->target_language;
        }
        if ($studyPlanId !== null) {
            $plan = StudyPlan::query()->where('id', $studyPlanId)->where('user_id', $user->id)->first();
            if (! $plan) {
                throw new ApiException('Study plan not found', 404);
            }
            $language = $plan->target_language;
        }
        if ($conversationId !== null) {
            $text = ChatMarkdown::toSpeech($text, is_string($language) ? $language : null);
            if (trim($text) === '') {
                throw new ApiException('No speakable text', 422);
            }
        }
        $voice = $speech->ttsProvider === 'local' ? null : ($body['voice'] ?? null);
        $started = microtime(true);
        $audio = $speech->tts->synthesize($text, is_string($voice) ? $voice : null, $language);
        $ms = (microtime(true) - $started) * 1000;
        $trace = $request->header('X-TTS-Trace-ID') ?: 'tts-'.substr(bin2hex(random_bytes(8)), 0, 12);

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'X-TTS-Trace-ID' => $trace,
            'X-TTS-Backend-Synth-Ms' => number_format($ms, 1, '.', ''),
            'X-TTS-Backend-Total-Ms' => number_format($ms, 1, '.', ''),
        ]);
    }

    public function tour(Request $request, string $locale, string $step, SpeechGateway $speech): BinaryFileResponse
    {
        $this->apiUser($request);
        if (! in_array($locale, SpeechGateway::UI_LOCALES, true) || ! in_array($step, ['step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'step7'], true)) {
            throw new ApiException('Unknown tour narration', 404);
        }
        $voice = $request->json('voice');
        if ($speech->ttsProvider === 'openai' && is_string($voice) && $voice !== '' && ! in_array($voice, SpeechGateway::OPENAI_VOICES, true)) {
            throw new ApiException('Invalid voice name', 400);
        }
        $text = $request->json('text');
        if (! is_string($text) || $text === '' || mb_strlen($text) > 5000) {
            throw new ApiException('Tour narration text is required', 422);
        }
        if ($speech->tts === null) {
            throw new ApiException('TTS service is not enabled', 503);
        }
        try {
            $path = TourAudio::path($speech->tts, $locale, $text, is_string($voice) ? $voice : null);
        } catch (SpeechTimeout) {
            throw new ApiException('Tour narration timed out', 504);
        } catch (\Throwable) {
            throw new ApiException('Tour narration is unavailable', 503);
        }

        return $this->audioFile($path);
    }

    public function preview(Request $request, string $voice, SpeechGateway $speech): BinaryFileResponse
    {
        $this->apiUser($request);
        if ($speech->ttsProvider !== 'openai') {
            throw new ApiException('Voice preview is only available with OpenAI TTS', 404);
        }
        if (! in_array($voice, SpeechGateway::OPENAI_VOICES, true)) {
            throw new ApiException('Invalid voice name', 400);
        }
        if (! $speech->tts instanceof OpenAiTtsService) {
            throw new ApiException('TTS service is not enabled', 503);
        }
        $text = "Hello! I'm ".Prompts::tutorDisplayName().", your tutor. This is how I sound — warm, clear, and ready to help you practise every day. Let's get started!";
        $directory = Settings::string('tts_previews_path', storage_path('app/tts_previews'));
        $name = $voice.'.mp3';
        if ($speech->tts->getInstructions()) {
            $name = $voice.'-'.$speech->tts->getCacheKey($text, $voice).'.mp3';
        }
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = rtrim($directory, '/').'/'.$name;
        if (! is_file($path)) {
            $audio = $speech->tts->synthesize($text, $voice);
            $temporary = $path.'.tmp';
            file_put_contents($temporary, $audio);
            rename($temporary, $path);
        }

        return $this->audioFile($path);
    }

    public function stt(Request $request, SpeechGateway $speech): \Illuminate\Http\JsonResponse
    {
        $user = $this->apiUser($request);
        $planId = $this->requiredPlanId($request->input('study_plan_id'));
        if ($speech->stt === null) {
            throw new ApiException('STT service is not enabled', 503);
        }
        $plan = StudyPlan::query()->where('id', $planId)->where('user_id', $user->id)->first();
        if (! $plan) {
            throw new ApiException('Study plan not found', 404);
        }
        $file = $request->file('audio');
        if ($file === null) {
            throw new ApiException('Audio file is required', 422);
        }
        $bytes = file_get_contents($file->getRealPath() ?: '') ?: '';
        if (strlen($bytes) > 50 * 1024 * 1024) {
            throw new ApiException('Audio file too large (max 50 MB)', 413);
        }
        $text = $speech->stt->transcribe(
            $bytes,
            $file->getClientOriginalName() ?: 'audio.webm',
            $file->getClientMimeType() ?: ($file->getMimeType() ?: 'audio/webm'),
            Languages::iso639($plan->target_language),
        );

        return response()->json(['text' => $text]);
    }

    private function audioFile(string $path): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ], false, null, false, false);
        // Symfony appends ", private" unless public/private is already present.
        // The tour and preview contracts require the exact directive "no-store".
        $property = new \ReflectionProperty($response->headers, 'headers');
        $headers = $property->getValue($response->headers);
        $headers['cache-control'] = ['no-store'];
        $property->setValue($response->headers, $headers);

        return $response;
    }

    /**
     * Strict positive 32-bit id. Null means the field was omitted.
     */
    private function contextId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $id = $this->parseBoundedId($value);
        if ($id === null) {
            throw new ApiException('Invalid context id', 422);
        }

        return $id;
    }

    private function requiredPlanId(mixed $value): int
    {
        $id = $this->parseBoundedId($value);
        if ($id === null) {
            throw new ApiException('study_plan_id is required', 422);
        }

        return $id;
    }

    private function parseBoundedId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 2147483647 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/^[0-9]+$/', $value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        if ($normalized === '' || strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '2147483647') > 0)) {
            return null;
        }

        return (int) $normalized;
    }
}
