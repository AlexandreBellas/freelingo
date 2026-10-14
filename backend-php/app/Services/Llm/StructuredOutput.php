<?php

namespace App\Services\Llm;

use App\Services\Prompts\PromptCatalog;
use App\Support\PyFormat;

final class StructuredOutput
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(mixed): mixed  $validate
     */
    public function decode(array $messages, callable $validate, ?float $deadline = null): mixed
    {
        $raw = $this->llm->complete($messages, $deadline);
        try {
            return $validate($this->json($raw));
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $retry = $messages;
            $retry[] = [
                'role' => 'user',
                'content' => PyFormat::format(PromptCatalog::text('STRUCTURED_OUTPUT_RETRY_PROMPT'), [
                    'error' => $e->getMessage(),
                ]),
            ];
            try {
                return $validate($this->json($this->llm->complete($retry, $deadline)));
            } catch (LlmException $again) {
                throw $again;
            } catch (\Throwable $again) {
                throw new LlmException('Failed to parse JSON after retry: '.$again->getMessage(), 0, $again);
            }
        }
    }

    public function chat(array $messages): string
    {
        return $this->llm->complete($messages);
    }

    private function json(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
