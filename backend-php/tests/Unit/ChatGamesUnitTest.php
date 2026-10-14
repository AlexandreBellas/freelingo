<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\LlmUsage;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmStream;
use App\Services\Llm\LlmStreamReset;
use App\Services\Llm\LlmTimeoutError;
use App\Services\Llm\LlmToolCall;
use App\Services\Llm\LlmToolResult;
use App\Services\Llm\LlmToolResultEvent;
use App\Services\Llm\LlmUnavailableError;
use App\Services\Practice\Access;
use App\Services\Practice\ChatMarkdown;
use App\Services\Practice\ConversationPipeline;
use App\Services\Practice\GamesService;
use App\Services\Practice\Languages;
use App\Services\Practice\Prompts;
use App\Services\Redis\ArrayRedis;
use Tests\TestCase;

class ChatGamesUnitTest extends TestCase
{
    public function test_remaining_autolink_labels_stay_verbatim(): void
    {
        foreach ([
            'https://xn--caf-dma.example/%C3%A9',
            'file:///example/%32%30%32%36',
            'hello@xn--caf-dma.example',
        ] as $label) {
            $this->assertSame("Read {$label}.", ChatMarkdown::toSpeech("Read <{$label}>."));
        }
    }

    public function test_voice_session_titles(): void
    {
        $english = Languages::voiceSessionTitle('en');
        $this->assertStringStartsWith('Voice session — ', $english);
        $this->assertGreaterThan(strlen('Voice session — '), strlen($english));
        $this->assertStringStartsWith('Voice session — ', Languages::voiceSessionTitle('xx'));

        $spanish = Languages::voiceSessionTitle('es');
        $this->assertStringStartsWith('Sesión de voz — ', $spanish);
        $this->assertSame(2, substr_count(explode(' — ', $spanish, 2)[1], ' de '));

        $portuguese = Languages::voiceSessionTitle('pt');
        $this->assertStringStartsWith('Sessão de voz — ', $portuguese);
        $this->assertSame(2, substr_count(explode(' — ', $portuguese, 2)[1], ' de '));

        $german = Languages::voiceSessionTitle('de');
        $this->assertStringStartsWith('Sprachsitzung — ', $german);
        $germanDate = explode(' — ', $german, 2)[1];
        $this->assertStringEndsWith('.', explode(' ', $germanDate, 2)[0]);
        $this->assertStringNotContainsString(' de ', $germanDate);

        $this->assertStringStartsWith('Sesja głosowa — ', Languages::voiceSessionTitle('pl'));
        $this->assertStringNotContainsString(' de ', explode(' — ', Languages::voiceSessionTitle('pl'), 2)[1]);
        $this->assertStringStartsWith('Голосовая сессия — ', Languages::voiceSessionTitle('ru'));
        $this->assertStringNotContainsString(' de ', explode(' — ', Languages::voiceSessionTitle('ru'), 2)[1]);
        $this->assertStringStartsWith('Sesli oturum — ', Languages::voiceSessionTitle('tr'));
        $this->assertStringNotContainsString(' de ', explode(' — ', Languages::voiceSessionTitle('tr'), 2)[1]);
        $this->assertStringStartsWith('Röstsamtal — ', Languages::voiceSessionTitle('sv'));
        $this->assertStringNotContainsString(' de ', Languages::voiceSessionTitle('sv'));

        $danish = Languages::voiceSessionTitle('da');
        $this->assertStringStartsWith('Stemmesamtale — ', $danish);
        $this->assertStringEndsWith('.', explode(' ', explode(' — ', $danish, 2)[1], 2)[0]);

        $finnish = Languages::voiceSessionTitle('fi');
        $this->assertStringStartsWith('Äänikeskustelu — ', $finnish);
        $this->assertStringEndsWith('.', explode(' ', explode(' — ', $finnish, 2)[1], 2)[0]);
        $this->assertStringContainsString('kuuta ', $finnish);

        $croatian = Languages::voiceSessionTitle('hr');
        $this->assertStringStartsWith('Glasovni razgovor — ', $croatian);
        $croatianDate = explode(' — ', $croatian, 2)[1];
        $this->assertStringEndsWith('.', explode(' ', $croatianDate, 2)[0]);
        $this->assertStringEndsWith('.', $croatianDate);

        foreach (['es', 'fr', 'pt', 'de', 'it', 'pl', 'nl', 'ro', 'ru', 'tr', 'sv', 'da', 'fi', 'hr'] as $language) {
            $title = Languages::voiceSessionTitle($language);
            $this->assertNotSame('', trim($title), $language);
            $this->assertStringContainsString(' — ', $title, $language);
        }
    }

