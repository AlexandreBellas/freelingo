<?php

namespace App\Services\Speech;

final class WhisperSttService
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var callable(string, array<string, mixed>): string */
    public $transport;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = static fn () => throw new \RuntimeException('STT transport is not configured');
    }

    public readonly string $baseUrl;

    public function health(): void
    {
        ($this->transport)('', ['op' => 'health', 'url' => $this->baseUrl.'/']);
    }

    public function transcribe(
        string $audioBytes,
        string $filename = 'audio.wav',
        string $mimeType = 'audio/wav',
        string $language = '',
    ): string {
        $payload = [
            'url' => $this->baseUrl.'/asr',
            'params' => ['output' => 'json', 'language' => $language, 'task' => 'transcribe'],
            'filename' => $filename,
            'mime' => $mimeType,
            'bytes' => strlen($audioBytes),
        ];
        $this->requests[] = $payload;

        return trim(($this->transport)($audioBytes, $payload));
    }
}
