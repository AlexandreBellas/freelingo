<?php

namespace App\Services\Llm;

final class LlmToolResult
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public readonly LlmToolCall $call,
        public readonly array $content,
        public readonly bool $isError = false,
    ) {
    }
}

