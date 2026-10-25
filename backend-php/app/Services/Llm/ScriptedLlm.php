<?php

namespace App\Services\Llm;

class ScriptedLlm implements LlmClient
{
    /** @var list<string|\Throwable> */
    public array $scripts = [];

    /** @var list<list<array{role: string, content: string}>> */
    public array $calls = [];

    /**
     * @param  list<string|\Throwable|array<string, mixed>>  $scripts
     */
    public function __construct(array $scripts = [])
    {
        foreach ($scripts as $script) {
            $this->scripts[] = is_array($script)
                ? json_encode($script, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $script;
        }
    }

    public function complete(array $messages, ?float $deadline = null): string
    {
        $this->calls[] = $messages;
        if ($this->scripts === []) {
            throw new LlmException('No scripted LLM response');
        }
        $next = array_shift($this->scripts);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function prompt(): string
    {
        $last = $this->calls[array_key_last($this->calls)] ?? [];

        return (string) ($last[0]['content'] ?? '');
    }
}