    public function test_prompt_and_pipeline_context(): void
    {
        $prompt = Prompts::buildConversationSystemPrompt('Alice', 'A2', 'es', 'English', '', '');
        $this->assertStringContainsString('Alice', $prompt);
        $this->assertStringContainsString('A2', $prompt);
        $this->assertStringContainsString('es', $prompt);
        $this->assertStringContainsString('English', $prompt);
        $this->assertStringContainsString(Prompts::tutorDisplayName(), $prompt);
        $this->assertStringContainsString('save_user_memory', $prompt);
        $this->assertStringNotContainsString('<<MEMORY>>', $prompt);
        $this->assertStringContainsString(
            'grammar, vocab',
            Prompts::buildConversationSystemPrompt('Bob', 'B2', 'fr', 'Spanish', "\nStudent context:\n- Learning goals: grammar, vocab\n", ''),
        );
        $this->assertStringContainsString(
            'Alice likes cats',
            Prompts::buildConversationSystemPrompt('Bob', 'B2', 'fr', 'Spanish', '', "\nMemories:\n- Alice likes cats\n"),
        );

        $pipeline = $this->pipeline(nativeLanguage: 'es', targetLanguage: 'en-GB');
        $this->assertStringContainsString("Student's native language: Spanish", $pipeline->systemPrompt);
        $this->assertStringNotContainsString("Student's native language: es", $pipeline->systemPrompt);
        $this->assertSame('How are you?', ConversationPipeline::cleanSentence('  How are you?  '));
        $this->assertSame('Hello world.', ConversationPipeline::cleanSentence('Hello world.'));
        $this->assertSame([], $pipeline->history);
        $this->assertSame([], $this->pipeline(initialContext: [])->history);
        $this->assertSame('', $pipeline->voice);
        $this->assertFalse($pipeline->recorded);
        $this->assertSame('nova', $this->pipeline(voice: 'nova')->voice);

        $goals = $this->pipeline(bio: 'Loves hiking', learningGoals: '["vocabulary", "pronunciation"]');
        $this->assertStringContainsString('Loves hiking', $goals->systemPrompt);
        $this->assertStringContainsString('vocabulary', $goals->systemPrompt);
        $this->assertNotSame('', $this->pipeline(learningGoals: 'not-valid-json')->systemPrompt);
        $this->assertNotSame('', $this->pipeline(learningGoals: '{"key": "value"}')->systemPrompt);
        $this->assertNotSame('', $this->pipeline(bio: '', learningGoals: '[]')->systemPrompt);
        $this->assertStringNotContainsString('About the student', $this->pipeline(bio: "   \n  ")->systemPrompt);

        $stored = $this->pipeline(userId: 42, conversationId: 99);
        $this->assertSame(42, $stored->userId);
        $this->assertSame(99, $stored->conversationId);

        $valid = [
            ['role' => 'user', 'content' => 'Hello, can you help me?'],
            ['role' => 'assistant', 'content' => 'Of course! What would you like to practice?'],
            ['role' => 'user', 'content' => 'I want to work on past tense.'],
        ];
        $copied = $this->pipeline(initialContext: $valid);
        $this->assertCount(3, $copied->history);
        $this->assertSame($valid[0], $copied->history[0]);
        $this->assertSame($valid[2], $copied->history[2]);

        $many = [];
        for ($i = 0; $i < 14; $i++) {
            $many[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "Message {$i}"];
        }
        $trimmed = $this->pipeline(initialContext: $many);
        $this->assertSame(array_map(fn (int $i) => "Message {$i}", range(4, 13)), array_column($trimmed->history, 'content'));

        $filtered = $this->pipeline(initialContext: [
            ['role' => 'system', 'content' => 'You are a bot.'],
            ['role' => 'user', 'content' => 'Hello there!'],
            ['role' => 'moderator', 'content' => 'Be nice.'],
            ['role' => 'assistant', 'content' => 'Hi!'],
            ['role' => 'user', 'content' => ''],
            ['role' => 'user', 'content' => '   '],
            ['role' => 'user', 'content' => "\n\t"],
            'not a dict',
            42,
            null,
            ['role' => 'user', 'content' => 'This is valid.', 'id' => 99, 'timestamp' => '2026-01-01'],
        ]);
        $this->assertSame(['Hello there!', 'Hi!', 'This is valid.'], array_column($filtered->history, 'content'));
        foreach ($filtered->history as $entry) {
            $this->assertSame(['role', 'content'], array_keys($entry));
        }
        $filtered->history[] = ['role' => 'assistant', 'content' => 'Cats are fascinating!'];
        $this->assertSame('assistant', $filtered->history[array_key_last($filtered->history)]['role']);

        $filtered->memoryToolsAvailable = false;
        $filtered->refreshMemoryPrompt();
        $this->assertStringNotContainsString('save_user_memory', $filtered->systemPrompt);
        $this->assertTrue($this->pipeline()->memoryToolsAvailable);
    }

