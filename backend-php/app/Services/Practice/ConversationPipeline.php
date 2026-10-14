<?php

namespace App\Services\Practice;

use App\Models\ChatHistory;
use App\Models\Conversation;
use App\Models\LlmUsage;
use App\Models\Memory;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Learning\LessonVoicePractice;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmStream;
use App\Services\Llm\LlmStreamReset;
use App\Services\Llm\LlmTimeoutError;
use App\Services\Llm\LlmTool;
use App\Services\Llm\LlmToolCall;
use App\Services\Llm\LlmToolResult;
use App\Services\Llm\LlmToolResultEvent;
use App\Services\Llm\LlmUnavailableError;
use App\Services\MemoryService;
use App\Services\Security\Jwt;
use App\Services\UserLanguageService;
use Illuminate\Support\Carbon;

final class ConversationPipeline
{
    public string $systemPrompt;

    /** @var list<array{role: string, content: string}> */
    public array $history = [];

    public bool $recorded = false;

    public bool $freemiumVoice = false;

    public bool $memoryToolsAvailable = true;

    public string $voice;

    public int $turnId = 0;

    public bool $cancelled = false;

    /** @var array<string, mixed> */
    private array $promptArgs;

    private string $memoryContext;

    public function __construct(
        public readonly LlmAdapter $llm,
        public readonly object $tts,
        public readonly object $stt,
        public readonly string $cefrLevel = 'B1',
        public readonly string $nativeLanguage = 'es',
        public readonly string $targetLanguage = 'en-GB',
        public readonly string $studentName = 'Student',
        public readonly int $maxDuration = 1800,
        public readonly int $inactivityTimeout = 180,
        ?array $initialContext = null,
        public readonly ?int $userId = null,
        public readonly ?int $conversationId = null,
        public readonly ?string $bio = null,
        public readonly mixed $learningGoals = null,
        public readonly array $memories = [],
        string $voice = '',
        public readonly ?int $studyPlanId = null,
        public readonly string $lessonPracticeContext = '',
        public readonly ?Access $access = null,
        public readonly ?MemoryService $memoryStore = null,
    ) {
        $this->voice = $voice;
        $parts = [];
        $goals = $learningGoals;
        if (is_string($goals)) {
            $decoded = json_decode($goals, true);
            $goals = is_array($decoded) ? $decoded : null;
        }
        if (is_array($goals) && array_is_list($goals) && $goals !== []) {
            $parts[] = 'Learning goals: '.implode(', ', $goals);
        }
        if (is_string($bio) && trim($bio) !== '') {
            $parts[] = 'About the student: '.trim($bio);
        }
        $userContext = $parts === [] ? '' : "\nStudent context:\n".implode("\n", array_map(fn ($part) => "- {$part}", $parts))."\n";
        $this->memoryContext = ChatService::memoryContext($this->memoryModels($memories));
        $this->promptArgs = [
            'studentName' => $studentName,
            'cefrLevel' => $cefrLevel,
            'nativeLanguage' => Languages::nativeName($nativeLanguage),
            'targetLanguageName' => Languages::name($targetLanguage),
            'userContext' => $userContext,
            'memoryContext' => $this->memoryContext,
            'languagePromptOverlay' => Prompts::languageOverlay($targetLanguage),
            'lessonPracticeContext' => $lessonPracticeContext,
        ];
        $this->systemPrompt = Prompts::buildConversationSystemPrompt(
            ...$this->promptArgs,
            memoryToolsEnabled: $this->memoryToolsAvailable,
        );
        if ($initialContext) {
            foreach ($initialContext as $message) {
                if (! is_array($message)) {
                    continue;
                }
                if (! in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                    continue;
                }
                if (! is_string($message['content'] ?? null) || trim($message['content']) === '') {
                    continue;
                }
                $this->history[] = ['role' => $message['role'], 'content' => $message['content']];
            }
            $this->history = array_slice($this->history, -10);
        }
    }

    /**
     * @param  list<mixed>  $memories
     * @return list<Memory>
     */
    private function memoryModels(array $memories): array
    {
        $models = [];
        foreach ($memories as $memory) {
            if ($memory instanceof Memory) {
                $models[] = $memory;

                continue;
            }
            if (is_string($memory) && $memory !== '') {
                $row = new Memory;
                $row->content = $memory;
                $models[] = $row;
            } elseif (is_array($memory) && isset($memory['content'])) {
                $row = new Memory;
                $row->content = (string) $memory['content'];
                $models[] = $row;
            }
        }

        return $models;
    }

