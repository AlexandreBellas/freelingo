<?php

namespace App\Services\Llm;

final class LlmTool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
    ) {
    }
}

