<?php

namespace App\Services\Speech;

final class KokoroTtsService
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /**
     * Arguments passed to synthesize, before voice fallback.
     *
     * @var list<array{0: string, 1: ?string, 2: ?string}>
     */
    public array $invocations = [];

    /** @var callable(string, array<string, mixed>): string */
    public $transport;

    public function __construct(
        public readonly string $baseUrl,
        public string $voice,
    ) {
        $this->transport = static fn () => throw new \RuntimeException('TTS transport is not configured');
    }

    public function health(): void
    {
        $this->requests[] = ['op' => 'health', 'url' => $this->baseUrl.'/v1/models'];
        ($this->transport)('', ['op' => 'health', 'url' => $this->baseUrl.'/v1/models']);
    }

    public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
    {
        $this->invocations[] = [$text, $voice, $language];
        $payload = [
            'model' => 'kokoro',
            'input' => $text,
            'voice' => $voice ?: $this->voice,
            'response_format' => 'mp3',
            'url' => rtrim($this->baseUrl, '/').'/v1/audio/speech',
        ];
        $this->requests[] = $payload;

        return ($this->transport)($text, $payload);
    }
}
