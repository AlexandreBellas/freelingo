<?php

namespace App\Services\Practice;

use App\Exceptions\ApiException;
use App\Models\ResourceNativeHelp;
use App\Models\User;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmException;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;

final class NativeHelp
{
    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly LlmAdapter $llm,
    ) {
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  callable(string, string, string): string  $prompt
     * @return array<string, mixed>
     */
    public function resolve(
        User $user,
        string $type,
        string $key,
        string $targetLanguage,
        array $source,
        callable $prompt,
    ): array {
        $hash = Text::sha256Canonical($source);
        $native = (string) $user->native_language;
        $cached = $this->cached($type, $key, $targetLanguage, $native, $hash);
        if ($cached) {
            return $cached->content;
        }
        $lock = "native_help:{$type}:{$targetLanguage}:{$native}:{$key}";
        $acquired = $this->redis->set($lock, '1', 'NX');
        if (! $acquired) {
            $tries = app()->runningUnitTests() ? 1 : 10;
            for ($i = 0; $i < $tries; $i++) {
                if (! app()->runningUnitTests()) {
                    usleep(500000);
                }
                $cached = $this->cached($type, $key, $targetLanguage, $native, $hash);
                if ($cached) {
                    return $cached->content;
                }
            }
            throw new ApiException('Native help is being generated. Try again shortly.', 503);
        }
        try {
            $body = $prompt(
                Languages::name($targetLanguage),
                Languages::nativeName($native),
                Text::pyJson($source),
            );
            $content = $this->llm->structuredOutput([['role' => 'user', 'content' => $body]]);
        } catch (LlmException) {
            throw new ApiException('Could not generate native help at this time', 503);
        } finally {
            $this->redis->delete($lock);
        }
        $now = Carbon::now('UTC');
        $row = ResourceNativeHelp::query()
            ->where('resource_type', $type)
            ->where('resource_key', $key)
            ->where('target_language', $targetLanguage)
            ->where('native_language', $native)
            ->first();
        if ($row) {
            $row->source_hash = $hash;
            $row->content = $content;
            $row->updated_at = $now;
            $row->save();
        } else {
            $row = ResourceNativeHelp::query()->create([
                'resource_type' => $type,
                'resource_key' => $key,
                'target_language' => $targetLanguage,
                'native_language' => $native,
                'source_hash' => $hash,
                'content' => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $row->content;
    }

    private function cached(string $type, string $key, string $target, string $native, string $hash): ?ResourceNativeHelp
    {
        $row = ResourceNativeHelp::query()
            ->where('resource_type', $type)
            ->where('resource_key', $key)
            ->where('target_language', $target)
            ->where('native_language', $native)
            ->first();
        if ($row && $row->source_hash === $hash) {
            return $row;
        }

        return null;
    }
}