    public function test_pipeline_turns_memory_and_cleanup(): void
    {
        [$user] = $this->makeUser();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Voice',
            'source' => 'voice',
            'target_language' => 'en-GB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tts = $this->speaker();
        $stt = $this->listener('Hello');
        $llm = new LlmAdapter('ollama', 'test');
        $llm->responder = fn () => new LlmStream(['Hello.']);
        $pipeline = $this->pipeline(llm: $llm, tts: $tts, stt: $stt, userId: $user->id, conversationId: $conversation->id);
        $events = $pipeline->processAudio('wav-bytes');
        $types = array_column($events, 'type');
        $this->assertContains('transcript', $types);
        $this->assertContains('turn_complete', $types);
        $this->assertContains('audio', array_column($events, 'type'));
        $userTranscript = array_values(array_filter($events, fn (array $event) => ($event['type'] ?? '') === 'transcript' && ($event['role'] ?? '') === 'user'))[0];
        $this->assertTrue($userTranscript['final']);
        $roles = \App\Models\ChatHistory::query()->orderBy('id')->pluck('role')->all();
        $this->assertSame(['user', 'assistant'], $roles);

        $failed = $this->pipeline(userId: $user->id, conversationId: $conversation->id, stt: $this->listener('hello'));
        $failed->llm->responder = fn () => throw new LlmTimeoutError('timeout');
        $before = \App\Models\ChatHistory::query()->count();
        $errors = array_values(array_filter($failed->processAudio('audio'), fn (array $event) => ($event['type'] ?? '') === 'error'));
        $this->assertSame('llm_failed', $errors[0]['code']);
        $this->assertSame($before, \App\Models\ChatHistory::query()->count());
        $this->assertSame([], $failed->history);

        $down = $this->pipeline(stt: $this->listener('hello'));
        $down->llm->responder = fn () => throw new LlmUnavailableError('unavailable');
        $codes = array_column(array_values(array_filter($down->processAudio('audio'), fn (array $event) => ($event['type'] ?? '') === 'error')), 'code');
        $this->assertContains('llm_failed', $codes);

        $empty = $this->pipeline(stt: $this->listener(''));
        $emptyTypes = array_column($empty->processAudio('audio'), 'type');
        $this->assertNotContains('turn_complete', $emptyTypes);
        $this->assertSame([], $empty->llm->requests);

        $italian = $this->pipeline(targetLanguage: 'it-IT', stt: $this->listener(''));
        $italian->processAudio('audio');
        $this->assertSame(['audio', 'audio.wav', 'audio/wav', 'it'], $italian->stt->calls[0]);

        $sttDown = $this->pipeline(stt: new class
        {
            public function transcribe(string $audio, string $filename, string $mime, string $language): string
            {
                throw new \RuntimeException('STT down');
            }
        });
        $sttErrors = array_column(array_values(array_filter($sttDown->processAudio('audio'), fn (array $event) => ($event['type'] ?? '') === 'error')), 'code');
        $this->assertContains('stt_failed', $sttErrors);

        $statuses = $this->pipeline(stt: $this->listener('hello'));
        $statuses->llm->responder = fn () => new LlmStream(['Hi there.']);
        $values = array_column(array_values(array_filter($statuses->processAudio('audio'), fn (array $event) => ($event['type'] ?? '') === 'status')), 'value');
        $this->assertContains('transcribing', $values);
        $this->assertContains('thinking', $values);

        $many = $this->pipeline(stt: $this->listener('Tell me a story'), tts: $this->speaker());
        $many->llm->responder = fn () => new LlmStream(['Once upon a time. ', 'There was a cat. ', 'The cat was happy.']);
        $many->processAudio('audio');
        $this->assertSame(['Once upon a time.', 'There was a cat.', 'The cat was happy.'], array_column($many->tts->calls, 0));

        $silent = $this->pipeline(stt: $this->listener('Tell me a story'), tts: new class
        {
            public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
            {
                throw new \RuntimeException('TTS unavailable');
            }
        });
        $silent->llm->responder = fn () => new LlmStream(['Response with one full sentence.']);
        $silentEvents = $silent->processAudio('audio');
        $this->assertSame([], array_values(array_filter($silentEvents, fn (array $event) => ($event['type'] ?? '') === 'audio')));
        $this->assertSame([], array_values(array_filter($silentEvents, fn (array $event) => ($event['type'] ?? '') === 'error')));
        $assistant = array_values(array_filter($silentEvents, fn (array $event) => ($event['type'] ?? '') === 'transcript' && ($event['role'] ?? '') === 'assistant'))[0];
        $this->assertSame('Response with one full sentence.', $assistant['text']);
        $this->assertContains('turn_complete', array_column($silentEvents, 'type'));
        $this->assertSame(['role' => 'assistant', 'content' => 'Response with one full sentence.'], $silent->history[array_key_last($silent->history)]);

        $partialTts = $this->pipeline(stt: $this->listener('Tell me a story'), tts: new class
        {
            public int $sent = 0;

            public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
            {
                if ($text === 'Broken sentence.') {
                    throw new \RuntimeException('TTS unavailable');
                }
                $this->sent++;

                return 'audio';
            }
        });
        $partialTts->llm->responder = fn () => new LlmStream(['Broken sentence. Working sentence.']);
        $partialEvents = $partialTts->processAudio('audio');
        $this->assertCount(1, array_values(array_filter($partialEvents, fn (array $event) => ($event['type'] ?? '') === 'audio')));
        $this->assertSame([], array_values(array_filter($partialEvents, fn (array $event) => ($event['type'] ?? '') === 'error')));

        $blank = $this->pipeline(stt: $this->listener('test'));
        $blank->llm->responder = fn () => new LlmStream([]);
        $this->assertContains('turn_complete', array_column($blank->processAudio('audio'), 'type'));
        $this->assertSame([], $blank->history);

        $short = $this->pipeline(stt: $this->listener('OK'));
        $short->llm->responder = fn () => new LlmStream(['Alright!']);
        $shortTypes = array_column($short->processAudio('audio'), 'type');
        $this->assertContains('transcript', $shortTypes);
        $this->assertContains('turn_complete', $shortTypes);

        foreach (['en-GB', 'en-US', 'pt-PT'] as $language) {
            $regional = $this->pipeline(targetLanguage: $language, stt: $this->listener('hi'), tts: $this->speaker());
            $regional->llm->responder = fn () => new LlmStream(['Hello.']);
            $regional->processAudio('audio');
            $this->assertSame('Hello.', $regional->tts->calls[0][0]);
            $this->assertNull($regional->tts->calls[0][1]);
            $this->assertSame($language, $regional->tts->calls[0][2]);
            $this->assertSame(explode('-', $language)[0], $regional->stt->calls[0][3]);
        }

        $greet = $this->pipeline(tts: $this->speaker());
        $greet->llm->responder = fn () => new LlmStream(['Hello. How are you? I hope you\'re well.']);
        $greetEvents = $greet->greet();
        $this->assertContains('transcript', array_column($greetEvents, 'type'));
        $this->assertContains('turn_complete', array_column($greetEvents, 'type'));
        $this->assertSame('assistant', $greet->history[array_key_last($greet->history)]['role']);
        $this->assertStringNotContainsString('save_user_memory', $greet->llm->requests[0]['messages'][0]['content']);
        $this->assertSame(['Hello.', 'How are you? I hope you\'re well.'], array_column($greet->tts->calls, 0));

        $long = $this->pipeline(tts: $this->speaker());
        $longText = str_repeat('x', 300).'.';
        $long->llm->responder = fn () => new LlmStream([$longText]);
        $long->greet();
        $this->assertSame([$longText], array_column($long->tts->calls, 0));

        $broken = $this->pipeline();
        $broken->llm->responder = fn () => throw new LlmException('LLM is down');
        $brokenEvents = $broken->greet();
        $this->assertSame('error', $brokenEvents[0]['type']);

        $call = new LlmToolCall('call_1', 'save_user_memory', ['content' => 'A Bob le gusta la pizza']);
        $memory = $this->pipeline(userId: $user->id, studyPlanId: 5, stt: $this->listener('hi'));
        $memory->llm->responder = fn () => new LlmStream([
            new LlmToolResultEvent(new LlmToolResult($call, ['saved' => true])),
            'Nice to meet you.',
        ]);
        $memoryEvents = $memory->processAudio('audio');
        $this->assertSame(1, count(array_filter($memoryEvents, fn (array $event) => ($event['type'] ?? '') === 'memory_updated')));
        $this->assertStringContainsString('native language: Spanish', $memory->llm->requests[0]['tools'][0]->description);
        $this->assertStringContainsString('save_user_memory', $memory->llm->requests[0]['messages'][0]['content']);

        $reset = $this->pipeline(userId: $user->id, targetLanguage: 'en-GB', stt: $this->listener('hello'), tts: $this->speaker());
        $saved = new LlmToolResult(new LlmToolCall('call_1', 'save_user_memory', ['content' => 'The user likes tea']), ['saved' => true]);
        $reset->llm->responder = fn () => new LlmStream([
            'Partial response.',
            new LlmToolResultEvent($saved),
            new LlmStreamReset,
            'Complete normal response.',
        ]);
        $resetEvents = $reset->processAudio('audio');
        $this->assertSame([['Complete normal response.', null, 'en-GB']], $reset->tts->calls);
        $resetAssistant = array_values(array_filter($resetEvents, fn (array $event) => ($event['type'] ?? '') === 'transcript' && ($event['role'] ?? '') === 'assistant'))[0];
        $this->assertSame('Complete normal response.', $resetAssistant['text']);
        $this->assertSame('Complete normal response.', $reset->history[array_key_last($reset->history)]['content']);
        $this->assertSame(1, count(array_filter($resetEvents, fn (array $event) => ($event['type'] ?? '') === 'memory_updated')));

        foreach ([true, false] as $savedFlag) {
            $stream = new LlmStream([
                new LlmToolResultEvent(new LlmToolResult($call, ['saved' => $savedFlag])),
                new LlmException('continuation failed'),
            ]);
            $failing = $this->pipeline(userId: $user->id, stt: $this->listener('remember this'));
            $failing->llm->responder = fn () => $stream;
            $failedEvents = $failing->processAudio('audio');
            $this->assertSame($savedFlag ? 1 : 0, count(array_filter($failedEvents, fn (array $event) => ($event['type'] ?? '') === 'memory_updated')));
            $this->assertSame(['llm_failed'], array_column(array_values(array_filter($failedEvents, fn (array $event) => ($event['type'] ?? '') === 'error')), 'code'));
        }

        $toolError = $this->pipeline(userId: $user->id, stt: $this->listener('hi'));
        $toolError->llm->responder = function () use ($call) {
            $stream = new LlmStream(['Hello.']);
            $stream->toolResults = [new LlmToolResult($call, ['saved' => false, 'error' => 'persistence_failed'], true)];

            return $stream;
        };
        $toolEvents = $toolError->processAudio('audio');
        $this->assertContains('turn_complete', array_column($toolEvents, 'type'));
        $this->assertNotContains('memory_updated', array_column($toolEvents, 'type'));

        $unsupported = $this->pipeline(stt: new class
        {
            public int $i = 0;

            public function transcribe(string $audio, string $filename, string $mime, string $language): string
            {
                return $this->i++ === 0 ? 'first' : 'second';
            }
        });
        $unsupported->llm->responder = function (array $messages, bool $stream, ?array $tools) {
            if ($tools) {
                throw new \RuntimeException('provider does not support tools');
            }

            return new LlmStream(['First response.']);
        };
        $unsupported->processAudio('audio');
        $unsupported->llm->responder = fn () => new LlmStream(['Second response.']);
        $unsupported->processAudio('audio');
        $this->assertNotNull($unsupported->llm->requests[0]['tools']);
        $this->assertStringContainsString('save_user_memory', $unsupported->llm->requests[0]['messages'][0]['content']);
        $this->assertNull($unsupported->llm->requests[1]['tools']);
        $this->assertStringNotContainsString('save_user_memory', $unsupported->llm->requests[1]['messages'][0]['content']);
        $this->assertNull($unsupported->llm->requests[2]['tools']);
        $this->assertTrue($this->pipeline()->memoryToolsAvailable);

        $transient = $this->pipeline(stt: new class
        {
            public int $i = 0;

            public function transcribe(string $audio, string $filename, string $mime, string $language): string
            {
                return $this->i++ === 0 ? 'first' : 'second';
            }
        });
        $transient->llm->responder = fn () => new LlmStream(['Response.']);
        $transient->processAudio('a');
        $transient->processAudio('b');
        $this->assertNotNull($transient->llm->requests[0]['tools']);
        $this->assertNotNull($transient->llm->requests[1]['tools']);

        $safe = $this->pipeline(stt: $this->listener('hello'), tts: $this->speaker());
        $safe->llm->responder = fn () => new LlmStream(['Goodbye.']);
        $safe->processAudio('audio');
        $this->assertTrue(array_reduce($safe->tts->calls, fn (bool $carry, array $call) => $carry || str_contains($call[0], 'Goodbye'), false));
        foreach ($safe->tts->calls as $speech) {
            $this->assertStringNotContainsString('save_user_memory', $speech[0]);
        }

        $metered = $this->pipeline(userId: $user->id, stt: $this->listener('hello'));
        $metered->llm->responder = function () {
            $stream = new LlmStream(['Hi.']);
            $stream->promptTokens = 50;
            $stream->completionTokens = 30;
            $stream->totalTokens = 80;

            return $stream;
        };
        $metered->processAudio('audio');
        $usage = LlmUsage::query()->first();
        $this->assertNotNull($usage);
        $this->assertSame(50, $usage->prompt_tokens);
        $this->assertSame(30, $usage->completion_tokens);

        $unmetered = $this->pipeline(stt: $this->listener('hello'));
        $unmetered->llm->responder = function () {
            $stream = new LlmStream(['Hi.']);
            $stream->promptTokens = 4;
            $stream->completionTokens = 4;
            $stream->totalTokens = 8;

            return $stream;
        };
        $unmetered->processAudio('audio');
        $this->assertSame(1, LlmUsage::query()->count());

        $cancel = $this->pipeline();
        $cancel->history = [['role' => 'user', 'content' => 'hello']];
        $cancel->cancelCurrent();
        $this->assertSame([], $cancel->history);
        $kept = $this->pipeline();
        $kept->history = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
        ];
        $kept->cancelCurrent();
        $this->assertCount(2, $kept->history);
        $this->pipeline()->cancelCurrent();

