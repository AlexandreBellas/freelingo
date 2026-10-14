<?php

namespace App\Services\Llm;

class UnavailableLlm implements LlmClient
{
    public function complete(array $messages, ?float $deadline = null): string
    {
        throw new LlmUnavailableException('ai_service_unavailable');
    }
}
