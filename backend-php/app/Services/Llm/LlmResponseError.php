<?php

namespace App\Services\Llm;

class LlmResponseError extends LlmResponseException
{
    public function __construct(
        string $message = '',
        public readonly ?string $rawResponse = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