        $redis = app(ArrayRedis::class);
        $tracked = $this->pipeline(userId: $user->id, access: app(Access::class));
        $tracked->cleanup(30);
        $tracked->cleanup(30);
        $recorded = [];
        foreach ($redis->scan(0)[1] as $key) {
            $recorded[$key] = (int) $redis->get($key);
        }
        $this->assertNotEmpty($recorded);
        foreach ($recorded as $seconds) {
            $this->assertSame(30, $seconds);
        }
        $this->assertTrue($tracked->recorded);
        $anonymous = $this->pipeline(access: app(Access::class));
        $anonymous->cleanup(15);
        $this->assertFalse($anonymous->recorded);
        $this->pipeline(userId: $user->id)->cleanup(15);
    }

    public function test_game_content_schema_and_shuffle(): void
    {
        $challenge = [
            'sentence' => '彼女は学生ですた。',
            'fragments' => ['彼女は', '学生', 'ですた。'],
            'error_index' => 2,
            'options' => ['です。', 'ます。', 'だます。'],
            'correct_index' => 0,
            'corrected_sentence' => '彼女は学生です。',
            'explanation' => 'La terminación correcta es です。',
            'source_id' => 'lesson:1',
        ];
        GamesService::validateDetectiveChallenge($challenge);
        $this->assertSame($challenge['sentence'], implode('', $challenge['fragments']));
        foreach ([
            ['corrected_sentence' => 'A different sentence'],
            ['options' => ['です。', 'です。', 'ます。']],
            ['error_index' => 19],
            ['fragments' => ['wrong', 'data', 'here']],
        ] as $override) {
            try {
                GamesService::validateDetectiveChallenge(array_merge($challenge, $override));
                $this->fail('expected detective rejection');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $order = [
            'sentence' => 'I often walk to school on day 0.',
            'clue' => 'Expresa que a menudo vas andando al colegio el día 0.',
            'fragments' => ['often', 'I', 'walk', 'to school on day 0.'],
            'separator' => ' ',
            'accepted_orders' => [[1, 0, 2, 3], [1, 2, 0, 3]],
            'explanation' => 'El adverbio often puede colocarse antes o después de walk.',
            'source_id' => 'grammar:present-simple',
        ];
        GamesService::validateSentenceOrderChallenge($order);
        foreach ([
            ['accepted_orders' => [[0, 0, 2, 3]]],
            ['accepted_orders' => [[1, 0, 2]]],
            ['accepted_orders' => [[1, 0, 2, 4]]],
            ['accepted_orders' => [[true, 0, 2, 3]]],
            ['accepted_orders' => [[1, 0, 2, 3], [1, 0, 2, 3]]],
            ['sentence' => 'Something else'],
            ['separator' => '-'],
            ['fragments' => ['often', ' I', 'walk', 'to school on day 0.']],
            ['fragments' => ['', 'I', 'walk', 'to school on day 0.']],
        ] as $override) {
            try {
                GamesService::validateSentenceOrderChallenge(array_merge($order, $override));
                $this->fail('expected order rejection');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $pairs = ['challenges' => []];
        foreach ([['cat', 'gato'], ['dog', 'perro'], ['book', 'libro'], ['house', 'casa'], ['tree', 'árbol']] as [$term, $meaning]) {
            $pairs['challenges'][] = [
                'term' => $term,
                'meaning' => $meaning,
                'sentence' => "This is a {$term}.",
                'translation' => "Esto es: {$meaning}.",
                'source_id' => 'vocabulary:test',
            ];
        }
        GamesService::validateVocabularyPairs($pairs);
        foreach ([['term', ' CAT '], ['meaning', ' GATO '], ['term', 'ｃａｔ'], ['meaning', '   ']] as [$field, $value]) {
            $raw = $pairs;
            $raw['challenges'][1][$field] = $value;
            try {
                GamesService::validateVocabularyPairs($raw);
                $this->fail('expected pair rejection');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $games = app(GamesService::class);
        foreach ([
            [['です。', '彼女は', '学生'], '', '彼女は学生です。', [1, 2, 0]],
            [['是', '她', '学生。'], '', '她是学生。', [1, 0, 2]],
            [['학교에', '저는', '가요.'], ' ', '저는 학교에 가요.', [1, 0, 2]],
        ] as [$fragments, $separator, $sentence, $accepted]) {
            $shuffled = $games->shuffleOrderChallenge([
                'sentence' => $sentence,
                'clue' => 'Construye la frase indicada.',
                'fragments' => $fragments,
                'separator' => $separator,
                'accepted_orders' => [$accepted],
                'explanation' => 'Respeta el orden natural de esta oración.',
                'source_id' => 'lesson:1',
            ]);
            $this->assertNotSame($sentence, implode($separator, $shuffled['fragments']));
            $rebuilt = implode($separator, array_map(fn (int $index) => $shuffled['fragments'][$index], $shuffled['accepted_orders'][0]));
            $this->assertSame($sentence, $rebuilt);
        }
    }

    private function pipeline(
        ?LlmAdapter $llm = null,
        ?object $tts = null,
        ?object $stt = null,
        string $nativeLanguage = 'es',
        string $targetLanguage = 'en-GB',
        ?array $initialContext = null,
        ?int $userId = null,
        ?int $conversationId = null,
        ?string $bio = null,
        mixed $learningGoals = null,
        string $voice = '',
        ?int $studyPlanId = null,
        ?Access $access = null,
    ): ConversationPipeline {
        return new ConversationPipeline(
            llm: $llm ?? new LlmAdapter('ollama', 'test'),
            tts: $tts ?? $this->speaker(),
            stt: $stt ?? $this->listener(''),
            nativeLanguage: $nativeLanguage,
            targetLanguage: $targetLanguage,
            initialContext: $initialContext,
            userId: $userId,
            conversationId: $conversationId,
            bio: $bio,
            learningGoals: $learningGoals,
            voice: $voice,
            studyPlanId: $studyPlanId,
            access: $access,
        );
    }

    private function speaker(): object
    {
        return new class
        {
            /** @var list<array{0: string, 1: ?string, 2: ?string}> */
            public array $calls = [];

            public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
            {
                $this->calls[] = [$text, $voice, $language];

                return 'audio';
            }
        };
    }

    private function listener(string $text): object
    {
        return new class($text)
        {
            /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
            public array $calls = [];

            public function __construct(private string $text) {}

            public function transcribe(string $audio, string $filename, string $mime, string $language): string
            {
                $this->calls[] = [$audio, $filename, $mime, $language];

                return $this->text;
            }
        };
    }
}
