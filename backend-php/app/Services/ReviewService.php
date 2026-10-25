<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class ReviewService
{
    public function __construct(private readonly UserLanguageService $languages) {}

    public function forUser(int $userId): ?Review
    {
        return Review::query()->where('user_id', $userId)->first();
    }

    public function create(User $user, int $rating, ?string $comment): Review
    {
        if ($this->forUser($user->id)) {
            throw new ApiException('review_already_exists', 409);
        }
        $active = $this->languages->active($user->id);
        $now = Carbon::now('UTC');
        try {
            return Review::query()->create([
                'user_id' => $user->id,
                'user_display_name' => $user->display_name,
                'target_language' => $active?->target_language ?? $user->target_language,
                'rating' => $rating,
                'comment' => $comment,
                'is_approved' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new ApiException('review_already_exists', 409);
            }
            throw $e;
        }
    }

    public function update(User $user, int $rating, ?string $comment): Review
    {
        $review = $this->forUser($user->id);
        if (! $review) {
            throw new ApiException('review_not_found', 404);
        }
        $active = $this->languages->active($user->id);
        $review->user_display_name = $user->display_name;
        $review->target_language = $active?->target_language ?? $user->target_language;
        $review->rating = $rating;
        $review->comment = $comment;
        $review->is_approved = false;
        $review->updated_at = Carbon::now('UTC');
        $review->save();

        return $review->refresh();
    }

    public function deleteForUser(int $userId): void
    {
        $review = $this->forUser($userId);
        if (! $review) {
            throw new ApiException('review_not_found', 404);
        }
        $review->delete();
    }

    public function findOrFail(int $reviewId): Review
    {
        $review = Review::query()->find($reviewId);
        if (! $review) {
            throw new ApiException('review_not_found', 404);
        }

        return $review;
    }

    public function setApproval(int $reviewId, bool $approved): Review
    {
        $review = $this->findOrFail($reviewId);
        $review->is_approved = $approved;
        $review->updated_at = Carbon::now('UTC');
        $review->save();

        return $review->refresh();
    }

    public function delete(int $reviewId): void
    {
        $this->findOrFail($reviewId)->delete();
    }
}
