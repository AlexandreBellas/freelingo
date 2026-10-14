<?php

namespace App\Services\Redis;

/**
 * In-memory Redis stand-in used by tests and by local runs without a server.
 * Method names follow the Python redis client used by the FastAPI backend.
 */
class ArrayRedis
{
    /** @var array<string, string> */
    private array $store = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->store[$key] = $value;
        $this->expiresAt[$key] = time() + $ttl;
    }

    public function set(string $key, string $value, mixed ...$args): bool
    {
        $nx = false;
        foreach ($args as $arg) {
            if (is_array($arg) && (($arg['nx'] ?? false) || ($arg['NX'] ?? false))) {
                $nx = true;
            } elseif (is_string($arg) && strtoupper($arg) === 'NX') {
                $nx = true;
            }
        }
        if ($nx && array_key_exists($key, $this->store)) {
            return false;
        }
        $this->store[$key] = $value;

        return true;
    }

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key], $this->expiresAt[$key]);
    }

    public function getex(string $key): ?string
    {
        return $this->get($key);
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    public function scan(int $cursor, ?string $match = null, ?int $count = null): array
    {
        $keys = array_keys($this->store);
        if ($match !== null) {
            $keys = array_values(array_filter($keys, fn (string $key) => fnmatch($match, $key)));
        }

        return [0, $keys];
    }

    public function incr(string $key): int
    {
        $next = ((int) ($this->store[$key] ?? 0)) + 1;
        $this->store[$key] = (string) $next;

        return $next;
    }

    public function incrby(string $key, int $amount): int
    {
        $next = ((int) ($this->store[$key] ?? 0)) + $amount;
        $this->store[$key] = (string) $next;

        return $next;
    }

    public function expire(string $key, int $ttl): void
    {
        if (array_key_exists($key, $this->store)) {
            $this->expiresAt[$key] = time() + $ttl;
        }
    }

    public function ttl(string $key): int
    {
        if (! array_key_exists($key, $this->store)) {
            return -2;
        }
        if (! array_key_exists($key, $this->expiresAt)) {
            return -1;
        }
        $remaining = $this->expiresAt[$key] - time();

        return $remaining > 0 ? $remaining : -2;
    }

    /**
     * @param  list<string>  $args
     * @return list<int>
     */
    public function eval(string $script, int $numKeys, string ...$args): array
    {
        $key = $args[0] ?? '';
        $limit = (int) ($args[1] ?? 0);
        $current = (int) ($this->store[$key] ?? 0);
        if ($limit > 0 && $current >= $limit) {
            return [0, $current];
        }
        $new = $this->incr($key);
        $ttl = (int) ($args[2] ?? 0);
        if ($new === 1 && $ttl > 0) {
            $this->expire($key, $ttl);
        }

        return [1, $current];
    }

    public function ping(): string
    {
        return 'PONG';
    }

    public function flush(): void
    {
        $this->store = [];
        $this->expiresAt = [];
    }
}
