<?php

namespace App\Services\Practice;

use App\Services\Speech\KokoroTtsService;
use App\Services\Speech\OpenAiSttService;
use App\Services\Speech\OpenAiTtsService;
use App\Services\Speech\WhisperSttService;
use App\Support\Settings;

final class SpeechGateway
{
    public const OPENAI_VOICES = [
        'alloy', 'ash', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer',
    ];

    public const UI_LOCALES = [
        'en', 'es', 'fr', 'pt', 'de', 'it', 'pl', 'nl', 'ro', 'ru', 'tr', 'sv', 'da', 'fi', 'hr',
    ];

    public function __construct(
        public readonly ?object $tts,
        public readonly ?object $stt,
        public readonly string $ttsProvider,
        public readonly string $sttProvider,
    ) {
    }

    public static function fromSettings(): self
    {
        $ttsProvider = Settings::string('tts_provider', 'local');
        $sttProvider = Settings::string('stt_provider', 'local');
        $tts = $ttsProvider === 'openai'
            ? new OpenAiTtsService(
                Settings::string('openai_api_key', ''),
                Settings::string('openai_tts_model', 'tts-1'),
                Settings::string('openai_tts_voice', 'nova'),
                (float) Settings::get('openai_tts_speed', 1.0),
            )
            : new KokoroTtsService(
                Settings::string('tts_base_url', 'http://kokoro:8880'),
                Settings::string('tts_voice', 'af_heart'),
            );
        $stt = $sttProvider === 'openai'
            ? new OpenAiSttService(Settings::string('openai_api_key', ''), Settings::string('openai_stt_model', 'whisper-1'))
            : new WhisperSttService(Settings::string('stt_base_url', 'http://whisper:9000'));

        return new self($tts, $stt, $ttsProvider, $sttProvider);
    }

    public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
    {
        if ($this->tts === null || ! method_exists($this->tts, 'synthesize')) {
            throw new \RuntimeException('TTS service is not enabled');
        }

        return $this->tts->synthesize($text, $voice, $language);
    }
}
