<?php

namespace App\Services\Speech;

final class OpenAiSttService
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var callable(string, array<string, mixed>): string */
    public $transport;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $model,
    ) {
        $this->transport = static fn () => throw new \RuntimeException('STT transport is not configured');
    }

    public function health(): void
    {
        ($this->transport)('', ['op' => 'health']);
    }

    public function transcribe(
        string $audioBytes,
        string $filename = 'audio.wav',
        string $mimeType = 'audio/wav',
        string $language = '',
    ): string {
        $payload = [
            'model' => $this->model,
            'filename' => $filename,
            'mime' => $mimeType,
            'language' => $language,
            'timeout' => 60.0,
            'bytes' => strlen($audioBytes),
        ];
        $this->requests[] = $payload;

        return trim(($this->transport)($audioBytes, $payload));
    }
}
