<?php

namespace App\Services\Llm;

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
