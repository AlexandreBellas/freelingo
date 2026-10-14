<?php

namespace App\Services\Llm;

interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function complete(array $messages, ?float $deadline = null): string;
}
