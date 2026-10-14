<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\Review;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Services\ReviewService;
use App\Support\Iso;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReviewController extends Controller
{
    use ResolvesApiUser;

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly EmailService $email,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $review = $this->reviews->forUser($user->id);

        return response()->json([
            'has_review' => $review !== null,
            'review' => $review ? $this->admin($review) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        [$rating, $comment] = $this->payload($request);
        $review = $this->reviews->create($user, $rating, $comment);
        $this->email->sendReviewNotification(
            $review->user_display_name,
            (int) $review->rating,
            $review->comment,
            $review->target_language,
            $review->id,
            $this->adminLocale(),
        );

        return response()->json($this->admin($review), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        [$rating, $comment] = $this->payload($request);

        return response()->json($this->admin($this->reviews->update($user, $rating, $comment)));
    }

    public function destroyMine(Request $request): Response
    {
        $this->reviews->deleteForUser($this->apiUser($request)->id);

        return response()->noContent();
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $limit = QueryParams::int($request, 'limit', 50, 1, 50);
        $reviews = Review::query()
            ->where('is_approved', true)
            ->where('rating', '>=', 4)
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json($reviews->map(fn (Review $review) => $this->publicReview($review))->all());
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $approved = QueryParams::bool($request, 'is_approved');
        $rating = QueryParams::int($request, 'rating', 0, 1, 5);
        if (! $request->query->has('rating')) {
            $rating = null;
        }
        $language = QueryParams::boundedString($request, 'target_language', null, 10);
        $order = QueryParams::enum($request, 'order', 'desc', ['asc', 'desc']);
        $skip = QueryParams::int($request, 'skip', 0, 0, null);
        $limit = QueryParams::int($request, 'limit', 10, 1, 100);

        $query = Review::query();
        if ($approved !== null) {
            $query->where('is_approved', $approved);
        }
        if ($rating !== null) {
            $query->where('rating', $rating);
        }
        if ($language) {
            $query->where('target_language', $language);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('created_at', $order)->skip($skip)->take($limit)->get();

        return response()->json([
            'items' => $items->map(fn (Review $review) => $this->admin($review))->all(),
            'total' => $total,
            'skip' => $skip,
            'limit' => $limit,
        ]);
    }

    public function adminUpdate(Request $request, int $reviewId): JsonResponse
    {
        $data = $request->json()->all();
        if (! is_array($data) || ! array_key_exists('is_approved', $data) || ! is_bool($data['is_approved'])) {
            throw new ApiException('Validation error', 422);
        }

        return response()->json($this->admin($this->reviews->setApproval($reviewId, $data['is_approved'])));
    }

    public function adminDestroy(int $reviewId): Response
    {
        $this->reviews->delete($reviewId);

        return response()->noContent();
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function payload(Request $request): array
    {
        $data = $request->json()->all();
        if (! is_array($data) || ! array_key_exists('rating', $data) || ! is_int($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
            throw new ApiException('Validation error', 422);
        }
        $comment = null;
        if (array_key_exists('comment', $data) && $data['comment'] !== null) {
            if (! is_string($data['comment']) || mb_strlen($data['comment']) > 2000) {
                throw new ApiException('Validation error', 422);
            }
            $stripped = trim($data['comment']);
            $comment = $stripped === '' ? null : $stripped;
        }

        return [$data['rating'], $comment];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicReview(Review $review): array
    {
        return [
            'id' => $review->id,
            'user_display_name' => $review->user_display_name,
            'target_language' => $review->target_language,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'created_at' => Iso::string($review->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function admin(Review $review): array
    {
        return $this->publicReview($review) + [
            'user_id' => $review->user_id,
            'is_approved' => (bool) $review->is_approved,
            'updated_at' => Iso::string($review->updated_at),
        ];
    }

    private function adminLocale(): string
    {
        return User::query()->where('role', 'admin')->orderBy('id')->value('native_language') ?: 'en';
    }
}
