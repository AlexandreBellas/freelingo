<?php

namespace App\Services\Llm;

final class LlmToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
        public readonly string $rawArguments = '',
    ) {
    }
}

