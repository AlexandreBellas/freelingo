<?php

namespace App\Services\Learning;

use App\Services\Llm\LlmTimeoutException;
use App\Services\Redis\ArrayRedis;
use App\Support\Clock;
use App\Support\Settings;

class GenerationInterruptedException extends \RuntimeException {}

final class ExerciseGeneration
{
    public const LEASE_SECONDS = 60;

    public const STATE_SECONDS = 900;

    public function __construct(private readonly ArrayRedis $redis) {}

    /**
     * @return array{generation_status: string, generation_error: ?string, generation_deadline: ?string, generation_remaining_seconds: ?float}
     */
    public function state(string $key): array
    {
        $owner = $this->liveOwner($key);
        $raw = $this->redis->get($key.':state');
        $state = $raw ? json_decode($raw, true) : [];
        if (! is_array($state)) {
            $state = [];
        }
        $status = $state['generation_status'] ?? 'idle';
        $error = $state['generation_error'] ?? null;
        $deadline = $state['generation_deadline'] ?? null;
        $remaining = null;
        if ($owner !== null) {
            $status = 'generating';
            $error = null;
            if ($deadline) {
                $remaining = max(0.0, strtotime($deadline) - Clock::now()->getTimestamp());
            }
        } elseif ($status === 'generating') {
            $status = 'failed';
            $error = 'interrupted';
        }

        return [
            'generation_status' => $status,
            'generation_error' => $error,
            'generation_deadline' => $deadline,
            'generation_remaining_seconds' => $remaining,
        ];
    }

    public function acquire(string $key): ?GenerationLease
    {
        if ($this->liveOwner($key) !== null) {
            return null;
        }
        $seconds = Settings::int('exercise_generation_timeout_seconds', 600);
        $owner = bin2hex(random_bytes(16));
        $deadline = Clock::now()->addSeconds($seconds);
        $leaseUntil = Clock::now()->addSeconds(self::LEASE_SECONDS)->getTimestamp();
        $this->redis->set($key, json_encode(['owner' => $owner, 'until' => $leaseUntil]));
        $this->redis->set($key.':state', json_encode([
            'generation_status' => 'generating',
            'generation_error' => null,
            'generation_deadline' => $deadline->toIso8601String(),
        ]));

        return new GenerationLease($key, $owner, $deadline->getTimestamp(), $this);
    }

    public function ensureOwner(GenerationLease $lease): void
    {
        if (Clock::now()->getTimestamp() >= $lease->deadline) {
            throw new \RuntimeException('Exercise generation deadline reached');
        }
        $current = $this->payload($lease->key);
        if (! $current || $current['owner'] !== $lease->owner || $current['until'] < Clock::now()->getTimestamp()) {
            throw new GenerationInterruptedException('Exercise generation lease lost');
        }
        $current['until'] = Clock::now()->addSeconds(self::LEASE_SECONDS)->getTimestamp();
        $this->redis->set($lease->key, json_encode($current));
    }

    public function finish(GenerationLease $lease, ?string $error = null): void
    {
        $current = $this->payload($lease->key);
        if (! $current || $current['owner'] !== $lease->owner) {
            return;
        }
        $this->redis->set($lease->key.':state', json_encode([
            'generation_status' => $error ? 'failed' : 'idle',
            'generation_error' => $error,
            'generation_deadline' => null,
        ]));
        $this->redis->delete($lease->key);
    }

    /**
     * @param  callable(): void  $work
     */
    public function run(GenerationLease $lease, callable $work): void
    {
        $error = 'interrupted';
        try {
            $this->ensureOwner($lease);
            $work();
            $error = null;
        } catch (LlmTimeoutException|\RuntimeException $e) {
            if ($e instanceof GenerationInterruptedException) {
                $error = 'interrupted';
            } elseif ($e instanceof LlmTimeoutException || str_contains($e->getMessage(), 'deadline')) {
                $error = 'timeout';
            } else {
                $error = 'generation_failed';
            }
        } catch (\Throwable) {
            $error = 'generation_failed';
        }
        try {
            $this->finish($lease, $error);
        } catch (\Throwable) {
        }
    }

    private function liveOwner(string $key): ?string
    {
        $payload = $this->payload($key);
        if (! $payload || $payload['until'] < Clock::now()->getTimestamp()) {
            return null;
        }

        return $payload['owner'];
    }

    /**
     * @return array{owner: string, until: int}|null
     */
    private function payload(string $key): ?array
    {
        $raw = $this->redis->get($key);
        if (! $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['owner'], $decoded['until']) ? $decoded : null;
    }
}

final class GenerationLease
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly int $deadline,
        private readonly ExerciseGeneration $service,
    ) {}

    public function ensureOwner(): void
    {
        $this->service->ensureOwner($this);
    }

    public function finish(?string $error = null): void
    {
        $this->service->finish($this, $error);
    }

    /**
     * @param  callable(): void  $work
     */
    public function run(callable $work): void
    {
        $this->service->run($this, $work);
    }
}
