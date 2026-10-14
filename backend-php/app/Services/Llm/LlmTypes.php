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

final class LlmToolResultEvent
{
    public function __construct(public readonly LlmToolResult $result)
    {
    }
}

final class LlmStreamReset
{
    public function __construct(public readonly string $reason = 'tools_unsupported')
    {
    }
}

final class LlmStream implements \IteratorAggregate
{
    public ?int $promptTokens = null;

    public ?int $completionTokens = null;

    public ?int $totalTokens = null;

    /** @var list<LlmToolResult> */
    public array $toolResults = [];

    public bool $toolsUnsupported = false;

    /**
     * @param  list<mixed>  $events
     */
    public function __construct(public array $events = [])
    {
    }

    public function getIterator(): \Traversable
    {
        yield from $this->events;
    }
}
