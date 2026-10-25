<?php

namespace App\Services\Llm;

final class LlmToolResultEvent
{
    public function __construct(public readonly LlmToolResult $result)
    {
    }
}

