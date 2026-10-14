<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\UserLanguage;
use Illuminate\Support\Carbon;

class UserLanguageService
{
    public function active(int $userId): ?UserLanguage
    {
        return UserLanguage::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return list<UserLanguage>
     */
    public function all(int $userId): array
    {
        return UserLanguage::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    public function add(int $userId, string $targetLanguage): UserLanguage
    {
        $existing = UserLanguage::query()
            ->where('user_id', $userId)
            ->where('target_language', $targetLanguage)
            ->first();
        if ($existing) {
            throw new ApiException('Language already added', 409);
        }

        UserLanguage::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return UserLanguage::query()->create([
            'user_id' => $userId,
            'target_language' => $targetLanguage,
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    public function switch(int $userId, string $targetLanguage): UserLanguage
    {
        $row = UserLanguage::query()
            ->where('user_id', $userId)
            ->where('target_language', $targetLanguage)
            ->first();
        if (! $row) {
            throw new ApiException('Language not found for user', 404);
        }
        if ($row->is_active) {
            return $row;
        }

        UserLanguage::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
        $row->is_active = true;
        $row->save();

        return $row->refresh();
    }

    public function ensure(int $userId, string $targetLanguage): UserLanguage
    {
        $row = UserLanguage::query()
            ->where('user_id', $userId)
            ->where('target_language', $targetLanguage)
            ->first();
        if ($row) {
            return $row;
        }

        return UserLanguage::query()->create([
            'user_id' => $userId,
            'target_language' => $targetLanguage,
            'is_active' => false,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    public function remove(int $userId, string $targetLanguage): void
    {
        $rows = $this->all($userId);
        if (count($rows) <= 1) {
            throw new ApiException('Cannot delete the only language', 400);
        }
        $target = null;
        foreach ($rows as $row) {
            if ($row->target_language === $targetLanguage) {
                $target = $row;
                break;
            }
        }
        if (! $target) {
            throw new ApiException('Language not found for user', 404);
        }
        if ($target->is_active) {
            throw new ApiException('Cannot delete the active language', 400);
        }
        $target->delete();
    }
}
