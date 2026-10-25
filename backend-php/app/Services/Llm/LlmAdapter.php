<?php

namespace App\Services\Llm;

use App\Services\Practice\Prompts;
use App\Support\Settings;

/**
 * Public LLM interface. HTTP providers are stubs: tests and callers inject a responder.
 */
class LlmAdapter
{
    public const MAX_RETRIES = 2;

    public const RETRY_DELAY_SECONDS = 0;

    public const REQUEST_TIMEOUT = 120.0;

    /** @var array<string, int> */
    public const MAX_CONTEXT_TOKENS = [
        'openai' => 128000,
        'anthropic' => 200000,
        'deepseek' => 128000,
        'ollama' => 8192,
    ];

    public string $provider;

    public string $model;

    /**
     * Test double. Return a string, an LlmStream, or throw.
     *
     * @var null|callable(array<int, array<string, mixed>>, bool, ?array): (string|LlmStream)
     */
    public $responder = null;

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function __construct(?string $provider = null, ?string $model = null)
    {
        $this->provider = $provider ?? Settings::string('llm_provider', 'ollama');
        $this->model = $model ?? match ($this->provider) {
            'openai' => Settings::string('openai_model', 'gpt-6-luna'),
            'anthropic' => Settings::string('anthropic_model', 'claude-3-5-haiku-latest'),
            'deepseek' => Settings::string('deepseek_model', 'deepseek-chat'),
            default => Settings::string('ollama_model', 'gemma4:e4b'),
        };
    }

    public static function fromSettings(): self
    {
        return new self;
    }

