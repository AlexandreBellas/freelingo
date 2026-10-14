<?php

namespace Tests\Feature;

use App\Models\ListeningExercise;
use App\Models\ReadingExercise;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmStream;
use App\Services\Practice\SpeechGateway;
use App\Services\Speech\KokoroTtsService;
use App\Services\Speech\WhisperSttService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PracticeDomainsTest extends TestCase
{
    public function test_vocabulary_grammar_phrasebook_and_curriculum_catalogs(): void
    {
        [, $headers] = $this->makeUser();
        $sets = $this->api('GET', '/api/vocabulary', [], $headers);
        $sets->assertOk();
        $this->assertNotEmpty($sets->json('sets'));
        $this->api('GET', '/api/vocabulary')->assertStatus(401);
        $this->api('GET', '/api/vocabulary/level/a1', [], $headers)->assertOk();
        $this->api('GET', '/api/vocabulary/level/X9', [], $headers)->assertStatus(400);
        $id = $sets->json('sets.0.id');
        $this->api('GET', '/api/vocabulary/'.$id, [], $headers)->assertOk();
        $this->api('GET', '/api/vocabulary/missing-set', [], $headers)->assertStatus(404);
        $this->api('GET', '/api/vocabulary?language=ja-JP', [], $headers)->assertOk();
        $this->api('GET', '/api/grammar', [], $headers)->assertOk();
        $slug = $this->api('GET', '/api/grammar?language=en-GB', [], $headers)->json('topics.0.slug');
        $this->api('GET', '/api/grammar/'.$slug, [], $headers)->assertOk();
        $this->api('GET', '/api/grammar/missing-topic', [], $headers)->assertStatus(404);
        $this->api('GET', '/api/phrasebook', [], $headers)->assertOk();
        $this->api('GET', '/api/phrasebook/level/A1', [], $headers)->assertOk();
        $category = $this->api('GET', '/api/phrasebook?language=en-GB', [], $headers)->json('categories.0.id');
        $this->api('GET', '/api/phrasebook/'.$category, [], $headers)->assertOk();
        $this->api('GET', '/api/curriculum', [], $headers)->assertOk()->assertJsonStructure(['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);
        $this->assertNotEmpty($this->api('GET', '/api/curriculum/A1', [], $headers)->json());
        $this->assertSame([], $this->api('GET', '/api/curriculum/Z9', [], $headers)->json());
    }

    public function test_native_help_generates_then_uses_cache(): void
    {
        [, $headers] = $this->makeUser();
        $adapter = new LlmAdapter('ollama', 'test');
        $calls = 0;
        $adapter->responder = function () use (&$calls) {
            $calls++;

            return json_encode([
                'summary' => 'Resumen del tema',
                'study_tips' => ['a', 'b', 'c'],
                'word_notes' => [['word' => 'hello', 'meaning' => 'hola', 'note' => 'n']],
                'common_traps' => [],
                'mini_glossary' => [],
                'practice_prompts' => [],
            ], JSON_UNESCAPED_UNICODE);
        };
        $this->app->instance(LlmAdapter::class, $adapter);
        $id = $this->api('GET', '/api/vocabulary?language=en-GB', [], $headers)->json('sets.0.id');
        $first = $this->api('POST', '/api/vocabulary/'.$id.'/native-help?language=en-GB', [], $headers);
        $second = $this->api('POST', '/api/vocabulary/'.$id.'/native-help?language=en-GB', [], $headers);
        $first->assertOk();
        $this->assertSame('Resumen del tema', $first->json('native_help.summary'));
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $calls);
        $this->api('POST', '/api/vocabulary/missing/native-help', [], $headers)->assertStatus(404);
    }

    public function test_reading_flow(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->plan($user, 'A2', 'en-US');
        $this->api('GET', '/api/reading/next', [], $headers)->assertOk()->assertJsonPath('available', false);
        $exercise = ReadingExercise::query()->create([
            'level' => 'A2',
            'target_language' => 'en-US',
            'exercise_type' => 'email',
            'topic' => 'travel',
            'text' => 'Hello from London.',
            'questions' => $this->questions(),
            'view_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
        $next = $this->api('GET', '/api/reading/next', [], $headers);
        $next->assertOk()->assertJsonPath('available', true)->assertJsonPath('exercise.text', 'Hello from London.');
        $this->assertArrayNotHasKey('correct', $next->json('exercise.questions.0'));
        $context = ['study_plan_id' => $plan->id, 'target_language' => 'en-US', 'level' => 'A2'];
        $answers = ['0' => 'A', '1' => 'B', '2' => 'A', '3' => 'A', '4' => 'A'];
        $submit = $this->api('POST', '/api/reading/attempt', [
            'context' => $context,
            'exercise_id' => $exercise->id,
            'answers' => $answers,
        ], $headers);
        $submit->assertOk();
        $this->assertSame(5, $submit->json('score'));
        $this->assertSame(50, $submit->json('xp_earned'));
        $this->api('POST', '/api/reading/attempt', [
            'context' => $context,
            'exercise_id' => $exercise->id,
            'answers' => $answers,
        ], $headers)->assertStatus(409);
        $this->api('POST', '/api/reading/attempt', [
            'context' => $context,
            'exercise_id' => 99999,
            'answers' => $answers,
        ], $headers)->assertStatus(404);
        $history = $this->api('GET', '/api/reading/history', [], $headers);
        $history->assertOk();
        $this->assertSame(1, $history->json('total'));
    }

    public function test_listening_flow_and_audio(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->plan($user);
        $this->api('GET', '/api/listening/next')->assertStatus(401);
        $this->api('GET', '/api/listening/next', [], $headers)->assertOk()->assertJsonPath('available', false);
        $exercise = ListeningExercise::query()->create([
            'level' => 'A2',
            'target_language' => 'en-US',
            'exercise_type' => 'story',
            'topic' => 'park',
            'text' => 'A short story.',
            'audio_path' => '',
            'duration_seconds' => 3,
            'questions' => $this->questions(),
            'play_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
        $next = $this->api('GET', '/api/listening/next', [], $headers);
        $next->assertOk();
        $this->assertArrayNotHasKey('text', $next->json('exercise'));
        $dir = storage_path('app/audio/listening');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/'.$exercise->id.'.mp3', 'mp3');
        $this->api('GET', '/api/listening/audio/'.$exercise->id, [], $headers)->assertOk();
        $this->api('GET', '/api/listening/audio/99999', [], $headers)->assertStatus(404);
        $context = ['study_plan_id' => $plan->id, 'target_language' => 'en-US', 'level' => 'A2'];
        $answers = ['0' => 'A', '1' => 'B', '2' => 'A', '3' => 'A', '4' => 'A'];
        $submit = $this->api('POST', '/api/listening/attempt', [
            'context' => $context,
            'exercise_id' => $exercise->id,
            'answers' => $answers,
        ], $headers);
        $submit->assertOk();
        $this->assertSame(5, $submit->json('score'));
        $this->assertSame('A short story.', $submit->json('text'));
        $this->api('GET', '/api/listening/history?limit=100', [], $headers)->assertOk()->assertJsonPath('limit', 50);
    }

    public function test_games_free_detective_round(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->plan($user, 'A1', 'en-US');
        $catalog = $this->api('GET', '/api/games/detective', [], $headers);
        $catalog->assertOk();
        $this->assertTrue($catalog->json('modes.free.available'));
        $slug = null;
        foreach ($catalog->json() ? [] : [] as $ignored) {
        }
        $topics = $this->api('GET', '/api/grammar?language=en-US', [], $headers)->json('topics');
        foreach ($topics as $topic) {
            if ($topic['level'] === 'A1') {
                $slug = $topic['slug'];
                break;
            }
        }
        $adapter = new LlmAdapter('ollama', 'test');
        $step = 0;
        $adapter->responder = function () use (&$step) {
            $step++;
            $source = \App\Models\GameSession::query()->latest('created_at')->first();
            $sourceId = $source?->context['sources'][0]['source_id'] ?? 'grammar:unknown';
            if ($step % 2 === 1) {
                return json_encode(['challenges' => [[
                    'sentence' => 'She go home yesterday.',
                    'fragments' => ['She', 'go', 'home', 'yesterday.'],
                    'error_index' => 1,
                    'options' => ['goes', 'went', 'gone'],
                    'correct_index' => 1,
                    'corrected_sentence' => 'She went home yesterday.',
                    'explanation' => 'Past simple uses went, not go.',
                    'source_id' => $sourceId,
                ]]]);
            }

            return json_encode(['valid' => true, 'reason' => 'ok']);
        };
        $this->app->instance(LlmAdapter::class, $adapter);
        $requestId = '11111111-1111-1111-1111-111111111111';
        $started = $this->api('POST', '/api/games/detective', [
            'request_id' => $requestId,
            'study_plan_id' => $plan->id,
            'mode' => 'free',
        ], $headers);
        $started->assertStatus(202);
        $session = $this->api('GET', '/api/games/sessions/'.$requestId, [], $headers);
        $session->assertOk()->assertJsonPath('status', 'ready');
        $this->assertArrayNotHasKey('error_index', $session->json('challenges.0'));
        $detected = $this->api('POST', '/api/games/sessions/'.$requestId.'/answer', [
            'challenge' => 0,
            'step' => 'detect',
            'choice' => 1,
        ], $headers);
        $detected->assertOk();
        $this->assertSame(1, $detected->json('challenges.0.error_index'));
        $corrected = $this->api('POST', '/api/games/sessions/'.$requestId.'/answer', [
            'challenge' => 0,
            'step' => 'correct',
            'choice' => 1,
        ], $headers);
        $corrected->assertOk()->assertJsonPath('status', 'completed');
        $this->assertGreaterThan(0, $corrected->json('xp_earned'));
    }

    public function test_chat_conversation_and_stream(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->plan($user);
        $created = $this->api('POST', '/api/chat/conversations', ['title' => 'Practice'], $headers);
        $created->assertOk();
        $id = $created->json('id');
        $adapter = new LlmAdapter('ollama', 'test');
        $adapter->responder = fn () => new LlmStream(['Hello ', 'there.']);
        $this->app->instance(LlmAdapter::class, $adapter);
        $response = $this->api('POST', '/api/chat', ['message' => 'Hi', 'conversation_id' => $id], $headers);
        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('conversation_id', $body);
        $this->assertStringContainsString('Hello ', $body);
        $this->assertStringContainsString('done', $body);
        $messages = $this->api('GET', '/api/chat/conversations/'.$id.'/messages', [], $headers);
        $messages->assertOk();
        $this->assertCount(2, $messages->json('messages'));
        $this->api('DELETE', '/api/chat/conversations/'.$id, [], $headers)->assertNoContent();
    }

    public function test_tts_stt_and_warmup(): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->plan($user, 'A2', 'it-IT');
        $tts = new KokoroTtsService('http://kokoro.test', 'af_heart');
        $tts->transport = fn (string $text, array $payload) => $payload['op'] ?? '' === 'health' ? 'ok' : 'AUDIO:'.$text;
        $stt = new WhisperSttService('http://whisper.test');
        $seen = null;
        $stt->transport = function (string $bytes, array $payload) use (&$seen) {
            $seen = $payload;

            return 'ciao';
        };
        $this->app->instance(SpeechGateway::class, new SpeechGateway($tts, $stt, 'local', 'local'));
        $speech = $this->withHeaders($headers)->postJson('/api/tts', [
            'text' => '**Hello**',
            'study_plan_id' => $plan->id,
            'voice' => 'nova',
        ]);
        $speech->assertOk();
        $this->assertSame('AUDIO:**Hello**', $speech->getContent());
        $this->assertSame('af_heart', $tts->requests[0]['voice']);
        $file = UploadedFile::fake()->createWithContent('clip.webm', 'abc');
        $transcript = $this->withHeaders($headers)->post('/api/stt', [
            'study_plan_id' => $plan->id,
            'audio' => $file,
        ]);
        $transcript->assertOk()->assertJsonPath('text', 'ciao');
        $this->assertSame('it', $seen['params']['language']);
        $this->api('POST', '/api/stt', [], $headers)->assertStatus(422);
        $this->api('POST', '/api/conversation/warmup', [], $headers)->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_conversation_pipeline_transcript(): void
    {
        $llm = new LlmAdapter('ollama', 'test');
        $llm->responder = fn () => new LlmStream(['Hello.', ' How are you?']);
        $tts = new KokoroTtsService('http://kokoro.test', 'af_heart');
        $tts->transport = fn (string $text) => 'mp3:'.$text;
        $stt = new WhisperSttService('http://whisper.test');
        $stt->transport = fn () => 'I am fine';
        $pipeline = new \App\Services\Practice\ConversationPipeline(
            llm: $llm,
            tts: $tts,
            stt: $stt,
            nativeLanguage: 'es',
            targetLanguage: 'it-IT',
            studentName: 'Ana',
            bio: 'Lives in Rome',
            learningGoals: ['travel'],
        );
        $this->assertStringContainsString('Ana', $pipeline->systemPrompt);
        $this->assertStringContainsString('Italian', $pipeline->systemPrompt);
        $events = $pipeline->processAudio('wav');
        $types = array_column($events, 'type');
        $this->assertContains('transcript', $types);
        $this->assertContains('audio', $types);
        $this->assertContains('turn_complete', $types);
        $this->assertSame('it', $stt->requests[0]['params']['language']);
    }

    private function plan(User $user, string $level = 'A2', ?string $language = null): StudyPlan
    {
        $active = UserLanguage::query()->where('user_id', $user->id)->where('is_active', true)->firstOrFail();
        if ($language && $language !== $active->target_language) {
            $active->target_language = $language;
            $active->save();
            $user->target_language = $language;
            $user->save();
        }

        return StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $active->id,
            'cefr_level' => $level,
            'target_language' => $language ?? $user->target_language,
            'goals' => ['grammar'],
            'current_unit' => '',
            'progress_day' => 0,
            'duration_weeks' => 8,
            'days_per_week' => 5,
            'generated_plan' => ['weekly_plan' => []],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(): array
    {
        $rows = [];
        foreach ([0 => 'A', 1 => 'B', 2 => 'A', 3 => 'A', 4 => 'A'] as $index => $correct) {
            $rows[] = [
                'index' => $index,
                'question' => 'Question '.$index,
                'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
                'correct' => $correct,
            ];
        }

        return $rows;
    }
}
