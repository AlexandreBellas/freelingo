<?php

namespace App\Services\Practice;

use App\Models\Conversation;
use App\Models\Memory;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Learning\LessonVoicePractice;
use App\Services\Llm\LlmAdapter;
use App\Services\MemoryService;
use App\Services\Security\Jwt;
use App\Services\UserLanguageService;
use Illuminate\Support\Carbon;

final class ConversationHandshake
{
    public function __construct(
        private readonly Access $access,
        private readonly UserLanguageService $languages,
        private readonly SpeechGateway $speech,
        private readonly LlmAdapter $llm,
        private readonly LessonVoicePractice $lessons,
        private readonly MemoryService $memories,
    ) {
    }

    /**
     * @param  array<string, mixed>  $auth
     * @return array{events: list<array<string, mixed>>, close: ?int, pipeline: ?ConversationPipeline}
     */
    public function open(array $auth): array
    {
        try {
            $payload = Jwt::decode((string) ($auth['token'] ?? ''));
            $userId = (int) $payload['sub'];
        } catch (\Throwable) {
            return ['events' => [['type' => 'error', 'code' => 'auth_failed', 'message' => 'Authentication failed']], 'close' => 1008, 'pipeline' => null];
        }
        $user = User::query()->find($userId);
        if (! $user || ! $user->is_active) {
            return ['events' => [], 'close' => 1008, 'pipeline' => null];
        }
        try {
            $this->access->assertNotMaintenance($user);
        } catch (\Throwable) {
            return ['events' => [['type' => 'error', 'code' => 'maintenance_mode', 'message' => 'Service temporarily unavailable — maintenance mode is active.']], 'close' => 1013, 'pipeline' => null];
        }
        [$allowed, $freemium] = $this->access->voiceAccess($user, $auth['voice_trial_token'] ?? null);
        if (! $allowed) {
            return ['events' => [['type' => 'error', 'code' => 'subscription_required', 'message' => 'Voice conversation is not available on your current plan.']], 'close' => 1008, 'pipeline' => null];
        }
        if ($this->speech->tts === null || $this->speech->stt === null) {
            return ['events' => [['type' => 'error', 'code' => 'services_disabled', 'message' => 'TTS and STT must be enabled for conversation mode.']], 'close' => 1011, 'pipeline' => null];
        }
        $voice = (string) ($auth['voice'] ?? '');
        if ($this->speech->ttsProvider === 'openai') {
            if (! in_array($voice, SpeechGateway::OPENAI_VOICES, true)) {
                $voice = '';
            }
        } else {
            $voice = '';
        }
        $lessonContext = '';
        $plan = null;
        if (array_key_exists('lesson_id', $auth)) {
            $lessonId = $auth['lesson_id'];
            $loaded = is_int($lessonId) ? $this->lessons->source($user->id, $lessonId) : null;
            if ($loaded === null) {
                return ['events' => [['type' => 'error', 'code' => 'lesson_practice_unavailable']], 'close' => 1008, 'pipeline' => null];
            }
            [$lesson, $plan] = $loaded;
            $lessonContext = $this->lessons->context($user->id, $lesson->id) ?? '';
        }
        $requested = $auth['target_language'] ?? null;
        if (! $plan && is_string($requested) && $requested !== '') {
            $language = UserLanguage::query()->where('user_id', $user->id)->where('target_language', $requested)->first();
            if ($language) {
                $plan = StudyPlan::query()->where('user_language_id', $language->id)->where('is_active', true)->first();
            }
        }
        if (! $plan) {
            $plan = $this->access->activePlanOptional($user);
        }
        $active = $this->languages->active($user->id);
        $target = $plan->target_language ?? (is_string($requested) && $requested !== '' ? $requested : ($active->target_language ?? 'en-GB'));
        $level = $plan->cefr_level ?? 'A2';
        $now = Carbon::now('UTC');
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'title' => $lessonContext !== ''
                ? Languages::lessonPracticeTitle((string) $user->native_language, 'Lesson')
                : Languages::voiceSessionTitle((string) $user->native_language, $now),
            'source' => 'voice',
            'study_plan_id' => $plan?->id,
            'target_language' => $target,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        [$maxDuration] = $this->access->checkConversationQuotas($user, (int) $user->conversation_max_duration);
        $memories = Memory::query()->where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->get()->all();
        $pipeline = new ConversationPipeline(
            llm: $this->llm,
            tts: $this->speech->tts,
            stt: $this->speech->stt,
            cefrLevel: $level,
            nativeLanguage: (string) $user->native_language,
            targetLanguage: $target,
            studentName: $user->display_name ?: $user->username,
            maxDuration: $maxDuration,
            inactivityTimeout: (int) $user->conversation_inactivity_timeout,
            initialContext: is_array($auth['context'] ?? null) ? $auth['context'] : null,
            userId: $user->id,
            conversationId: $conversation->id,
            bio: $user->bio,
            learningGoals: $user->learning_goals,
            memories: $memories,
            voice: $voice,
            studyPlanId: $plan?->id,
            lessonPracticeContext: $lessonContext,
            access: $this->access,
            memoryStore: $this->memories,
        );
        $pipeline->freemiumVoice = $freemium;

        return [
            'events' => [['type' => 'ready', 'conversation_id' => $conversation->id, 'max_duration' => $maxDuration]],
            'close' => null,
            'pipeline' => $pipeline,
        ];
    }
}
