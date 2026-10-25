<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Practice\SpeechGateway;
use App\Services\Practice\TourAudio;
use App\Services\Speech\KokoroTtsService;
use App\Services\Speech\OpenAiSttService;
use App\Services\Speech\OpenAiTtsService;
use App\Services\Speech\SpeechTimeout;
use App\Services\Speech\WhisperSttService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class SpeechExtraTest extends TestCase
{
    private const TOUR_TEXT = 'Texto del tour enviado desde la interfaz.';

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function recognitionLanguages(): array
    {
        return [
            ['en-US', 'en'],
            ['en-GB', 'en'],
            ['de-DE', 'de'],
            ['es-ES', 'es'],
            ['fr-FR', 'fr'],
            ['it-IT', 'it'],
            ['ja-JP', 'ja'],
            ['ko-KR', 'ko'],
            ['pt-PT', 'pt'],
            ['zh-CN', 'zh'],
        ];
    }

    #[DataProvider('recognitionLanguages')]
    public function test_stt_uses_owned_study_plan_language(string $targetLanguage, string $expectedLanguage): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $this->plan($user, $targetLanguage);
        $seen = null;
        $stt = new WhisperSttService('http://whisper.test');
        $stt->transport = function (string $bytes, array $payload) use (&$seen) {
            $seen = [$bytes, $payload];

            return 'transcribed text';
        };
        $this->bind(null, $stt, 'local', 'local');

        $response = $this->postStt($headers, ['study_plan_id' => (string) $plan->id], $this->wav('audio-bytes'));

        $response->assertOk();
        $this->assertSame('transcribed text', $response->json('text'));
        $this->assertSame('audio-bytes', $seen[0]);
        $this->assertSame('recording.wav', $seen[1]['filename']);
        $this->assertSame('audio/wav', $seen[1]['mime']);
        $this->assertSame($expectedLanguage, $seen[1]['params']['language']);
    }

    public function test_stt_requires_study_plan_id(): void
    {
        [, $headers] = $this->makeUser();
        $stt = new WhisperSttService('http://whisper.test');
        $stt->transport = fn () => 'transcribed text';
        $this->bind(null, $stt, 'local', 'local');

        $response = $this->postStt($headers, [], $this->wav());

        $response->assertStatus(422);
        $this->assertSame([], $stt->requests);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidStudyPlanIds(): array
    {
        return [['0'], ['2147483648'], ['not-a-number']];
    }

    #[DataProvider('invalidStudyPlanIds')]
    public function test_stt_rejects_invalid_study_plan_id(string $studyPlanId): void
    {
        [, $headers] = $this->makeUser();
        $stt = new WhisperSttService('http://whisper.test');
        $stt->transport = fn () => 'transcribed text';
        $this->bind(null, $stt, 'local', 'local');

        $response = $this->postStt($headers, ['study_plan_id' => $studyPlanId], $this->wav());

        $response->assertStatus(422);
        $this->assertSame([], $stt->requests);
    }

    public function test_stt_rejects_study_plan_owned_by_another_user(): void
    {
        [, $headers] = $this->makeUser();
        [$other] = $this->makeAdmin();
        $plan = $this->plan($other, 'it-IT');
        $stt = new WhisperSttService('http://whisper.test');
        $stt->transport = fn () => 'transcribed text';
        $this->bind(null, $stt, 'local', 'local');

        $response = $this->postStt($headers, ['study_plan_id' => (string) $plan->id], $this->wav());

        $response->assertStatus(404);
        $this->assertSame(['detail' => 'Study plan not found'], $response->json());
        $this->assertSame([], $stt->requests);
    }

    public function test_openai_stt_forwards_required_language(): void
    {
        $seen = null;
        $service = new OpenAiSttService('test-key', 'whisper-1');
        $service->transport = function (string $bytes, array $payload) use (&$seen) {
            $seen = [$bytes, $payload];

            return ' Credo che abbiano poco tempo. ';
        };

        $text = $service->transcribe('wav-bytes', 'recording.wav', 'audio/wav', 'it');

        $this->assertSame('Credo che abbiano poco tempo.', $text);
        $this->assertSame('whisper-1', $seen[1]['model']);
        $this->assertSame('it', $seen[1]['language']);
        $this->assertSame(60.0, $seen[1]['timeout']);
        $this->assertSame('recording.wav', $seen[1]['filename']);
        $this->assertSame('wav-bytes', $seen[0]);
        $this->assertSame('audio/wav', $seen[1]['mime']);
    }

    public function test_local_stt_forwards_required_language(): void
    {
        $seen = null;
        $service = new WhisperSttService('http://whisper:9000');
        $service->transport = function (string $bytes, array $payload) use (&$seen) {
            $seen = [$bytes, $payload];

            return ' Ich spreche Deutsch. ';
        };

        $text = $service->transcribe('wav-bytes', 'recording.wav', 'audio/wav', 'de');

        $this->assertSame('Ich spreche Deutsch.', $text);
        $this->assertSame('http://whisper:9000/asr', $seen[1]['url']);
        $this->assertSame(['output' => 'json', 'language' => 'de', 'task' => 'transcribe'], $seen[1]['params']);
        $this->assertSame('recording.wav', $seen[1]['filename']);
        $this->assertSame('wav-bytes', $seen[0]);
        $this->assertSame('audio/wav', $seen[1]['mime']);
        $this->assertSame(60.0, $seen[1]['timeout']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function legacyTtsModels(): array
    {
        return [['tts-1'], ['tts-1-hd'], ['custom-tts']];
    }

    #[DataProvider('legacyTtsModels')]
    public function test_other_models_keep_the_original_request(string $model): void
    {
        $service = $this->openai($model);
        $this->assertSame('ID3new-audio', $service->synthesize('Bonjour.', 'coral', 'fr-FR'));
        $this->assertSame([
            'model' => $model,
            'voice' => 'coral',
            'input' => 'Bonjour.',
            'response_format' => 'mp3',
            'speed' => 1.0,
        ], $service->requests[0]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function capableSpeech(): array
    {
        $languages = [
            ['en-GB', 'British English'],
            ['en-US', 'American English'],
            ['es', 'Spanish from Spain'],
            ['es-ES', 'Spanish from Spain'],
            ['pt-PT', 'European Portuguese from Portugal'],
            ['fr', 'French from France'],
            ['de-DE', 'Standard German from Germany'],
            ['it-IT', 'Standard Italian from Italy'],
            ['ja-JP', 'Standard Japanese from Japan'],
            ['ko-KR', 'Standard Korean from South Korea'],
            ['zh-CN', 'Standard Mandarin Chinese from Mainland China'],
            ['pl', 'Polish from Poland'],
            ['nl', 'Dutch from the Netherlands'],
            ['ro', 'Romanian from Romania'],
            ['ru', 'Russian from Russia'],
            ['tr', 'Turkish from Turkey'],
            ['sv', 'Swedish from Sweden'],
            ['da', 'Danish from Denmark'],
            ['fi', 'Finnish from Finland'],
            ['hr', 'Croatian from Croatia'],
        ];
        $cases = [];
        foreach (['gpt-4o-mini-tts', 'gpt-4o-mini-tts-2025-12-15'] as $model) {
            foreach ($languages as [$language, $expected]) {
                $cases[] = [$model, $language, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('capableSpeech')]
    public function test_capable_models_receive_the_requested_language(string $model, string $language, string $expected): void
    {
        $service = $this->openai($model);
        $service->synthesize('Text to read.', language: $language);
        $payload = $service->requests[0];
        $this->assertSame($model, $payload['model']);
        $this->assertSame('Text to read.', $payload['input']);
        $this->assertStringContainsString("The primary language is {$expected};", $payload['instructions']);
    }

    public function test_generic_tts_also_receives_multilingual_instructions(): void
    {
        $service = $this->openai();
        $service->synthesize('Bonjour. Hola. Hello.');
        $instructions = $service->requests[0]['instructions'];
        $this->assertStringContainsString('Identify the language of each passage', $instructions);
        $this->assertStringContainsString('do not translate', $instructions);
        $this->assertStringContainsString('When the text switches languages, switch pronunciation naturally', $instructions);
        $this->assertStringNotContainsString('British', $instructions);
        $this->assertStringNotContainsString('Spanish from Spain', $instructions);
        $this->assertStringNotContainsString('Portuguese from Portugal', $instructions);
    }

    public function test_cache_key_changes_with_instructions_only_for_capable_models(): void
    {
        $service = $this->openai();
        $modern = $service->getCacheKey('Bonjour.', language: 'fr');
        $service->model = 'tts-1';
        $legacy = $service->getCacheKey('Bonjour.', language: 'fr');
        $service->instructionSource = fn (?string $language) => 'Revised pronunciation';
        $this->assertSame($legacy, $service->getCacheKey('Bonjour.', language: 'fr'));
        $service->model = 'gpt-4o-mini-tts';
        $this->assertNotSame($modern, $service->getCacheKey('Bonjour.', language: 'fr'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function previewModels(): array
    {
        return [['tts-1'], ['gpt-4o-mini-tts']];
    }

    #[DataProvider('previewModels')]
    public function test_previews_refresh_only_instruction_capable_audio(string $model): void
    {
        [, $headers] = $this->makeUser();
        $directory = $this->tempDir();
        config(['freelingo.tts_previews_path' => $directory]);
        file_put_contents($directory.'/nova.mp3', 'ID3legacy');
        $service = $this->openai($model);
        $this->bind($service, null, 'openai', 'local');

        $first = $this->api('GET', '/api/tts/preview/nova', [], $headers);
        $second = $this->api('GET', '/api/tts/preview/nova', [], $headers);

        $first->assertOk();
        $second->assertOk();
        $expected = hash('sha256', $model === 'tts-1' ? 'ID3legacy' : 'ID3new-audio');
        $this->assertSame($expected, $this->fileHash($first));
        $this->assertSame($this->fileHash($first), $this->fileHash($second));
        $this->assertCount($model === 'tts-1' ? 0 : 1, $service->requests);
    }

    #[DataProvider('previewModels')]
    public function test_phrasebook_refreshes_capable_audio_and_preserves_language(string $model): void
    {
        [, $headers] = $this->makeUser();
        $directory = $this->tempDir();
        config(['freelingo.audio_storage_path' => $directory]);
        $category = $this->api('GET', '/api/phrasebook?language=es-ES', [], $headers)->json('categories.0');
        $text = $category['phrases'][0]['text'];
        $key = substr(hash('sha256', "es-ES:{$category['id']}:0:{$text}"), 0, 16);
        $folder = $directory.'/phrasebook/es';
        mkdir($folder, 0777, true);
        file_put_contents($folder.'/'.$key.'.mp3', 'ID3legacy');
        $service = $this->openai($model);
        $this->bind($service, null, 'openai', 'local');

        $url = '/api/phrasebook/audio/'.$category['id'].'/0?language=es-ES';
        $first = $this->api('GET', $url, [], $headers);
        $second = $this->api('GET', $url, [], $headers);

        $first->assertOk();
        $second->assertOk();
        $expected = hash('sha256', $model === 'tts-1' ? 'ID3legacy' : 'ID3new-audio');
        $this->assertSame($expected, $this->fileHash($first));
        $this->assertSame($this->fileHash($first), $this->fileHash($second));
        $this->assertSame('no-store', $first->headers->get('cache-control'));
        $this->assertSame('no-store', $second->headers->get('cache-control'));
        if ($model === 'gpt-4o-mini-tts') {
            $this->assertCount(1, $service->requests);
            $this->assertStringContainsString('The primary language is Spanish from Spain;', $service->requests[0]['instructions']);
        } else {
            $this->assertSame([], $service->requests);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function planLanguages(): array
    {
        return [
            ['en-US', 'American English'],
            ['en-GB', 'British English'],
            ['es-ES', 'Spanish from Spain'],
        ];
    }

    #[DataProvider('planLanguages')]
    public function test_tts_endpoint_uses_the_owned_plan_instead_of_active_language(string $language, string $expected): void
    {
        [$user, $headers] = $this->makeUser();
        $user->target_language = 'fr-FR';
        $user->save();
        $plan = $this->plan($user, $language, false);
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', [
            'text' => 'Read this.',
            'study_plan_id' => $plan->id,
            'language' => 'fr-FR',
        ], $headers);

        $response->assertOk();
        $this->assertStringContainsString(
            "The primary language is {$expected};",
            $service->requests[0]['instructions'],
        );
    }

    /**
     * @return list<array{0: bool}>
     */
    public static function withPlan(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('withPlan')]
    public function test_tts_endpoint_uses_conversation_context(bool $withPlan): void
    {
        [$user, $headers] = $this->makeUser();
        $plan = $withPlan ? $this->plan($user, 'en-US') : null;
        $conversation = $this->conversation($user, $plan, $withPlan ? 'fr-FR' : 'en-US');
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', [
            'text' => 'Hello.',
            'conversation_id' => $conversation->id,
        ], $headers);

        $response->assertOk();
        $this->assertStringContainsString('The primary language is American English;', $service->requests[0]['instructions']);
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function foreignContexts(): array
    {
        return [
            ['study_plan_id', false],
            ['study_plan_id', true],
            ['conversation_id', false],
            ['conversation_id', true],
        ];
    }

    #[DataProvider('foreignContexts')]
    public function test_tts_rejects_missing_or_foreign_context(string $context, bool $foreign): void
    {
        [, $headers] = $this->makeUser();
        $resourceId = 2147483647;
        if ($foreign) {
            [$other] = $this->makeAdmin();
            if ($context === 'study_plan_id') {
                $resourceId = $this->plan($other, 'en-US')->id;
            } else {
                $resourceId = $this->conversation($other, null, 'en-US')->id;
            }
        }
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', ['text' => 'Hello.', $context => $resourceId], $headers);

        $response->assertStatus(404);
        $this->assertSame([], $service->requests);
    }

    /**
     * @return list<array{0: array<string, mixed>}>
     */
    public static function invalidContexts(): array
    {
        return [
            [['study_plan_id' => 0]],
            [['conversation_id' => -1]],
            [['study_plan_id' => 2147483648]],
            [['conversation_id' => true]],
            [['study_plan_id' => 1, 'conversation_id' => 2]],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    #[DataProvider('invalidContexts')]
    public function test_tts_rejects_invalid_or_ambiguous_context(array $context): void
    {
        [, $headers] = $this->makeUser();
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', ['text' => 'Hello.', ...$context], $headers);

        $response->assertStatus(422);
        $this->assertSame([], $service->requests);
    }

    public function test_tts_without_context_does_not_impose_a_regional_accent(): void
    {
        [, $headers] = $this->makeUser();
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', ['text' => 'Hello.'], $headers);

        $response->assertOk();
        $instructions = $service->requests[0]['instructions'];
        $this->assertStringNotContainsString('British', $instructions);
        $this->assertStringNotContainsString('primary language', $instructions);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function chatPlayback(): array
    {
        $cases = [];
        foreach (['chat', 'voice'] as $source) {
            foreach (['openai', 'local'] as $provider) {
                $cases[] = [$source, $provider];
            }
        }

        return $cases;
    }

    #[DataProvider('chatPlayback')]
    public function test_chat_playback_converts_markdown_before_synthesis(string $source, string $provider): void
    {
        [$user, $headers] = $this->makeUser();
        $conversation = $this->conversation($user, null, 'en-US', $source);
        $service = $provider === 'openai' ? $this->openai() : $this->kokoro('ID3audio');
        $this->bind($service, null, $provider, 'local');

        $response = $this->api('POST', '/api/tts', [
            'text' => "Use **went**, not *goed*.\n\n- I went home\n- She went shopping.",
            'conversation_id' => $conversation->id,
            'voice' => 'coral',
        ], $headers);

        $response->assertOk();
        $this->assertSame($provider === 'openai' ? 'ID3new-audio' : 'ID3audio', $response->getContent());
        $this->assertSame([
            "Use went, not goed.\n\nI went home.\nShe went shopping.",
            $provider === 'openai' ? 'coral' : null,
            'en-US',
        ], $service->invocations[0]);
    }

    #[DataProvider('withPlan')]
    public function test_tts_preserves_literal_text_outside_chat(bool $withPlan): void
    {
        [$user, $headers] = $this->makeUser();
        $body = ['text' => 'Keep **this**, ___ and 1. intact.'];
        if ($withPlan) {
            $body['study_plan_id'] = $this->plan($user, 'en-US')->id;
        }
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', $body, $headers);

        $response->assertOk();
        $this->assertSame($body['text'], $service->requests[0]['input']);
    }

    public function test_chat_tts_rejects_content_without_speakable_text(): void
    {
        [$user, $headers] = $this->makeUser();
        $conversation = $this->conversation($user, null, 'en-GB');
        $service = $this->openai();
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts', [
            'text' => '---',
            'conversation_id' => $conversation->id,
        ], $headers);

        $response->assertStatus(422);
        $this->assertSame([], $service->requests);
    }

    public function test_narration_requires_authentication(): void
    {
        $service = $this->openai('tts-1');
        $service->transport = fn () => 'ID3tour-audio';
        $this->bind($service, null, 'openai', 'local');

        $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT])->assertStatus(401);
        $this->assertSame([], $service->invocations);
    }

    public function test_client_text_interface_language_and_voice_are_cached(): void
    {
        [, $headers] = $this->makeUser();
        config(['freelingo.audio_storage_path' => $this->tempDir()]);
        $service = $this->openai('tts-1');
        $service->transport = fn () => 'ID3tour-audio';
        $this->bind($service, null, 'openai', 'local');
        $body = ['text' => 'Pratiquez avec Lingu.', 'voice' => 'coral'];

        $first = $this->api('POST', '/api/tts/tour/fr/step6', $body, $headers);
        $second = $this->api('POST', '/api/tts/tour/fr/step6', $body, $headers);

        $first->assertOk();
        $second->assertOk();
        $expected = hash('sha256', 'ID3tour-audio');
        $this->assertSame($expected, $this->fileHash($first));
        $this->assertSame($expected, $this->fileHash($second));
        $this->assertStringContainsString('audio/mpeg', (string) $first->headers->get('content-type'));
        $this->assertSame('no-store', $first->headers->get('cache-control'));
        $this->assertSame([[$body['text'], 'coral', 'fr']], $service->invocations);
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int}>
     */
    public static function tourValidation(): array
    {
        return [
            ['xx/step1', null, 404],
            ['es/step8', null, 404],
            ['es/step1', 'unknown', 400],
        ];
    }

    #[DataProvider('tourValidation')]
    public function test_locale_step_and_voice_are_validated(string $path, ?string $voice, int $status): void
    {
        [, $headers] = $this->makeUser();
        $service = $this->openai('tts-1');
        $service->transport = fn () => 'ID3tour-audio';
        $this->bind($service, null, 'openai', 'local');

        $response = $this->api('POST', '/api/tts/tour/'.$path, ['text' => self::TOUR_TEXT, 'voice' => $voice], $headers);

        $response->assertStatus($status);
        $this->assertSame([], $service->invocations);
    }

    /**
     * @return list<array{0: array<string, mixed>}>
     */
    public static function boundedTourText(): array
    {
        return [
            [[]],
            [['text' => '']],
            [['text' => str_repeat('a', 5001)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('boundedTourText')]
    public function test_narration_requires_bounded_text(array $body): void
    {
        [, $headers] = $this->makeUser();
        $service = $this->openai('tts-1');
        $service->transport = fn () => 'ID3tour-audio';
        $this->bind($service, null, 'openai', 'local');

        $this->api('POST', '/api/tts/tour/es/step1', $body, $headers)->assertStatus(422);
        $this->assertSame([], $service->invocations);
    }

    public function test_cache_identity_covers_effective_settings_and_text(): void
    {
        config(['freelingo.audio_storage_path' => $this->tempDir()]);
        $service = $this->openai('tts-1');
        $service->transport = fn () => 'ID3tour-audio';
        $audio = function (?string $voice = null, string $locale = 'es', ?string $text = null) use ($service) {
            return TourAudio::path($service, $locale, $text ?? self::TOUR_TEXT, $voice);
        };

        $original = $audio();
        $this->assertSame($original, $audio('nova'));
        $paths = [$original, $audio('coral'), $audio(null, 'en')];
        $service->model = 'another-configured-model';
        $paths[] = $audio();
        $service->speed = 0.9;
        $paths[] = $audio();
        $paths[] = $audio(null, 'es', 'Texto revisado');

        $this->assertCount(6, array_unique($paths));
        $this->assertCount(6, $service->invocations);
    }

    public function test_tour_regenerates_when_pronunciation_changes(): void
    {
        config(['freelingo.audio_storage_path' => $this->tempDir()]);
        $service = $this->openai('gpt-4o-mini-tts');
        $service->transport = fn () => 'ID3tour-audio';
        $original = TourAudio::path($service, 'es', self::TOUR_TEXT, null);
        $service->instructionSource = fn (?string $language) => 'Revised pronunciation';
        $revised = TourAudio::path($service, 'es', self::TOUR_TEXT, null);

        $this->assertNotSame($original, $revised);
        $this->assertFileExists($original);
        $this->assertCount(2, $service->invocations);
    }

    public function test_kokoro_keeps_configured_voice(): void
    {
        [, $headers] = $this->makeUser();
        config(['freelingo.audio_storage_path' => $this->tempDir()]);
        $service = $this->kokoro('ID3kokoro');
        $this->bind($service, null, 'local', 'local');

        $first = $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT, 'voice' => 'coral'], $headers);
        $second = $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT, 'voice' => 'stale'], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(hash('sha256', 'ID3kokoro'), $this->fileHash($first));
        $this->assertSame($this->fileHash($first), $this->fileHash($second));
        $this->assertSame([[self::TOUR_TEXT, 'af_heart', 'es']], $service->invocations);
    }

    public function test_empty_audio_is_not_cached_and_can_be_retried(): void
    {
        [, $headers] = $this->makeUser();
        $directory = $this->tempDir();
        config(['freelingo.audio_storage_path' => $directory]);
        $service = $this->openai('tts-1');
        $service->transport = fn () => '';
        $this->bind($service, null, 'openai', 'local');

        $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT], $headers)->assertStatus(503);
        $this->assertSame([], $this->mp3Files($directory));

        $service->transport = fn () => 'ID3retry';
        $retry = $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT], $headers);
        $retry->assertOk();
        $this->assertSame(hash('sha256', 'ID3retry'), $this->fileHash($retry));
    }

    public function test_synthesis_timeout_returns_gateway_timeout(): void
    {
        [, $headers] = $this->makeUser();
        config(['freelingo.audio_storage_path' => $this->tempDir()]);
        $service = $this->openai('tts-1');
        $service->transport = function () {
            throw new SpeechTimeout('timeout');
        };
        $this->bind($service, null, 'openai', 'local');

        $this->api('POST', '/api/tts/tour/es/step1', ['text' => self::TOUR_TEXT], $headers)->assertStatus(504);
    }

    private function openai(string $model = 'gpt-4o-mini-tts'): OpenAiTtsService
    {
        $service = new OpenAiTtsService('test-key', $model, 'nova', 1.0);
        $service->transport = fn () => 'ID3new-audio';

        return $service;
    }

    private function kokoro(string $audio): KokoroTtsService
    {
        $service = new KokoroTtsService('http://unused', 'af_heart');
        $service->transport = fn () => $audio;

        return $service;
    }

    private function bind(?object $tts, ?object $stt, string $ttsProvider, string $sttProvider): void
    {
        $this->app->instance(SpeechGateway::class, new SpeechGateway($tts, $stt, $ttsProvider, $sttProvider));
    }

    private function plan(User $user, string $language, bool $active = true): StudyPlan
    {
        $row = UserLanguage::query()->where('user_id', $user->id)->where('is_active', true)->firstOrFail();

        return StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $row->id,
            'cefr_level' => 'A1',
            'target_language' => $language,
            'goals' => ['grammar'],
            'current_unit' => '',
            'progress_day' => 0,
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'generated_plan' => [],
            'is_active' => $active,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    private function conversation(User $user, ?StudyPlan $plan, string $language, string $source = 'chat'): Conversation
    {
        $now = Carbon::now('UTC');

        return Conversation::query()->create([
            'user_id' => $user->id,
            'title' => 'New conversation',
            'source' => $source,
            'study_plan_id' => $plan?->id,
            'target_language' => $language,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function wav(string $bytes = 'audio-bytes'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wav');
        file_put_contents((string) $path, $bytes);

        return new UploadedFile((string) $path, 'recording.wav', 'audio/wav', null, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function postStt(array $headers, array $data, ?UploadedFile $file): TestResponse
    {
        if ($file !== null) {
            $data['audio'] = $file;
        }

        return $this->withHeaders($headers)->post('/api/stt', $data);
    }

    private function tempDir(): string
    {
        $directory = sys_get_temp_dir().'/freelingo-'.bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function fileHash(TestResponse $response): string
    {
        $base = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);

        return hash('sha256', (string) file_get_contents($base->getFile()->getPathname()));
    }

    /**
     * @return list<string>
     */
    private function mp3Files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.mp3')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
