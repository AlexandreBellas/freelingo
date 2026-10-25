<?php

namespace App\Services\Llm;

final class LlmStreamReset
{
    public function __construct(public readonly string $reason = 'tools_unsupported')
    {
    }
}