    public static function isToolsUnsupported(\Throwable $exception): bool
    {
        $messages = [];
        $current = $exception;
        $seen = [];
        while ($current !== null) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $messages[] = strtolower($current->getMessage());
            $current = $current->getPrevious();
        }
        $message = implode(' ', $messages);
        foreach ([
            'does not support tools',
            "doesn't support tools",
            'does not support tool use',
            "doesn't support tool use",
            'does not support function calling',
            "doesn't support function calling",
            'tools are not supported',
            'tools is not supported',
            "'tools' is not supported",
            '"tools" is not supported',
            '`tools` is not supported',
            'tool use is not supported',
            'function tools with reasoning_effort are not supported',
            'function tools with reasoning effort are not supported',
            "unsupported parameter: 'tools'",
            'unsupported parameter: "tools"',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<LlmTool>|null  $tools
     * @param  list<array<string, mixed>>|null  $fallbackMessages
     */
    public function chat(
        array $messages,
        bool $stream = false,
        ?array $tools = null,
        ?callable $toolExecutor = null,
        ?array $fallbackMessages = null,
    ): string|LlmStream {
        if ($tools && (! $stream || $toolExecutor === null)) {
            throw new \InvalidArgumentException('Native tools require streaming and a tool executor');
        }
        $usingFallback = false;
        $toolsUnsupported = false;
        $messagesWithoutTools = $fallbackMessages ?? $messages;
        try {
            $result = $this->callWithRetry(
                fn () => $this->doChat($messages, $stream, $tools),
                toolsRequested: (bool) $tools,
            );
        } catch (LlmToolsUnsupportedError) {
            $result = $this->callWithRetry(fn () => $this->doChat($messagesWithoutTools, $stream, null));
            $toolsUnsupported = true;
            $usingFallback = true;
        } catch (LlmException $exception) {
            if (! $tools) {
                throw $exception;
            }
            $result = $this->callWithRetry(fn () => $this->doChat($messagesWithoutTools, $stream, null));
            $usingFallback = true;
        }
        if (! $stream) {
            return $result;
        }
        if (! $result instanceof LlmStream) {
            $result = new LlmStream([(string) $result]);
        }
        if ($tools && $toolExecutor) {
            return $this->toolStream($result, $messages, $messagesWithoutTools, $toolExecutor, $usingFallback, $toolsUnsupported);
        }
        if ($toolsUnsupported) {
            $result->toolsUnsupported = true;
            array_unshift($result->events, new LlmStreamReset);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  callable(array<string, mixed>): bool|null  $validate
     * @return array<string, mixed>
     */
    public function structuredOutput(array $messages, ?callable $validate = null, ?float $deadline = null): array
    {
        if ($deadline !== null && $deadline <= microtime(true)) {
            throw new LlmTimeoutError('Structured generation deadline reached');
        }
        $messagesWithFormat = [...$messages, ['role' => 'system', 'content' => Prompts::jsonOnly()]];
        $request = function (array $requestMessages) use ($deadline): string {
            if ($deadline === null) {
                $raw = $this->callWithRetry(fn () => $this->doChat($requestMessages, false, null));
            } else {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new LlmTimeoutError('Structured generation deadline reached');
                }
                $raw = $this->callWithRetry(fn () => $this->doChat($requestMessages, false, null), maxRetries: 0);
            }
            if ($raw instanceof LlmStream) {
                $raw = implode('', array_filter($raw->events, 'is_string'));
            }

            return (string) $raw;
        };
        $raw = $request($messagesWithFormat);
        try {
            $parsed = $this->decodeStructured($raw);
            if ($validate && ! $validate($parsed)) {
                throw new \InvalidArgumentException('Structured output failed validation');
            }

            return $parsed;
        } catch (\Throwable $error) {
            $retryMessages = [...$messagesWithFormat, [
                'role' => 'user',
                'content' => TextBridge::retry($error->getMessage()),
            ]];
            try {
                $raw2 = $request($retryMessages);
                $parsed = $this->decodeStructured($raw2);
                if ($validate && ! $validate($parsed)) {
                    throw new \InvalidArgumentException('Structured output failed validation');
                }

                return $parsed;
            } catch (LlmException $second) {
                if ($deadline !== null) {
                    throw $second;
                }
                throw new LlmResponseError('Failed to parse JSON after retry: '.$second->getMessage(), $raw2 ?? $raw);
            } catch (\Throwable $second) {
                throw new LlmResponseError('Failed to parse JSON after retry: '.$second->getMessage(), $raw2 ?? $raw);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeStructured(string $raw): array
    {
        $cleaned = trim($raw);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = str_contains($cleaned, "\n") ? explode("\n", $cleaned, 2)[1] : substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);
        if (str_starts_with($cleaned, 'json')) {
            $cleaned = trim(substr($cleaned, 4));
        }
        $decoded = json_decode($cleaned, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseLlmJson(string $raw): array
    {
        $cleaned = trim($raw);
        if (str_starts_with($cleaned, '```')) {
            $parts = explode('```', $cleaned);
            $cleaned = $parts[1] ?? '';
            if (str_starts_with($cleaned, 'json')) {
                $cleaned = substr($cleaned, 4);
            }
        }
        $decoded = json_decode(trim($cleaned), true);
        if (! is_array($decoded)) {
            throw new \JsonException('Invalid JSON');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<LlmTool>|null  $tools
     */
    private function doChat(array $messages, bool $stream, ?array $tools): string|LlmStream
    {
        $this->requests[] = [
            'provider' => $this->provider,
            'model' => $this->model,
            'messages' => $messages,
            'stream' => $stream,
            'tools' => $tools,
        ];
        if ($this->responder === null) {
            throw new LlmUnavailableError("{$this->provider} is unreachable. Check that the service is running.");
        }
        try {
            return ($this->responder)($messages, $stream, $tools);
        } catch (LlmException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($tools && self::isToolsUnsupported($exception)) {
                throw new LlmToolsUnsupportedError("{$this->provider} does not support tools", previous: $exception);
            }
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
                throw new LlmTimeoutError("{$this->provider} request timed out", previous: $exception);
            }
            if (str_contains($message, 'connection')) {
                throw new LlmUnavailableError("{$this->provider} is unreachable. Check that the service is running.", previous: $exception);
            }
            if (str_contains($message, 'rate')) {
                throw new LlmUnavailableError("{$this->provider} rate limit exceeded. Try again later.", previous: $exception);
            }
            throw new LlmException("{$this->provider} error: ".$exception->getMessage(), previous: $exception);
        }
    }

    private function callWithRetry(callable $fn, bool $toolsRequested = false, int $maxRetries = self::MAX_RETRIES): string|LlmStream
    {
        $last = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $fn();
            } catch (LlmException $exception) {
                if ($toolsRequested && self::isToolsUnsupported($exception)) {
                    throw new LlmToolsUnsupportedError("{$this->provider} does not support tools", previous: $exception);
                }
                $last = $exception;
            } catch (\Throwable $exception) {
                if ($toolsRequested && self::isToolsUnsupported($exception)) {
                    throw new LlmToolsUnsupportedError("{$this->provider} does not support tools", previous: $exception);
                }
                $last = new LlmException("{$this->provider} error: ".$exception->getMessage(), previous: $exception);
            }
        }
        throw $last ?? new LlmException("{$this->provider} error");
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $fallback
     */
    private function toolStream(
        LlmStream $stream,
        array $messages,
        array $fallback,
        callable $executor,
        bool $usingFallback,
        bool $toolsUnsupported,
    ): LlmStream {
        $events = [];
        $calls = [];
        $text = '';
        foreach ($stream->events as $event) {
            if ($event instanceof LlmToolCall) {
                $calls[] = $event;

                continue;
            }
            if (is_string($event)) {
                $text .= $event;
                $events[] = $event;
            } else {
                $events[] = $event;
            }
        }
        if ($calls !== []) {
            $results = [];
            foreach ($calls as $call) {
                $result = $executor($call);
                if (! $result instanceof LlmToolResult) {
                    $result = new LlmToolResult($call, ['saved' => false, 'error' => 'invalid_executor'], true);
                }
                $results[] = $result;
                $events[] = new LlmToolResultEvent($result);
            }
            $stream->toolResults = $results;
            $continuation = $this->buildToolContinuation($messages, $text, $calls, $results);
            try {
                $follow = $this->doChat($continuation, true, null);
                $followEvents = $follow instanceof LlmStream ? $follow->events : [(string) $follow];
                foreach ($followEvents as $event) {
                    $events[] = $event;
                }
            } catch (LlmException) {
                $events[] = new LlmStreamReset('continuation_failed');
            }
        }
        if ($toolsUnsupported || $usingFallback) {
            array_unshift($events, new LlmStreamReset);
            $stream->toolsUnsupported = $toolsUnsupported;
        }
        $stream->events = $events;

        return $stream;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<LlmToolCall>  $calls
     * @param  list<LlmToolResult>  $results
     * @return list<array<string, mixed>>
     */
    public function buildToolContinuation(array $messages, string $initialText, array $calls, array $results): array
    {
        if ($this->provider === 'openai') {
            $outputs = [];
            foreach ($results as $result) {
                $outputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => $result->call->id,
                    'output' => json_encode($result->content, JSON_UNESCAPED_UNICODE),
                ];
            }

            return [...$messages, ...$outputs];
        }
        if ($this->provider === 'anthropic') {
            $assistant = [];
            if ($initialText !== '') {
                $assistant[] = ['type' => 'text', 'text' => $initialText];
            }
            foreach ($calls as $call) {
                $assistant[] = [
                    'type' => 'tool_use',
                    'id' => $call->id,
                    'name' => $call->name,
                    'input' => $call->arguments,
                ];
            }
            $toolResults = [];
            foreach ($results as $result) {
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $result->call->id,
                    'content' => json_encode($result->content, JSON_UNESCAPED_UNICODE),
                    'is_error' => $result->isError,
                ];
            }

            return [...$messages, ['role' => 'assistant', 'content' => $assistant], ['role' => 'user', 'content' => $toolResults]];
        }
        $toolCalls = [];
        foreach ($calls as $call) {
            $toolCalls[] = [
                'id' => $call->id,
                'type' => 'function',
                'function' => [
                    'name' => $call->name,
                    'arguments' => $call->rawArguments !== '' ? $call->rawArguments : json_encode($call->arguments),
                ],
            ];
        }
        $continuation = [...$messages, [
            'role' => 'assistant',
            'content' => $initialText !== '' ? $initialText : null,
            'tool_calls' => $toolCalls,
        ]];
        foreach ($results as $result) {
            $continuation[] = [
                'role' => 'tool',
                'tool_call_id' => $result->call->id,
                'content' => json_encode($result->content, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $continuation;
    }
}

final class TextBridge
{
    public static function retry(string $error): string
    {
        return \App\Services\Practice\Prompts::structuredRetry($error);
    }
}