    /**
     * @return list<string>
     */
    public static function splitTtsSentences(string $text): array
    {
        $chunks = [];
        $start = 0;
        $length = mb_strlen($text);
        for ($index = 0; $index < $length; $index++) {
            if (mb_substr($text, $index, 1) !== '.') {
                continue;
            }
            $sentence = trim(mb_substr($text, $start, $index - $start + 1));
            if ($sentence !== '') {
                $chunks[] = $sentence;
            }
            $start = $index + 1;
        }
        $tail = trim(mb_substr($text, $start));
        if ($tail !== '') {
            $chunks[] = $tail;
        }
        if ($chunks === [] && trim($text) !== '') {
            return [trim($text)];
        }

        return $chunks;
    }

    public static function cleanSentence(string $raw): string
    {
        return trim($raw);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function greet(): array
    {
        $turn = ++$this->turnId;
        $trigger = ['role' => 'user', 'content' => '[Session started. Greet the student warmly and naturally — one or two sentences max — and invite them to speak.]'];
        $prompt = Prompts::buildConversationSystemPrompt(...$this->promptArgs, memoryToolsEnabled: false);
        $messages = [['role' => 'system', 'content' => $prompt], ...array_slice($this->history, -20), $trigger];
        try {
            $raw = $this->llm->chat($messages, true);
            $text = $this->collectText($raw);
            $clean = self::cleanSentence($text);
            if ($clean === '') {
                return [];
            }
            $events = [['type' => 'status', 'value' => 'thinking', 'turn_id' => $turn]];
            $events = [...$events, ...$this->speak($clean, $turn, 'assistant')];
            $this->history[] = ['role' => 'assistant', 'content' => $clean];
            $this->saveMessage('assistant', $clean, null);
            $events[] = ['type' => 'turn_complete', 'turn_id' => $turn];

            return $events;
        } catch (LlmTimeoutError $error) {
            return [['type' => 'error', 'code' => 'llm_timeout', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        } catch (LlmUnavailableError $error) {
            return [['type' => 'error', 'code' => 'llm_unavailable', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        } catch (\Throwable $error) {
            return [['type' => 'error', 'code' => 'llm_error', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function processAudio(string $audio, string $filename = 'audio.webm', string $mime = 'audio/webm'): array
    {
        $this->cancelled = false;
        $turn = ++$this->turnId;
        $events = [['type' => 'status', 'value' => 'transcribing', 'turn_id' => $turn]];
        try {
            $text = $this->stt->transcribe($audio, $filename, $mime, Languages::iso639($this->targetLanguage));
        } catch (\Throwable $error) {
            return [...$events, ['type' => 'error', 'code' => 'stt_error', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        }
        if (trim($text) === '') {
            return [...$events, ['type' => 'turn_complete', 'turn_id' => $turn]];
        }

        return [...$events, ...$this->processTranscript($text, $turn)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function processTranscript(string $text, ?int $turn = null): array
    {
        $turn ??= ++$this->turnId;
        $this->cancelled = false;
        $text = trim($text);
        if ($text === '') {
            return [['type' => 'turn_complete', 'turn_id' => $turn]];
        }
        $this->history[] = ['role' => 'user', 'content' => $text];
        $userId = $this->saveMessage('user', $text, null);
        $events = [
            ['type' => 'transcript', 'role' => 'user', 'text' => $text, 'turn_id' => $turn],
            ['type' => 'status', 'value' => 'thinking', 'turn_id' => $turn],
        ];
        $native = $this->promptArgs['nativeLanguage'];
        $tool = new LlmTool('save_user_memory', "Save one durable fact in {$native}.", [
            'type' => 'object',
            'properties' => ['content' => ['type' => 'string']],
            'required' => ['content'],
        ]);
        $messages = [['role' => 'system', 'content' => $this->systemPrompt], ...array_slice($this->history, -20)];
        try {
            $raw = $this->memoryToolsAvailable
                ? $this->llm->chat($messages, true, [$tool], fn (LlmToolCall $call) => $this->executeMemory($call))
                : $this->llm->chat($messages, true);
        } catch (LlmTimeoutError $error) {
            return [...$events, ['type' => 'error', 'code' => 'llm_timeout', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        } catch (LlmUnavailableError $error) {
            return [...$events, ['type' => 'error', 'code' => 'llm_unavailable', 'message' => $error->getMessage(), 'turn_id' => $turn]];
        }
        if ($this->cancelled) {
            $this->popTrailingUser();

            return $events;
        }
        $spoken = '';
        $memorySent = false;
        $stream = $raw instanceof LlmStream ? $raw : new LlmStream([(string) $raw]);
        foreach ($stream as $chunk) {
            if ($chunk instanceof LlmToolResultEvent) {
                if (($chunk->result->content['saved'] ?? false) === true && ! $memorySent) {
                    $memorySent = true;
                    $events[] = ['type' => 'memory_updated', 'turn_id' => $turn];
                }

                continue;
            }
            if ($chunk instanceof LlmStreamReset) {
                $spoken = '';
                $events[] = ['type' => 'response_reset', 'turn_id' => $turn];

                continue;
            }
            if (is_string($chunk)) {
                $spoken .= $chunk;
            }
        }
        $clean = self::cleanSentence($spoken);
        if ($clean === '') {
            $events[] = ['type' => 'turn_complete', 'turn_id' => $turn];

            return $events;
        }
        $events = [...$events, ...$this->speak($clean, $turn, 'assistant')];
        $this->history[] = ['role' => 'assistant', 'content' => $clean];
        $this->saveMessage('assistant', $clean, $userId);
        if ($stream->promptTokens !== null || $stream->completionTokens !== null) {
            $this->saveUsage($stream);
        }
        $events[] = ['type' => 'turn_complete', 'turn_id' => $turn];

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function interrupt(string $text): array
    {
        $this->cancelCurrent();

        return $this->processTranscript($text);
    }

    public function cancelCurrent(): void
    {
        $this->cancelled = true;
        $this->popTrailingUser();
    }

    public function cleanup(int $seconds): void
    {
        if ($this->recorded || $this->userId === null || $this->access === null) {
            return;
        }
        $this->recorded = true;
        $this->access->recordSessionSeconds($this->userId, $seconds);
    }

    private function popTrailingUser(): void
    {
        $last = $this->history[array_key_last($this->history)] ?? null;
        if ($last && $last['role'] === 'user') {
            array_pop($this->history);
        }
    }

    private function executeMemory(LlmToolCall $call): LlmToolResult
    {
        if ($call->name !== 'save_user_memory' || $this->userId === null || $this->memoryStore === null) {
            return new LlmToolResult($call, ['saved' => false], true);
        }
        $saved = $this->memoryStore->save($this->userId, [(string) ($call->arguments['content'] ?? '')], 'voice', $this->studyPlanId);

        return new LlmToolResult($call, ['saved' => $saved > 0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function speak(string $text, int $turn, string $role): array
    {
        $events = [];
        $sent = 0;
        $transcript = ['type' => 'transcript', 'role' => $role, 'text' => $text, 'turn_id' => $turn];
        foreach (self::splitTtsSentences($text) as $sentence) {
            try {
                $audio = $this->tts->synthesize($sentence, $this->voice !== '' ? $this->voice : null, $this->targetLanguage);
                if ($audio === '') {
                    throw new \RuntimeException('empty audio');
                }
            } catch (\Throwable) {
                continue;
            }
            $events[] = ['type' => 'audio', 'bytes' => $audio, 'turn_id' => $turn];
            $sent++;
            if ($sent === 1) {
                $events[] = $transcript;
            }
        }
        if ($sent === 0) {
            $events[] = $transcript;
        }

        return $events;
    }

    private function collectText(string|LlmStream $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        $text = '';
        foreach ($raw as $chunk) {
            if (is_string($chunk)) {
                $text .= $chunk;
            }
        }

        return $text;
    }

    private function saveMessage(string $role, string $content, ?int $replyTo): ?int
    {
        if ($this->userId === null || $this->conversationId === null || trim($content) === '') {
            return null;
        }
        $row = ChatHistory::query()->create([
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'role' => $role,
            'content' => $content,
            'created_at' => Carbon::now('UTC'),
            'study_plan_id' => $this->studyPlanId,
            'target_language' => $this->targetLanguage,
            'modality' => 'voice',
            'reply_to_id' => $replyTo,
        ]);

        return $row->id;
    }

    private function saveUsage(LlmStream $stream): void
    {
        if ($this->userId === null) {
            return;
        }
        LlmUsage::query()->create([
            'user_id' => $this->userId,
            'source' => 'voice',
            'prompt_tokens' => $stream->promptTokens,
            'completion_tokens' => $stream->completionTokens,
            'total_tokens' => $stream->totalTokens,
            'created_at' => Carbon::now('UTC'),
            'study_plan_id' => $this->studyPlanId,
        ]);
    }
}

