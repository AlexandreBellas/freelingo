<?php

namespace App\Services;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MemoryAlreadyExists extends \RuntimeException {}

class MemoryService
{
    public const MAX_MEMORIES_PER_USER = 150;

    public const MAX_MEMORY_CHARS = 200;

    /**
     * @param  list<string>  $items
     */
    public function save(int $userId, array $items, string $source = 'chat', ?int $studyPlanId = null): int
    {
        if ($items === []) {
            return 0;
        }

        return DB::transaction(function () use ($userId, $items, $source, $studyPlanId) {
            User::query()->where('id', $userId)->lockForUpdate()->first();
            $existing = Memory::query()
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'content']);
            $seen = [];
            foreach ($existing as $row) {
                $seen[$row->content] = true;
            }
            $newItems = [];
            foreach ($items as $item) {
                if (! is_string($item)) {
                    continue;
                }
                $normalized = mb_substr(trim($item), 0, self::MAX_MEMORY_CHARS);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $newItems[] = $normalized;
            }
            if (count($newItems) > self::MAX_MEMORIES_PER_USER) {
                $newItems = array_slice($newItems, -self::MAX_MEMORIES_PER_USER);
            }
            if ($newItems === []) {
                return 0;
            }
            $overflow = $existing->count() + count($newItems) - self::MAX_MEMORIES_PER_USER;
            if ($overflow > 0) {
                $ids = $existing->take($overflow)->pluck('id')->all();
                Memory::query()->whereIn('id', $ids)->delete();
            }
            $now = Carbon::now('UTC');
            foreach ($newItems as $item) {
                Memory::query()->create([
                    'user_id' => $userId,
                    'content' => $item,
                    'source' => $source,
                    'study_plan_id' => $studyPlanId,
                    'created_at' => $now,
                ]);
            }

            return count($newItems);
        });
    }

    public function create(int $userId, string $content): Memory
    {
        $normalized = trim($content);
        try {
            $saved = $this->save($userId, [$normalized], 'manual');
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new MemoryAlreadyExists;
            }
            throw $e;
        }
        if ($saved === 0) {
            throw new MemoryAlreadyExists;
        }

        return Memory::query()
            ->where('user_id', $userId)
            ->where('content', $normalized)
            ->firstOrFail();
    }

    /**
     * @return list<Memory>
     */
    public function list(int $userId): array
    {
        return Memory::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function delete(int $memoryId, int $userId): bool
    {
        return DB::transaction(function () use ($memoryId, $userId) {
            User::query()->where('id', $userId)->lockForUpdate()->first();
            $memory = Memory::query()->find($memoryId);
            if (! $memory || (int) $memory->user_id !== $userId) {
                return false;
            }
            $memory->delete();

            return true;
        });
    }

    public function clear(int $userId): int
    {
        return DB::transaction(function () use ($userId) {
            User::query()->where('id', $userId)->lockForUpdate()->first();

            return Memory::query()->where('user_id', $userId)->delete();
        });
    }
}
