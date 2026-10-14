<?php

namespace App\Services\Speech;

use App\Services\Practice\Prompts;
use App\Services\Practice\Text;

final class OpenAiTtsService
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var callable(string, array<string, mixed>): string */
    public $transport;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $model,
        public string $voice,
        public readonly float $speed = 1.0,
        public readonly ?float $timeout = null,
    ) {
        $this->transport = static fn () => throw new \RuntimeException('TTS transport is not configured');
    }

    public function health(): void
    {
        $this->requests[] = ['op' => 'health'];
        ($this->transport)('', ['op' => 'health']);
    }

    public function getInstructions(?string $language = null): ?string
    {
        if ($this->model === 'gpt-4o-mini-tts' || str_starts_with($this->model, 'gpt-4o-mini-tts-')) {
            return Prompts::speechInstructions($language);
        }

        return null;
    }

    public function getCacheKey(string $text, ?string $voice = null, ?string $language = null): string
    {
        $identity = [
            'text' => $text,
            'locale' => $language,
            'provider' => 'openai',
            'model' => $this->model,
            'voice' => $voice ?: $this->voice,
            'speed' => $this->speed,
            'format' => 'mp3',
        ];
        $instructions = $this->getInstructions($language);
        if ($instructions) {
            $identity['instructions'] = $instructions;
        }

        return hash('sha256', Text::pyDumps($identity));
    }

    public function synthesize(string $text, ?string $voice = null, ?string $language = null): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $payload = [
            'model' => $this->model,
            'voice' => trim($voice ?: $this->voice),
            'input' => $text,
            'response_format' => 'mp3',
            'speed' => $this->speed,
        ];
        $instructions = $this->getInstructions($language);
        if ($instructions) {
            $payload['instructions'] = $instructions;
        }
        if ($this->timeout !== null) {
            $payload['timeout'] = $this->timeout;
        }
        $this->requests[] = $payload;
        $audio = ($this->transport)($text, $payload);
        if ($audio === '') {
            throw new \RuntimeException('OpenAI TTS returned empty audio payload');
        }

        return $audio;
    }
}
