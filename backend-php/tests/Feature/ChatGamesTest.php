<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Conversation;
use App\Models\GameAdmission;
use App\Models\GameRequest;
use App\Models\GameSession;
use App\Models\Lesson;
use App\Models\Progress;
use App\Models\ProgressReward;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmStream;
use App\Services\Practice\ConversationHandshake;
use App\Services\Practice\GamesService;
use App\Services\Practice\SpeechGateway;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatGamesTest extends TestCase
{
    private int $seq = 0;

    public function test_chat_stream_history_and_conversation_crud(): void
    {
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $this->api('GET', '/api/chat/conversations', [], ['Authorization' => 'Bearer invalid'])->assertStatus(401);
        $this->api('POST', '/api/chat', ['message' => 'Hi'], ['Authorization' => 'Bearer invalid'])->assertStatus(401);
        $this->api('GET', '/api/chat/conversations', [], $headers)->assertOk()->assertExactJson([]);
        $this->api('GET', '/api/chat/history', [], $headers)->assertOk()->assertJsonPath('messages', []);

        $titled = $this->api('POST', '/api/chat/conversations', ['title' => 'Travel plans'], $headers);
        $titled->assertOk();
        $this->assertSame('Travel plans', $titled->json('title'));
        $this->assertSame('chat', $titled->json('source'));
        $plain = $this->api('POST', '/api/chat/conversations', [], $headers);
        $plain->assertOk()->assertJsonPath('title', 'New conversation')->assertJsonPath('source', 'chat');
        Conversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Voice session — 23 de mayo de 2026',
            'source' => 'voice',
            'study_plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
        $listed = $this->api('GET', '/api/chat/conversations', [], $headers);
        $listed->assertOk();
        $this->assertCount(3, $listed->json());
        $this->assertContains('voice', array_column($listed->json(), 'source'));
        foreach ($listed->json() as $row) {
            $this->assertArrayHasKey('source', $row);
        }
        $voice = array_values(array_filter($listed->json(), fn (array $row) => $row['source'] === 'voice'))[0];
        $this->assertSame('Voice session — 23 de mayo de 2026', $voice['title']);

        $id = $plain->json('id');
        $this->api('GET', '/api/chat/conversations/'.$id.'/messages', [], $headers)
            ->assertOk()
            ->assertJsonPath('messages', []);
        $this->api('GET', '/api/chat/conversations/99999/messages', [], $headers)->assertStatus(404);
        [$other, $otherHeaders] = $this->user();
        $this->plan($other);
        $this->api('DELETE', '/api/chat/conversations/'.$id, [], $otherHeaders)->assertStatus(404);
        $this->api('GET', '/api/chat/conversations/'.$id.'/messages', [], $otherHeaders)->assertStatus(404);
        $this->api('DELETE', '/api/chat/conversations/99999', [], $headers)->assertStatus(404);
        $this->api('DELETE', '/api/chat/conversations/'.$id, [], $headers)->assertNoContent();
        $remaining = array_column($this->api('GET', '/api/chat/conversations', [], $headers)->json(), 'id');
        $this->assertNotContains($id, $remaining);

        $adapter = new LlmAdapter('ollama', 'test');
        $adapter->responder = fn () => new LlmStream(['Hello']);
        $this->app->instance(LlmAdapter::class, $adapter);
        $stream = $this->api('POST', '/api/chat', ['message' => 'Hi'], $headers);
        $stream->assertOk();
        $this->assertStringContainsString('Hello', $stream->getContent());
        $this->assertStringContainsString('"token"', $stream->getContent());
    }

    public function test_conversation_handshake_warmup_and_profile_settings(): void
    {
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $me = $this->api('GET', '/api/auth/me', [], $headers);
        $me->assertOk();
        $this->assertSame(1800, $me->json('conversation_max_duration'));
        $this->assertSame(180, $me->json('conversation_inactivity_timeout'));
        $this->assertSame(0, $me->json('conversation_speech_pause'));
        $this->api('PATCH', '/api/auth/me', [
            'conversation_max_duration' => 900,
            'conversation_inactivity_timeout' => 60,
            'conversation_speech_pause' => 3000,
        ], $headers)->assertOk();
        $updated = $this->api('GET', '/api/auth/me', [], $headers);
        $this->assertSame(900, $updated->json('conversation_max_duration'));
        $this->assertSame(60, $updated->json('conversation_inactivity_timeout'));
        $this->assertSame(3000, $updated->json('conversation_speech_pause'));
        $this->api('PATCH', '/api/auth/me', ['conversation_speech_pause' => 0], $headers)->assertOk();
        $this->assertSame(0, $this->api('GET', '/api/auth/me', [], $headers)->json('conversation_speech_pause'));
        $this->api('PATCH', '/api/auth/me', ['conversation_max_duration' => 600], $headers)->assertStatus(422);
        $this->api('PATCH', '/api/auth/me', ['conversation_inactivity_timeout' => 120], $headers)->assertStatus(422);
        $this->api('PATCH', '/api/auth/me', ['conversation_speech_pause' => 1500], $headers)->assertStatus(422);

        $invalid = app(ConversationHandshake::class)->open(['token' => 'not-a-token']);
        $this->assertSame('auth_failed', $invalid['events'][0]['code']);
        $this->assertSame(1008, $invalid['close']);
        $missing = app(ConversationHandshake::class)->open([]);
        $this->assertSame('auth_failed', $missing['events'][0]['code']);
        $this->assertSame(1008, $missing['close']);

        $this->app->instance(SpeechGateway::class, new SpeechGateway(null, null, 'local', 'local'));
        $disabled = app(ConversationHandshake::class)->open(['token' => $this->token($headers)]);
        $this->assertSame('services_disabled', $disabled['events'][0]['code']);
        $this->assertSame(1011, $disabled['close']);

        $openai = new class
        {
            public string $model = 'tts-1';

            public int $healthCalls = 0;

            public int $synthCalls = 0;

            public function health(): void
            {
                $this->healthCalls++;
            }

            public function synthesize(string $text): string
            {
                $this->synthCalls++;

                return $text;
            }
        };
        $stt = new class
        {
            /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
            public array $calls = [];

            public function transcribe(string $bytes, string $filename, string $mime, string $language): string
            {
                $this->calls[] = [$bytes, $filename, $mime, $language];

                return '';
            }
        };
        $this->app->instance(SpeechGateway::class, new SpeechGateway($openai, $stt, 'openai', 'local'));
        $this->api('POST', '/api/conversation/warmup', [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        $this->assertSame(1, $openai->healthCalls);
        $this->assertSame(0, $openai->synthCalls);
        $this->assertStringStartsWith('RIFF', $stt->calls[0][0]);
        $this->assertSame(['warmup.wav', 'audio/wav', 'en'], array_slice($stt->calls[0], 1));

        $local = new class
        {
            /** @var list<string> */
            public array $calls = [];

            public function synthesize(string $text): string
            {
                $this->calls[] = $text;

                return 'audio';
            }
        };
        $this->app->instance(SpeechGateway::class, new SpeechGateway($local, $stt, 'local', 'local'));
        $this->api('POST', '/api/conversation/warmup', [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        $this->assertSame(['ready'], $local->calls);

        config([
            'freelingo.stripe_enabled' => true,
            'freelingo.freemium_voice_weekly_minutes' => 0,
        ]);
        $this->api('POST', '/api/conversation/warmup', ['trial_token' => 'missing'], $headers)
            ->assertStatus(402)
            ->assertJsonPath('detail', 'subscription_required');
        $token = 'assessment-trial';
        app(ArrayRedis::class)->set('assessment_voice_trial:'.$user->id.':'.$token, json_encode([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'target_language' => $plan->target_language,
            'cefr_level' => $plan->cefr_level,
        ]));
        $this->api('POST', '/api/conversation/warmup', ['trial_token' => $token], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'ready');
    }

    public function test_detective_answers_rewards_access_and_generation(): void
    {
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $games = app(GamesService::class);
        $session = $this->ready($user, $plan, 'detective');
        $url = '/api/games/sessions/'.$session->id;
        $initial = $this->api('GET', $url, [], $headers);
        $initial->assertOk();
        $this->assertArrayNotHasKey('options', $initial->json('challenges.0'));
        $this->assertArrayNotHasKey('corrected_sentence', $initial->json('challenges.0'));
        $this->assertArrayNotHasKey('context', $initial->json());
        $this->api('POST', $url.'/answer', ['challenge' => 0, 'step' => 'correct', 'choice' => 0], $headers)->assertStatus(409);
        $detected = $this->api('POST', $url.'/answer', ['challenge' => 0, 'step' => 'detect', 'choice' => 1], $headers);
        $detected->assertOk();
        $this->assertSame(['goes ', 'going ', 'gone '], $detected->json('challenges.0.options'));
        $this->assertArrayNotHasKey('correct_index', $detected->json('challenges.0'));
        $this->assertArrayNotHasKey('explanation', $detected->json('challenges.0'));
        $other = $this->secondPlan($user, 'fr-FR');
        $this->activate($user, 'fr-FR');
        $games->answer($user->id, $session->id, ['challenge' => 0, 'step' => 'correct', 'choice' => 0]);
        for ($i = 1; $i < 5; $i++) {
            $games->answer($user->id, $session->id, ['challenge' => $i, 'step' => 'detect', 'choice' => 1]);
            $games->answer($user->id, $session->id, ['challenge' => $i, 'step' => 'correct', 'choice' => 0]);
        }
        $session->refresh();
        $this->assertSame(15, (int) $session->xp_earned);
        $this->assertSame(15, (int) Progress::query()->where('study_plan_id', $plan->id)->value('xp_earned'));
        $this->assertNull(Progress::query()->where('study_plan_id', $other->id)->first());
        $this->api('POST', $url.'/answer', ['challenge' => 4, 'step' => 'correct', 'choice' => 0], $headers)->assertOk();
        $this->assertSame(1, ProgressReward::query()->count());
        $this->api('POST', $url.'/answer', ['challenge' => 4, 'step' => 'correct', 'choice' => 1], $headers)->assertStatus(409);

        [$owner, $ownerHeaders] = $this->user();
        $ownedPlan = $this->plan($owner);
        $foreignSession = $this->ready($owner, $ownedPlan, 'detective');
        $foreignUrl = '/api/games/sessions/'.$foreignSession->id;
        [, $adminHeaders] = $this->makeAdmin();
        $this->api('GET', $foreignUrl)->assertStatus(401);
        $this->api('GET', $foreignUrl, [], $adminHeaders)->assertStatus(404);
        $this->api('POST', $foreignUrl.'/answer', ['challenge' => 0, 'step' => 'detect', 'choice' => 1], $adminHeaders)->assertStatus(404);
        $this->api('POST', $foreignUrl.'/abandon', [], $adminHeaders)->assertStatus(404);
        $this->api('GET', $foreignUrl, [], $ownerHeaders)->assertOk();

        foreach ([[0, 15], [40, 5], [44, 1], [45, 0]] as [$prior, $expected]) {
            [$cappedUser] = $this->user();
            $cappedPlan = $this->plan($cappedUser);
            app(\App\Services\Learning\ProgressRewards::class)->award($cappedUser->id, $cappedPlan->id, 'games', 'prior', $prior, Carbon::now('UTC')->toDateString());
            $capped = $this->ready($cappedUser, $cappedPlan, 'detective');
            $this->finishDetective(app(GamesService::class), $cappedUser->id, $capped->id, true);
            $capped->refresh();
            $this->assertSame($expected, (int) $capped->xp_earned);
            app(GamesService::class)->answer($cappedUser->id, $capped->id, ['challenge' => 4, 'step' => 'correct', 'choice' => 0]);
            $progress = Progress::query()->where('study_plan_id', $cappedPlan->id)->first();
            $this->assertSame($prior + $expected, (int) $progress->xp_earned);
            $this->assertSame(0, (int) $progress->lessons_completed);
            $this->assertSame(0, (int) $progress->exercises_total);
            $this->assertSame([], $progress->skills ?? []);
        }

        [$wrongUser] = $this->user();
        $wrong = $this->ready($wrongUser, $this->plan($wrongUser), 'detective');
        $this->finishDetective(app(GamesService::class), $wrongUser->id, $wrong->id, false);
        $wrong->refresh();
        $this->assertSame(5, (int) $wrong->xp_earned);
        $this->assertSame(1, (int) Progress::query()->where('study_plan_id', $wrong->study_plan_id)->value('streak_day'));

        [$splitUser] = $this->user();
        $first = $this->plan($splitUser);
        app(\App\Services\Learning\ProgressRewards::class)->award($splitUser->id, $first->id, 'games', 'full', 45, Carbon::now('UTC')->toDateString());
        $second = $this->secondPlan($splitUser, 'de-DE', 'A1');
        $split = $this->ready($splitUser, $second, 'detective');
        $this->finishDetective(app(GamesService::class), $splitUser->id, $split->id, true);
        $split->refresh();
        $this->assertSame(15, (int) $split->xp_earned);
        $this->assertSame(45, (int) Progress::query()->where('study_plan_id', $first->id)->value('xp_earned'));

        config([
            'freelingo.stripe_enabled' => true,
            'freelingo.freemium_games_daily' => 0,
            'freelingo.freemium_trial_enabled' => true,
        ]);
        foreach (['self_hosted', 'active', 'trialing', 'trial', 'free'] as $access) {
            config(['freelingo.stripe_enabled' => $access !== 'self_hosted']);
            [$accessUser] = $this->user();
            $accessUser->subscription_status = in_array($access, ['active', 'trialing'], true) ? $access : 'none';
            $accessUser->freemium_trial_ends_at = $access === 'trial' ? Carbon::now('UTC')->addDay() : null;
            $accessUser->save();
            $accessPlan = $this->plan($accessUser);
            $body = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $accessPlan->id, 'mode' => 'free'];
            $service = app(GamesService::class);
            if ($access === 'free') {
                try {
                    $service->create($accessUser->fresh(), $accessPlan, $body, 'detective');
                    $this->fail('free access should be rejected');
                } catch (ApiException $exception) {
                    $this->assertSame(402, $exception->status);
                }
            } else {
                [, $created] = $service->create($accessUser->fresh(), $accessPlan, $body, 'detective');
                $this->assertTrue($created);
                $this->assertSame(0, GameAdmission::query()->where('user_id', $accessUser->id)->count());
            }
        }

        [$stampUser] = $this->user();
        $stamped = $this->ready($stampUser, $this->plan($stampUser), 'detective');
        $at = Carbon::parse('2026-01-02 23:59:59', 'UTC');
        app(GamesService::class)->finish($stamped, $at, true, 5);
        $stamped->refresh();
        $this->assertSame('2026-01-02 23:59:59', $stamped->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02', ProgressReward::query()->where('source_key', $stamped->id)->first()->date->format('Y-m-d'));

        [$leftUser, $leftHeaders] = $this->user();
        $left = $this->ready($leftUser, $this->plan($leftUser), 'detective');
        app(GamesService::class)->answer($leftUser->id, $left->id, ['challenge' => 0, 'step' => 'detect', 'choice' => 0]);
        $leftUrl = '/api/games/sessions/'.$left->id;
        $this->api('POST', $leftUrl.'/abandon', [], $leftHeaders)->assertOk()->assertJsonPath('status', 'abandoned');
        $this->api('POST', $leftUrl.'/abandon', [], $leftHeaders)->assertOk();
        $this->api('POST', $leftUrl.'/answer', ['challenge' => 0, 'step' => 'correct', 'choice' => 0], $leftHeaders)->assertStatus(409);
        $this->assertSame(0, (int) Progress::query()->where('user_id', $leftUser->id)->value('xp_earned'));

        $this->generationOutcomes('detective');
    }

    public function test_game_requests_quota_catalog_and_prepare_sources(): void
    {
        foreach ([true, false] as $complete) {
            foreach ([true, false] as $hasPlan) {
                $this->reusedRequest($complete, $hasPlan);
            }
        }

        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $session = $this->ready($user, $plan, 'detective');
        $adapter = $this->bindAdapter();
        $adapter->responder = function () {
            $this->fail('a bound request must not generate');
        };
        $requestId = (string) Str::uuid();
        $body = ['request_id' => $requestId, 'study_plan_id' => $plan->id, 'mode' => 'free'];
        $this->api('POST', '/api/games/detective', $body, $headers)->assertStatus(202);
        $session->delete();
        $request = GameRequest::query()->find($requestId);
        $this->assertNull($request->session_id);
        $this->api('GET', '/api/games/sessions/'.$requestId, [], $headers)->assertStatus(404);
        $this->api('POST', '/api/games/detective', $body, $headers)->assertStatus(409)->assertJsonPath('detail', 'request_conflict');
        $plan->delete();
        $this->api('POST', '/api/games/detective', $body, $headers)->assertStatus(409)->assertJsonPath('detail', 'request_conflict');
        $this->assertSame(0, GameSession::query()->where('user_id', $user->id)->count());
        $this->assertSame([], $adapter->requests);

        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 1]);
        [$quotaUser] = $this->user();
        $quotaPlan = $this->plan($quotaUser);
        $games = app(GamesService::class);
        $createBody = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $quotaPlan->id, 'mode' => 'free'];
        [$createdSession, $created] = $games->create($quotaUser->fresh(), $quotaPlan, $createBody, 'detective');
        $this->assertTrue($created);
        [, $again] = $games->create($quotaUser->fresh(), $quotaPlan, $createBody, 'detective');
        $this->assertFalse($again);
        $other = $this->secondPlan($quotaUser, 'fr-FR');
        try {
            $games->create($quotaUser->fresh(), $other, [
                'request_id' => (string) Str::uuid(),
                'study_plan_id' => $other->id,
                'mode' => 'free',
            ], 'detective');
            $this->fail('second plan should exhaust the daily game quota');
        } catch (ApiException $exception) {
            $this->assertSame(402, $exception->status);
            $this->assertSame('freemium_exhausted', $exception->detail['reason']);
        }
        $admission = GameAdmission::query()->find($createdSession->id);
        $admission->status = 'consumed';
        $admission->save();
        $quotaPlan->delete();
        $this->assertSame(0, $games->quota($quotaUser->id)['remaining']);

        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 3]);
        [$expireUser] = $this->user();
        $expirePlan = $this->plan($expireUser);
        $expireGames = app(GamesService::class);
        [$expiring] = $expireGames->create($expireUser->fresh(), $expirePlan, [
            'request_id' => (string) Str::uuid(),
            'study_plan_id' => $expirePlan->id,
            'mode' => 'free',
        ], 'detective');
        $future = Carbon::parse($expiring->deadline)->utc()->addSecond();
        $expireGames->expire($expireUser->id, $future);
        $expiring->refresh();
        $this->assertSame('failed', $expiring->status);
        $this->assertSame('timeout', $expiring->error);
        $this->assertSame('released', GameAdmission::query()->find($expiring->id)->status);
        $this->assertSame($expireGames->quota($expireUser->id, $future)['limit'], $expireGames->quota($expireUser->id, $future)['remaining']);

        [$sourceUser] = $this->user();
        $sourcePlan = $this->plan($sourceUser, 'A1');
        $sourcePlan->progress_day = 1;
        $sourcePlan->generated_plan = ['weekly_plan' => [[
            'week' => 1,
            'days' => [
                ['day' => 1, 'title' => 'Known', 'unit_id' => 'a1-unit-1'],
                ['day' => 2, 'title' => 'Future', 'unit_id' => 'a1-unit-1', 'objectives' => ['New content']],
                ['day' => 3, 'title' => 'Test', 'unit_id' => 'completion-test'],
            ],
        ]]];
        $sourcePlan->save();
        $known = Lesson::query()->create([
            'study_plan_id' => $sourcePlan->id,
            'title' => 'Known',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 1,
            'unit_id' => 'a1-unit-1',
            'content' => ['explanation' => 'Known grammar'],
            'is_completed' => true,
            'completed_at' => Carbon::now('UTC'),
        ]);
        Lesson::query()->create([
            'study_plan_id' => $sourcePlan->id,
            'title' => 'Future',
            'lesson_type' => 'grammar',
            'cefr_level' => 'A1',
            'week_number' => 1,
            'day_number' => 2,
            'unit_id' => 'a1-unit-1',
            'content' => ['explanation' => 'New content'],
            'is_completed' => false,
        ]);
        foreach (GamesService::TYPES as $type) {
            $context = app(GamesService::class)->sourceContext($sourcePlan->fresh(), 'prepare', $type);
            $this->assertSame('Future', $context['upcoming']['title']);
            $this->assertSame(['lesson:'.$known->id], array_column($context['sources'], 'source_id'));
            $this->assertStringNotContainsString('New content', json_encode($context['sources']));
            $this->assertSame(1, (int) $sourcePlan->fresh()->progress_day);
            $this->assertSame(2, Lesson::query()->where('study_plan_id', $sourcePlan->id)->count());
            $sourcePlan->progress_day = 2;
            $sourcePlan->save();
            $this->assertSame('noUpcoming', app(GamesService::class)->sourceContext($sourcePlan->fresh(), 'prepare', $type)['reason']);
            $sourcePlan->progress_day = 1;
            $sourcePlan->save();
        }

        [$catalogUser, $catalogHeaders] = $this->user();
        $this->api('GET', '/api/games/detective', [], $catalogHeaders)->assertStatus(404);
        $catalogPlan = $this->plan($catalogUser, 'A1');
        $catalogUser->ui_locale = 'fr';
        $catalogUser->save();
        $adapter->responder = fn () => '{"challenges":[]}';
        $catalog = $this->api('GET', '/api/games/detective', [], $catalogHeaders);
        $catalog->assertOk();
        $this->assertTrue($catalog->json('modes.free.available'));
        $this->assertFalse($catalog->json('modes.review.available'));
        $started = $this->api('POST', '/api/games/detective', [
            'request_id' => (string) Str::uuid(),
            'study_plan_id' => $catalogPlan->id,
            'mode' => 'free',
            'native_language' => 'de',
        ], $catalogHeaders);
        $started->assertStatus(202);
        $this->assertSame('es', $started->json('native_language'));
    }

    public function test_sentence_order_answers_identity_and_generation(): void
    {
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $session = $this->ready($user, $plan, 'sentence-order');
        $url = '/api/games/sessions/'.$session->id;
        $initial = $this->api('GET', $url, [], $headers);
        $initial->assertOk()->assertJsonPath('game_type', 'sentence-order');
        $this->assertEqualsCanonicalizing(
            ['index', 'clue', 'fragments', 'separator', 'order'],
            array_keys($initial->json('challenges.0')),
        );
        $this->assertArrayNotHasKey('context', $initial->json());
        foreach ([
            [['challenge' => 1, 'step' => 'order', 'order' => [1, 0, 2, 3]], 409],
            [['challenge' => 0, 'step' => 'detect', 'choice' => 0], 422],
            [['challenge' => 0, 'step' => 'order', 'order' => [0, 0, 2, 3]], 422],
            [['challenge' => 0, 'step' => 'order', 'order' => [0, 1, 2]], 422],
            [['challenge' => 0, 'step' => 'order', 'order' => [0, 1, 2, 8]], 422],
            [['challenge' => 0, 'step' => 'order', 'order' => [true, 0, 2, 3]], 422],
        ] as [$body, $status]) {
            $this->api('POST', $url.'/answer', $body, $headers)->assertStatus($status);
        }
        $body = ['challenge' => 0, 'step' => 'order', 'order' => [1, 2, 0, 3]];
        $result = $this->api('POST', $url.'/answer', $body, $headers);
        $result->assertOk();
        $this->assertTrue($result->json('challenges.0.correct'));
        $this->assertSame('I walk often to school on day 0.', $result->json('challenges.0.corrected_sentence'));
        $this->assertArrayNotHasKey('accepted_orders', $result->json('challenges.0'));
        $this->assertArrayNotHasKey('corrected_sentence', $result->json('challenges.1'));
        $this->assertSame($result->json(), $this->api('POST', $url.'/answer', $body, $headers)->json());
        $this->api('POST', $url.'/answer', ['challenge' => 0, 'step' => 'order', 'order' => [1, 0, 2, 3]], $headers)->assertStatus(409);
        $this->assertSame(0, (int) Progress::query()->where('study_plan_id', $plan->id)->value('xp_earned'));

        [$owner, $ownerHeaders] = $this->user();
        $ownerPlan = $this->plan($owner);
        $order = $this->ready($owner, $ownerPlan, 'sentence-order');
        $orderUrl = '/api/games/sessions/'.$order->id;
        $orderBody = ['challenge' => 0, 'step' => 'order', 'order' => [1, 0, 2, 3]];
        [, $adminHeaders] = $this->makeAdmin();
        $this->api('GET', $orderUrl)->assertStatus(401);
        $this->api('GET', $orderUrl, [], $adminHeaders)->assertStatus(404);
        $this->api('POST', $orderUrl.'/answer', $orderBody, $adminHeaders)->assertStatus(404);
        $this->api('POST', $orderUrl.'/abandon', [], $adminHeaders)->assertStatus(404);
        $detective = $this->ready($owner, $ownerPlan, 'detective');
        $this->api('POST', '/api/games/sessions/'.$detective->id.'/answer', $orderBody, $ownerHeaders)->assertStatus(422);
        $this->api('POST', $orderUrl.'/abandon', [], $ownerHeaders)->assertOk()->assertJsonPath('status', 'abandoned');
        $this->api('POST', $orderUrl.'/answer', $orderBody, $ownerHeaders)->assertStatus(409);
        $this->assertSame(0, ProgressReward::query()->where('user_id', $owner->id)->count());

        foreach ([[0, true, 15], [0, false, 5], [40, true, 5], [44, true, 1], [45, true, 0]] as [$prior, $correct, $expected]) {
            [$rewardUser] = $this->user();
            $rewardPlan = $this->plan($rewardUser);
            app(\App\Services\Learning\ProgressRewards::class)->award($rewardUser->id, $rewardPlan->id, 'games', 'prior', $prior, Carbon::now('UTC')->toDateString());
            $other = $this->secondPlan($rewardUser, 'fr-FR');
            $this->activate($rewardUser, 'fr-FR');
            $rewardSession = $this->ready($rewardUser, $rewardPlan, 'sentence-order');
            $submitted = $correct ? [1, 0, 2, 3] : [0, 1, 2, 3];
            $this->finishOrder(app(GamesService::class), $rewardUser->id, $rewardSession->id, $submitted);
            $rewardSession->refresh();
            $this->assertSame('completed', $rewardSession->status);
            $this->assertSame($expected, (int) $rewardSession->xp_earned);
            app(GamesService::class)->answer($rewardUser->id, $rewardSession->id, ['challenge' => 4, 'step' => 'order', 'order' => $submitted]);
            $progress = Progress::query()->where('study_plan_id', $rewardPlan->id)->first();
            $this->assertSame($prior + $expected, (int) $progress->xp_earned);
            $this->assertSame(1, (int) $progress->streak_day);
            $this->assertSame(0, (int) $progress->lessons_completed);
            $this->assertSame(0, (int) $progress->exercises_total);
            $this->assertSame([], $progress->skills ?? []);
            $this->assertNull(Progress::query()->where('study_plan_id', $other->id)->first());
            if (! $correct) {
                $output = app(GamesService::class)->output($rewardSession->fresh());
                $this->assertFalse($output['challenges'][0]['correct']);
                $this->assertSame($rewardSession->challenges[0]['sentence'], $output['challenges'][0]['corrected_sentence']);
            }
        }

        [$budgetUser] = $this->user();
        $budgetPlan = $this->plan($budgetUser);
        $budgetGames = app(GamesService::class);
        $budgetDetective = $this->ready($budgetUser, $budgetPlan, 'detective');
        $this->finishDetective($budgetGames, $budgetUser->id, $budgetDetective->id, true);
        $last = null;
        foreach ([true, true, false] as $correct) {
            $last = $this->ready($budgetUser, $budgetPlan, 'sentence-order');
            $this->finishOrder($budgetGames, $budgetUser->id, $last->id, $correct ? [1, 0, 2, 3] : [0, 1, 2, 3]);
        }
        $last->refresh();
        $this->assertSame(0, (int) $last->xp_earned);
        $this->assertSame(45, (int) Progress::query()->where('study_plan_id', $budgetPlan->id)->value('xp_earned'));
        $context = $budgetGames->sourceContext($budgetPlan, 'free', 'detective');
        $this->assertContains($last->challenges[0]['sentence'], $context['recent_sentences']);
        $this->assertContains(
            ['question' => $last->challenges[0]['clue'], 'correction' => $last->challenges[0]['sentence']],
            $context['mistakes'],
        );

        [$repeatUser] = $this->user();
        $repeat = $this->ready($repeatUser, $this->plan($repeatUser), 'sentence-order');
        $challenges = $repeat->challenges;
        $challenges[0] = [
            'sentence' => 'I know that that is true.',
            'clue' => 'Sabes que eso es cierto.',
            'fragments' => ['that', 'I know', 'that', 'is true.'],
            'separator' => ' ',
            'accepted_orders' => [[1, 0, 2, 3]],
            'explanation' => 'El primer that introduce la oración y el segundo es demostrativo.',
            'source_id' => 'lesson:1',
        ];
        $repeat->challenges = $challenges;
        $repeat->save();
        app(GamesService::class)->answer($repeatUser->id, $repeat->id, ['challenge' => 0, 'step' => 'order', 'order' => [1, 2, 0, 3]]);
        $repeat->refresh();
        $this->assertTrue($repeat->answers[0]['correct']);

        [$cjkUser] = $this->user();
        $cjk = $this->ready($cjkUser, $this->plan($cjkUser), 'sentence-order');
        $shuffled = app(GamesService::class)->shuffleOrderChallenge([
            'sentence' => '彼女は学生です。',
            'clue' => 'Construye la frase indicada.',
            'fragments' => ['です。', '彼女は', '学生'],
            'separator' => '',
            'accepted_orders' => [[1, 2, 0]],
            'explanation' => 'Respeta el orden natural de esta oración.',
            'source_id' => 'lesson:1',
        ]);
        $cjkChallenges = $cjk->challenges;
        $cjkChallenges[0] = $shuffled;
        $cjk->challenges = $cjkChallenges;
        $cjk->save();
        $played = app(GamesService::class)->answer($cjkUser->id, $cjk->id, [
            'challenge' => 0,
            'step' => 'order',
            'order' => $shuffled['accepted_orders'][0],
        ]);
        $this->assertSame('彼女は学生です。', $played['session']->answers[0]['sentence']);
        $this->assertSame('彼女は学生です。', app(GamesService::class)->output($played['session'])['challenges'][0]['corrected_sentence']);

        $this->separateGameIdentity();
        $this->orderCannotBypassQuota();
        $this->generationOutcomes('sentence-order');
    }

    public function test_vocabulary_pairs_answers_generation_and_context(): void
    {
        [$user, $headers] = $this->user();
        $session = $this->ready($user, $this->plan($user), 'vocabulary-pairs');
        $url = '/api/games/sessions/'.$session->id;
        $initial = $this->api('GET', $url, [], $headers);
        $initial->assertOk();
        foreach ($initial->json('challenges') as $challenge) {
            $this->assertEqualsCanonicalizing(['index', 'term', 'matched'], array_keys($challenge));
        }
        $this->assertSame([
            ['index' => 0, 'text' => 'casa'],
            ['index' => 1, 'text' => 'libro'],
            ['index' => 2, 'text' => 'gato'],
            ['index' => 3, 'text' => 'árbol'],
            ['index' => 4, 'text' => 'perro'],
        ], $initial->json('meanings'));
        $body = ['step' => 'match', 'attempt' => 0, 'challenge' => 0, 'choice' => 4];
        $wrong = $this->api('POST', $url.'/answer', $body, $headers);
        $wrong->assertOk();
        $this->assertFalse($wrong->json('attempts.0.correct'));
        $this->assertSame($initial->json('challenges'), $wrong->json('challenges'));
        $session->refresh();
        $this->assertTrue($session->answers[0]['assisted']);
        $this->assertTrue($session->answers[1]['assisted']);
        $this->assertSame($wrong->json(), $this->api('GET', $url, [], $headers)->json());
        $this->assertSame($wrong->json(), $this->api('POST', $url.'/answer', $body, $headers)->json());
        foreach ([['choice' => 2], ['challenge' => 1], ['attempt' => 2], ['attempt' => 1]] as $changed) {
            $this->api('POST', $url.'/answer', array_merge($body, $changed), $headers)->assertStatus(409);
        }
        $games = app(GamesService::class);
        $this->match($games, $user->id, $session, 1);
        $revealed = $games->output($session->fresh());
        $this->assertSame(4, $revealed['challenges'][1]['choice']);
        $this->assertTrue($revealed['challenges'][1]['assisted']);
        $this->assertArrayNotHasKey('sentence', $revealed['challenges'][1]);
        foreach ([4, 0, 3, 2] as $word) {
            $this->match($games, $user->id, $session, $word);
        }
        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(11, (int) $session->xp_earned);
        foreach ($games->output($session)['challenges'] as $challenge) {
            $this->assertArrayHasKey('sentence', $challenge);
            $this->assertArrayHasKey('translation', $challenge);
        }
        $this->match($games, $user->id, $session, 0, 4, 0);
        $this->assertSame(1, ProgressReward::query()->where('user_id', $user->id)->count());

        foreach ([[0, 15], [40, 5], [44, 1], [45, 0]] as [$prior, $expected]) {
            [$rewardUser] = $this->user();
            $rewardPlan = $this->plan($rewardUser);
            app(\App\Services\Learning\ProgressRewards::class)->award($rewardUser->id, $rewardPlan->id, 'games', 'prior', $prior, Carbon::now('UTC')->toDateString());
            $other = $this->secondPlan($rewardUser, 'fr-FR');
            $this->activate($rewardUser, 'fr-FR');
            $rewardSession = $this->ready($rewardUser, $rewardPlan, 'vocabulary-pairs');
            $rewardGames = app(GamesService::class);
            for ($i = 4; $i >= 0; $i--) {
                $this->match($rewardGames, $rewardUser->id, $rewardSession, $i);
            }
            $rewardSession->refresh();
            $this->assertSame($expected, (int) $rewardSession->xp_earned);
            $this->match($rewardGames, $rewardUser->id, $rewardSession, 0, null, 4);
            $progress = Progress::query()->where('study_plan_id', $rewardPlan->id)->first();
            $this->assertSame($prior + $expected, (int) $progress->xp_earned);
            $this->assertSame(1, (int) $progress->streak_day);
            $this->assertSame(0, (int) $progress->lessons_completed);
            $this->assertSame(0, (int) $progress->exercises_total);
            $this->assertSame([], $progress->skills ?? []);
            $this->assertNull(Progress::query()->where('study_plan_id', $other->id)->first());
        }

        [$comboUser] = $this->user();
        $combo = $this->ready($comboUser, $this->plan($comboUser), 'vocabulary-pairs');
        $comboGames = app(GamesService::class);
        for ($i = 0; $i < 5; $i++) {
            for ($choice = 0; $choice < 5; $choice++) {
                if ($choice !== $combo->challenges[$i]['choice_index']) {
                    $this->match($comboGames, $comboUser->id, $combo, $i, $choice);
                }
            }
        }
        $this->assertCount(20, $comboGames->output($combo->fresh())['attempts']);
        for ($i = 0; $i < 5; $i++) {
            $this->match($comboGames, $comboUser->id, $combo, $i);
        }
        $combo->refresh();
        $this->assertSame('completed', $combo->status);
        $this->assertSame(5, (int) $combo->xp_earned);
        $this->assertCount(25, $comboGames->output($combo)['attempts']);

        [$authUser, $authHeaders] = $this->user();
        $authPlan = $this->plan($authUser);
        $authSession = $this->ready($authUser, $authPlan, 'vocabulary-pairs');
        $authUrl = '/api/games/sessions/'.$authSession->id;
        $authBody = ['step' => 'match', 'attempt' => 0, 'challenge' => 0, 'choice' => 2];
        [, $foreignHeaders] = $this->makeAdmin();
        $this->api('GET', $authUrl)->assertStatus(401);
        $this->api('GET', $authUrl, [], $foreignHeaders)->assertStatus(404);
        $this->api('POST', $authUrl.'/answer', $authBody, $foreignHeaders)->assertStatus(404);
        $this->api('POST', $authUrl.'/abandon', [], $foreignHeaders)->assertStatus(404);
        foreach ([
            ['attempt' => true],
            ['attempt' => 25],
            ['choice' => 5],
            ['challenge' => -1],
            ['choice' => '2'],
        ] as $invalid) {
            $this->api('POST', $authUrl.'/answer', array_merge($authBody, $invalid), $authHeaders)->assertStatus(422);
        }
        foreach ([
            ['step' => 'detect', 'challenge' => 0, 'choice' => 2],
            ['step' => 'order', 'challenge' => 0, 'order' => [0, 1, 2]],
        ] as $invalid) {
            $this->api('POST', $authUrl.'/answer', $invalid, $authHeaders)->assertStatus(422);
        }
        foreach (['detective', 'sentence-order'] as $type) {
            $otherSession = $this->ready($authUser, $authPlan, $type);
            $this->api('POST', '/api/games/sessions/'.$otherSession->id.'/answer', $authBody, $authHeaders)->assertStatus(422);
        }
        $authGames = app(GamesService::class);
        $this->match($authGames, $authUser->id, $authSession, 0);
        foreach ([[0, 4], [1, 2]] as [$word, $choice]) {
            try {
                $this->match($authGames, $authUser->id, $authSession, $word, $choice);
                $this->fail('matched pairs must stay closed');
            } catch (ApiException $exception) {
                $this->assertSame('pair_already_matched', $exception->detail);
            }
        }
        $this->api('POST', $authUrl.'/abandon', [], $authHeaders)->assertOk()->assertJsonPath('status', 'abandoned');
        $this->api('POST', $authUrl.'/answer', $authBody, $authHeaders)->assertStatus(409);
        $this->assertSame(0, ProgressReward::query()->where('user_id', $authUser->id)->count());

        $this->generationOutcomes('vocabulary-pairs');
        $this->thirdGameIdentity();

        [$mistakeUser] = $this->user();
        $mistakePlan = $this->plan($mistakeUser);
        $mistake = $this->ready($mistakeUser, $mistakePlan, 'vocabulary-pairs');
        app(GamesService::class)->answer($mistakeUser->id, $mistake->id, ['step' => 'match', 'attempt' => 0, 'challenge' => 0, 'choice' => 4]);
        $mistake->status = 'abandoned';
        $mistake->save();
        foreach (GamesService::TYPES as $type) {
            $context = app(GamesService::class)->sourceContext($mistakePlan, 'free', $type);
            $this->assertContains(['question' => 'cat', 'correction' => 'gato'], $context['mistakes']);
            $this->assertContains(['question' => 'dog', 'correction' => 'perro'], $context['mistakes']);
            $this->assertContains($mistake->challenges[0]['sentence'], $context['recent_sentences']);
        }
        $this->assertSame([], app(GamesService::class)->sourceContext($mistakePlan, 'review', 'vocabulary-pairs')['sources']);
    }

    private function reusedRequest(bool $complete, bool $hasPlan): void
    {
        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 3]);
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $this->acceptGeneration($this->bindAdapter());
        $original = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'free'];
        $this->api('POST', '/api/games/detective', $original, $headers)->assertStatus(202);
        $session = GameSession::query()->find($original['request_id']);
        $this->assertSame('ready', $session->status);
        $reused = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'review'];
        $bound = $this->api('POST', '/api/games/detective', $reused, $headers);
        $bound->assertStatus(202);
        $this->assertSame($session->id, $bound->json('id'));
        $path = '/api/games/sessions/'.$reused['request_id'];
        $recovered = $this->api('GET', $path, [], $headers);
        $recovered->assertOk();
        $this->assertSame($session->id, $recovered->json('id'));
        $this->assertArrayNotHasKey('options', $recovered->json('challenges.0'));
        [, $foreignHeaders] = $this->user();
        $this->api('GET', $path, [], $foreignHeaders)->assertStatus(404);
        $this->api('POST', '/api/games/detective', $reused, $foreignHeaders)->assertStatus(404);
        $this->api('POST', '/api/games/detective', array_merge($reused, ['mode' => 'free']), $headers)->assertStatus(409);
        $this->api('POST', '/api/games/detective', array_merge($reused, ['study_plan_id' => $plan->id + 100]), $headers)->assertStatus(409);
        $answered = $this->api('POST', $path.'/answer', ['challenge' => 0, 'step' => 'detect', 'choice' => 1], $headers);
        $answered->assertOk();
        $this->assertSame($session->id, $answered->json('id'));
        if ($complete) {
            app(GamesService::class)->answer($user->id, $session->id, ['challenge' => 0, 'step' => 'correct', 'choice' => 0]);
            for ($i = 1; $i < 5; $i++) {
                app(GamesService::class)->answer($user->id, $session->id, ['challenge' => $i, 'step' => 'detect', 'choice' => 1]);
                app(GamesService::class)->answer($user->id, $session->id, ['challenge' => $i, 'step' => 'correct', 'choice' => 0]);
            }
            $terminal = 'completed';
        } else {
            $this->api('POST', $path.'/abandon', [], $headers)->assertOk()->assertJsonPath('status', 'abandoned');
            $terminal = 'abandoned';
        }
        if (! $hasPlan) {
            $this->activate($user, 'ja-JP');
        }
        foreach ([$original, $reused] as $body) {
            $retry = $this->api('POST', '/api/games/detective', $body, $headers);
            $retry->assertStatus(202);
            $this->assertSame($session->id, $retry->json('id'));
            $this->assertSame($terminal, $retry->json('status'));
        }
        foreach ([['mode' => 'free'], ['study_plan_id' => $plan->id + 100]] as $changed) {
            $this->api('POST', '/api/games/detective', array_merge($reused, $changed), $headers)
                ->assertStatus(409)
                ->assertJsonPath('detail', 'request_conflict');
        }
        if (! $hasPlan) {
            $this->api('POST', '/api/games/detective', [
                'request_id' => (string) Str::uuid(),
                'study_plan_id' => $plan->id,
                'mode' => 'free',
            ], $headers)->assertStatus(404)->assertJsonPath('detail', 'No active study plan found');
        }
        $this->assertSame(1, GameSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, GameAdmission::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, GameRequest::query()->where('user_id', $user->id)->count());
    }

    private function separateGameIdentity(): void
    {
        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 3]);
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $adapter = $this->bindAdapter();
        $this->acceptGeneration($adapter);
        $body = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'free'];
        $detective = $this->api('POST', '/api/games/detective', $body, $headers);
        $detective->assertStatus(202);
        $this->api('POST', '/api/games/sentence-order', $body, $headers)->assertStatus(409);
        $original = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'free'];
        $created = $this->api('POST', '/api/games/sentence-order', $original, $headers);
        $created->assertStatus(202);
        $this->assertNotSame($detective->json('id'), $created->json('id'));
        $reused = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'review'];
        $this->assertSame($original['request_id'], $this->api('POST', '/api/games/sentence-order', $reused, $headers)->json('id'));
        foreach (['detective' => $body, 'sentence-order' => $original] as $kind => $expected) {
            $catalog = $this->api('GET', '/api/games/'.$kind, [], $headers);
            $catalog->assertOk();
            $this->assertSame(1, $catalog->json('total'));
            $this->assertSame($expected['request_id'], $catalog->json('history.0.id'));
            $this->assertSame(1, $catalog->json('quota.remaining'));
        }
        $this->api('GET', '/api/games/unknown', [], $headers)->assertStatus(422);
        $session = GameSession::query()->find($original['request_id']);
        $session->status = 'abandoned';
        $session->save();
        $this->activate($user, 'ja-JP');
        foreach ([$original, $reused] as $request) {
            $result = $this->api('POST', '/api/games/sentence-order', $request, $headers);
            $result->assertStatus(202);
            $this->assertSame($session->id, $result->json('id'));
            $this->api('POST', '/api/games/detective', $request, $headers)->assertStatus(409);
        }
        $session->delete();
        $this->assertSame('sentence-order', GameRequest::query()->find($reused['request_id'])->game_type);
        $this->api('POST', '/api/games/sentence-order', $reused, $headers)->assertStatus(409);
        $this->api('GET', '/api/games/sessions/'.$reused['request_id'], [], $headers)->assertStatus(404);
        $this->assertCount(4, $adapter->requests);
    }

    private function orderCannotBypassQuota(): void
    {
        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 1]);
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $this->acceptGeneration($this->bindAdapter());
        $body = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'free'];
        $this->api('POST', '/api/games/detective', $body, $headers)->assertStatus(202);
        $this->api('POST', '/api/games/sentence-order', [
            'request_id' => (string) Str::uuid(),
            'study_plan_id' => $plan->id,
            'mode' => 'free',
        ], $headers)->assertStatus(402);
        $this->assertSame(1, GameAdmission::query()->where('user_id', $user->id)->count());
    }

    private function thirdGameIdentity(): void
    {
        config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 3]);
        [$user, $headers] = $this->user();
        $plan = $this->plan($user);
        $this->acceptGeneration($this->bindAdapter());
        $requests = [];
        foreach (GamesService::TYPES as $type) {
            $requests[$type] = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'free'];
            $this->api('POST', '/api/games/'.$type, $requests[$type], $headers)->assertStatus(202);
        }
        $body = $requests['vocabulary-pairs'];
        $reused = ['request_id' => (string) Str::uuid(), 'study_plan_id' => $plan->id, 'mode' => 'review'];
        $this->assertSame($body['request_id'], $this->api('POST', '/api/games/vocabulary-pairs', $reused, $headers)->json('id'));
        foreach (array_keys($requests) as $type) {
            $catalog = $this->api('GET', '/api/games/'.$type, [], $headers);
            $this->assertSame(1, $catalog->json('total'));
            $this->assertSame($requests[$type]['request_id'], $catalog->json('history.0.id'));
            $this->assertSame(0, $catalog->json('quota.remaining'));
        }
        $session = GameSession::query()->find($body['request_id']);
        $session->status = 'abandoned';
        $session->save();
        $this->api('POST', '/api/games/vocabulary-pairs', [
            'request_id' => (string) Str::uuid(),
            'study_plan_id' => $plan->id,
            'mode' => 'free',
        ], $headers)->assertStatus(402);
        $this->activate($user, 'ja-JP');
        foreach ([$body, $reused] as $original) {
            $this->assertSame($session->id, $this->api('POST', '/api/games/vocabulary-pairs', $original, $headers)->json('id'));
            $this->api('POST', '/api/games/detective', $original, $headers)->assertStatus(409);
        }
        $this->assertSame($session->id, $this->api('GET', '/api/games/sessions/'.$reused['request_id'], [], $headers)->json('id'));
    }

    private function generationOutcomes(string $type): void
    {
        foreach (['valid', 'rejected', 'timeout', 'wrong_source'] as $outcome) {
            config(['freelingo.stripe_enabled' => true, 'freelingo.freemium_games_daily' => 3]);
            [$user] = $this->user();
            $plan = $this->plan($user);
            $adapter = $this->bindAdapter();
            $games = app(GamesService::class);
            [$session] = $games->create($user->fresh(), $plan, [
                'request_id' => (string) Str::uuid(),
                'study_plan_id' => $plan->id,
                'mode' => 'free',
            ], $type);
            if ($type === 'vocabulary-pairs') {
                foreach ($session->context['sources'] as $source) {
                    $this->assertStringStartsWith('vocabulary:', $source['source_id']);
                }
            }
            $source = $outcome === 'wrong_source'
                ? ($type === 'vocabulary-pairs' ? 'foreign' : 'lesson:foreign')
                : $session->context['sources'][0]['source_id'];
            $this->scriptOutcome($adapter, $type, $outcome, $source);
            $games->generate($session->id);
            $session->refresh();
            $this->assertSame($outcome === 'valid' ? 'ready' : 'failed', $session->status);
            $this->assertSame($outcome === 'valid' ? 'consumed' : 'released', GameAdmission::query()->find($session->id)->status);
            $expectedCalls = match ($outcome) {
                'valid' => 2,
                'rejected' => 4,
                'timeout' => 1,
                'wrong_source' => 2,
            };
            $this->assertCount($expectedCalls, $adapter->requests);
            if ($outcome === 'timeout') {
                $this->assertSame('timeout', $session->error);
            }
            if ($outcome !== 'valid') {
                $this->assertSame([], $session->challenges ?? []);
                continue;
            }
            if ($type === 'sentence-order') {
                $this->assertCount(5, $session->challenges);
                foreach ($session->challenges as $challenge) {
                    $accepted = [];
                    foreach ($challenge['accepted_orders'] as $order) {
                        $accepted[] = implode($challenge['separator'], array_map(fn (int $index) => $challenge['fragments'][$index], $order));
                    }
                    $this->assertContains($challenge['sentence'], $accepted);
                    $this->assertNotContains(implode($challenge['separator'], $challenge['fragments']), $accepted);
                }
            } elseif ($type === 'vocabulary-pairs') {
                $indexes = array_map('intval', array_column($session->challenges, 'choice_index'));
                sort($indexes);
                $this->assertSame(range(0, 4), $indexes);
                $terms = [];
                foreach ($session->challenges as $challenge) {
                    $terms[] = [$challenge['term'], $challenge['meaning']];
                }
                $expected = [];
                foreach ($this->pairChallenges($source) as $challenge) {
                    $expected[] = [$challenge['term'], $challenge['meaning']];
                }
                usort($terms, fn (array $left, array $right) => $left <=> $right);
                usort($expected, fn (array $left, array $right) => $left <=> $right);
                $this->assertSame($expected, $terms);
                $public = $games->output($session);
                foreach ($public['challenges'] as $challenge) {
                    $this->assertEqualsCanonicalizing(['index', 'term', 'matched'], array_keys($challenge));
                }
                for ($i = 0; $i < 5; $i++) {
                    $this->match($games, $user->id, $session, $i);
                }
                $session->refresh();
                $this->assertSame(15, (int) $session->xp_earned);
            } else {
                $this->assertCount(5, $session->challenges);
            }
        }
    }

    private function scriptOutcome(LlmAdapter $adapter, string $type, string $outcome, string $source): void
    {
        $payload = json_encode(['challenges' => match ($type) {
            'sentence-order' => $this->orderChallenges($source),
            'vocabulary-pairs' => $this->pairChallenges($source),
            default => $this->detectiveChallenges($source),
        }], JSON_UNESCAPED_UNICODE);
        $review = json_encode(['valid' => $outcome === 'valid', 'reason' => 'ambiguous']);
        $adapter->responder = function (array $messages) use ($outcome, $payload, $review) {
            if ($outcome === 'timeout') {
                throw new \RuntimeException('timeout');
            }
            $prompt = (string) ($messages[0]['content'] ?? '');
            if ($outcome === 'wrong_source') {
                return $payload;
            }

            return str_contains($prompt, 'Independently audit') ? $review : $payload;
        };
    }

    private function acceptGeneration(LlmAdapter $adapter): void
    {
        $adapter->responder = function (array $messages) {
            $prompt = (string) ($messages[0]['content'] ?? '');
            if (str_contains($prompt, 'Independently audit')) {
                return json_encode(['valid' => true, 'reason' => 'ok']);
            }
            $session = GameSession::query()->where('status', 'generating')->orderByDesc('created_at')->first();
            $source = $session->context['sources'][0]['source_id'] ?? 'grammar:present-simple';
            $challenges = match ($session->game_type ?? 'detective') {
                'sentence-order' => $this->orderChallenges($source),
                'vocabulary-pairs' => $this->pairChallenges($source),
                default => $this->detectiveChallenges($source),
            };

            return json_encode(['challenges' => $challenges], JSON_UNESCAPED_UNICODE);
        };
    }

    private function bindAdapter(): LlmAdapter
    {
        $adapter = new LlmAdapter('ollama', 'test');
        $this->app->instance(LlmAdapter::class, $adapter);

        return $adapter;
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function user(): array
    {
        $this->seq++;

        return $this->makeUser([
            'username' => 'player'.$this->seq,
            'email' => 'player'.$this->seq.'@example.com',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function token(array $headers): string
    {
        return substr($headers['Authorization'], 7);
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

    private function secondPlan(User $user, string $language, string $level = 'A1'): StudyPlan
    {
        $row = UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => $language,
            'is_active' => false,
            'created_at' => Carbon::now('UTC'),
        ]);

        return StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $row->id,
            'cefr_level' => $level,
            'target_language' => $language,
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

    private function activate(User $user, string $language): void
    {
        UserLanguage::query()->where('user_id', $user->id)->update(['is_active' => false]);
        $row = UserLanguage::query()->where('user_id', $user->id)->where('target_language', $language)->first();
        if (! $row) {
            UserLanguage::query()->create([
                'user_id' => $user->id,
                'target_language' => $language,
                'is_active' => true,
                'created_at' => Carbon::now('UTC'),
            ]);
        } else {
            $row->is_active = true;
            $row->save();
        }
        $user->target_language = $language;
        $user->save();
    }

    private function ready(User $user, StudyPlan $plan, string $type): GameSession
    {
        $challenges = match ($type) {
            'sentence-order' => $this->orderChallenges('grammar:present-simple'),
            'vocabulary-pairs' => $this->pairChallenges('vocabulary:test'),
            default => $this->detectiveChallenges('grammar:present-simple'),
        };
        if ($type === 'vocabulary-pairs') {
            foreach ($challenges as $index => $challenge) {
                $challenges[$index]['choice_index'] = [2, 4, 1, 0, 3][$index];
            }
        }

        return GameSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'mode' => 'free',
            'game_type' => $type,
            'target_language' => $plan->target_language,
            'native_language' => $user->native_language,
            'level' => $plan->cefr_level,
            'status' => 'ready',
            'context' => ['sources' => [['source_id' => 'grammar:present-simple']]],
            'challenges' => $challenges,
            'answers' => array_fill(0, 5, []),
            'created_at' => Carbon::now('UTC'),
            'deadline' => Carbon::now('UTC')->addMinutes(10),
            'xp_earned' => 0,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectiveChallenges(string $source): array
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'sentence' => "She go to school on day {$i}.",
                'fragments' => ['She ', 'go ', "to school on day {$i}."],
                'error_index' => 1,
                'options' => ['goes ', 'going ', 'gone '],
                'correct_index' => 0,
                'corrected_sentence' => "She goes to school on day {$i}.",
                'explanation' => 'Con she, el presente simple lleva -s.',
                'source_id' => $source,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderChallenges(string $source): array
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'sentence' => "I often walk to school on day {$i}.",
                'clue' => "Expresa que a menudo vas andando al colegio el día {$i}.",
                'fragments' => ['often', 'I', 'walk', "to school on day {$i}."],
                'separator' => ' ',
                'accepted_orders' => [[1, 0, 2, 3], [1, 2, 0, 3]],
                'explanation' => 'El adverbio often puede colocarse antes o después de walk.',
                'source_id' => $source,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pairChallenges(string $source): array
    {
        $rows = [];
        foreach ([['cat', 'gato'], ['dog', 'perro'], ['book', 'libro'], ['house', 'casa'], ['tree', 'árbol']] as [$term, $meaning]) {
            $rows[] = [
                'term' => $term,
                'meaning' => $meaning,
                'sentence' => "This is a {$term}.",
                'translation' => "Esto es: {$meaning}.",
                'source_id' => $source,
            ];
        }

        return $rows;
    }

    private function finishDetective(GamesService $games, int $userId, string $sessionId, bool $correct): void
    {
        for ($i = 0; $i < 5; $i++) {
            $games->answer($userId, $sessionId, ['challenge' => $i, 'step' => 'detect', 'choice' => $correct ? 1 : 0]);
            $games->answer($userId, $sessionId, ['challenge' => $i, 'step' => 'correct', 'choice' => $correct ? 0 : 1]);
        }
    }

    /**
     * @param  list<int>  $order
     */
    private function finishOrder(GamesService $games, int $userId, string $sessionId, array $order): void
    {
        for ($i = 0; $i < 5; $i++) {
            $games->answer($userId, $sessionId, ['challenge' => $i, 'step' => 'order', 'order' => $order]);
        }
    }

    private function match(GamesService $games, int $userId, GameSession $session, int $word, ?int $choice = null, ?int $attempt = null): GameSession
    {
        $session->refresh();
        $result = $games->answer($userId, $session->id, [
            'step' => 'match',
            'challenge' => $word,
            'choice' => $choice ?? $session->challenges[$word]['choice_index'],
            'attempt' => $attempt ?? count($games->output($session)['attempts']),
        ]);

        return $result['session'];
    }
}
