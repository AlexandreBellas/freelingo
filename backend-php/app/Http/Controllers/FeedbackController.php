<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\FeedbackComment;
use App\Models\FeedbackEntry;
use App\Models\FeedbackReadState;
use App\Models\FeedbackVote;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Support\Iso;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class FeedbackController extends Controller
{
    use ResolvesApiUser;

    public function __construct(private readonly EmailService $email) {}

    public function unread(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        return response()->json(['unread_count' => count($this->unreadIds($user->id))]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $type = QueryParams::optionalEnum($request, 'type', ['feature', 'bug']);
        $status = QueryParams::optionalEnum($request, 'status', ['pending', 'planned', 'in_progress', 'done', 'declined']);
        $q = QueryParams::boundedString($request, 'q', null, 100);
        $sort = QueryParams::enum($request, 'sort', 'votes', ['votes', 'date']);
        $order = QueryParams::enum($request, 'order', 'desc', ['asc', 'desc']);
        $skip = QueryParams::int($request, 'skip', 0, 0);
        $limit = QueryParams::int($request, 'limit', 10, 1, 100);

        $query = FeedbackEntry::query();
        if ($type) {
            $query->where('feedback_entries.type', $type);
        }
        if ($status) {
            $query->where('feedback_entries.status', $status);
        } else {
            $query->whereNotIn('feedback_entries.status', ['done', 'declined']);
        }
        if ($q !== null && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->join('users', 'feedback_entries.author_id', '=', 'users.id')
                ->where(function ($inner) use ($term) {
                    $inner->where('feedback_entries.title', 'like', $term)
                        ->orWhere('feedback_entries.description', 'like', $term)
                        ->orWhere('users.username', 'like', $term)
                        ->orWhere('users.display_name', 'like', $term);
                })
                ->select('feedback_entries.*');
        }
        $column = $sort === 'votes' ? 'feedback_entries.vote_count' : 'feedback_entries.created_at';
        $total = (clone $query)->count();
        $entries = $query->orderBy($column, $order)->orderBy('feedback_entries.id', $order)->skip($skip)->take($limit)->get();

        return response()->json([
            'items' => $this->presentMany($entries->all(), $user),
            'total' => $total,
            'skip' => $skip,
            'limit' => $limit,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->json()->all();
        $type = is_array($data) ? ($data['type'] ?? null) : null;
        $title = is_array($data) ? ($data['title'] ?? null) : null;
        $description = is_array($data) ? ($data['description'] ?? null) : null;
        if (! in_array($type, ['feature', 'bug'], true) || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 5000) {
            throw new ApiException('Validation error', 422);
        }
        $now = Carbon::now('UTC');
        $entry = FeedbackEntry::query()->create([
            'type' => $type,
            'title' => trim($title),
            'description' => trim($description),
            'status' => 'pending',
            'author_id' => $user->id,
            'vote_count' => 0,
            'created_at' => $now,
        ]);
        $this->markRead($entry->id, $user->id, $now);
        $locale = User::query()->where('role', 'admin')->orderBy('id')->value('native_language') ?: 'en';
        $this->email->sendFeedbackNotification($entry->type, $entry->title, $entry->description, $user->username, $entry->id, $locale);

        return response()->json($this->present($entry, $user), 201);
    }

    public function markReadEndpoint(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        $this->entryOrFail($entryId);
        $state = $this->markRead($entryId, $user->id);

        return response()->json([
            'entry_id' => $entryId,
            'last_read_at' => Iso::string($state->last_read_at),
        ]);
    }

    public function show(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        $entry = $this->entryOrFail($entryId);
        $payload = $this->present($entry, $user);
        $comments = FeedbackComment::query()->where('entry_id', $entryId)->orderBy('created_at')->get();
        $payload['comments'] = $this->presentComments($comments->all());

        return response()->json($payload);
    }

    public function destroy(Request $request, int $entry_id): Response
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        $entry = $this->entryOrFail($entryId);
        if ((int) $entry->author_id !== $user->id && $user->role !== 'admin') {
            throw new ApiException('forbidden', 403);
        }
        $entry->delete();

        return response()->noContent();
    }

    public function vote(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        $entry = $this->entryOrFail($entryId);
        if ($entry->type !== 'feature') {
            throw new ApiException('only_features_are_voteable', 400);
        }
        $existing = FeedbackVote::query()->where('entry_id', $entryId)->where('user_id', $user->id)->first();
        if ($existing) {
            $existing->delete();
            $entry->vote_count = max(0, (int) $entry->vote_count - 1);
            $voted = false;
        } else {
            FeedbackVote::query()->create([
                'entry_id' => $entryId,
                'user_id' => $user->id,
                'created_at' => Carbon::now('UTC'),
            ]);
            $entry->vote_count = (int) $entry->vote_count + 1;
            $voted = true;
        }
        $entry->save();

        return response()->json(['voted' => $voted, 'vote_count' => (int) $entry->vote_count]);
    }

    public function updateStatus(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        if ($user->role !== 'admin') {
            throw new ApiException('Admin access required', 403);
        }
        $data = $request->json()->all();
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        if (! in_array($status, ['pending', 'planned', 'in_progress', 'done', 'declined'], true)) {
            throw new ApiException('Validation error', 422);
        }
        $entry = $this->entryOrFail($entryId);
        $entry->status = $status;
        $entry->save();

        return response()->json($this->present($entry->refresh(), $user));
    }

    public function comments(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $this->apiUser($request);
        $this->entryOrFail($entryId);
        $comments = FeedbackComment::query()->where('entry_id', $entryId)->orderBy('created_at')->get();
        $items = $this->presentComments($comments->all());

        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    public function addComment(Request $request, int $entry_id): JsonResponse
    {
        $entryId = $entry_id;
        $user = $this->apiUser($request);
        $this->entryOrFail($entryId);
        $body = $request->json('body');
        if (! is_string($body) || trim($body) === '' || mb_strlen($body) > 2000) {
            throw new ApiException('Validation error', 422);
        }
        $now = Carbon::now('UTC');
        $comment = FeedbackComment::query()->create([
            'entry_id' => $entryId,
            'author_id' => $user->id,
            'body' => trim($body),
            'created_at' => $now,
        ]);
        $this->markRead($entryId, $user->id, $now);

        return response()->json([
            'id' => $comment->id,
            'entry_id' => $comment->entry_id,
            'author' => $this->author($user),
            'body' => $comment->body,
            'created_at' => Iso::string($comment->created_at),
        ], 201);
    }

    public function deleteComment(Request $request, int $entry_id, int $comment_id): Response
    {
        $entryId = $entry_id;
        $commentId = $comment_id;
        $user = $this->apiUser($request);
        $this->entryOrFail($entryId);
        $comment = FeedbackComment::query()->find($commentId);
        if (! $comment || (int) $comment->entry_id !== $entryId) {
            throw new ApiException('comment_not_found', 404);
        }
        if ((int) $comment->author_id !== $user->id && $user->role !== 'admin') {
            throw new ApiException('forbidden', 403);
        }
        $comment->delete();

        return response()->noContent();
    }

    private function entryOrFail(int $entryId): FeedbackEntry
    {
        $entry = FeedbackEntry::query()->find($entryId);
        if (! $entry) {
            throw new ApiException('feedback_entry_not_found', 404);
        }

        return $entry;
    }

    private function markRead(int $entryId, int $userId, ?Carbon $readAt = null): FeedbackReadState
    {
        $now = $readAt ?? Carbon::now('UTC');
        $state = FeedbackReadState::query()->where('entry_id', $entryId)->where('user_id', $userId)->first();
        if ($state) {
            $state->last_read_at = $now;
            $state->save();

            return $state->refresh();
        }

        return FeedbackReadState::query()->create([
            'entry_id' => $entryId,
            'user_id' => $userId,
            'last_read_at' => $now,
        ]);
    }

    /**
     * @return list<int>
     */
    private function unreadIds(int $userId, ?array $only = null): array
    {
        $query = FeedbackEntry::query();
        if ($only !== null) {
            if ($only === []) {
                return [];
            }
            $query->whereIn('id', $only);
        }
        $entries = $query->get();
        if ($entries->isEmpty()) {
            return [];
        }
        $ids = $entries->pluck('id')->all();
        $reads = FeedbackReadState::query()->where('user_id', $userId)->whereIn('entry_id', $ids)->get()->keyBy('entry_id');
        $comments = FeedbackComment::query()->whereIn('entry_id', $ids)->where('author_id', '!=', $userId)->get()->groupBy('entry_id');
        $unread = [];
        foreach ($entries as $entry) {
            $readAt = $reads->get($entry->id)?->last_read_at;
            $entryUnread = (int) $entry->author_id !== $userId && ($readAt === null || $entry->created_at->gt($readAt));
            $commentUnread = false;
            foreach ($comments->get($entry->id, []) as $comment) {
                if ($readAt === null || $comment->created_at->gt($readAt)) {
                    $commentUnread = true;
                    break;
                }
            }
            if ($entryUnread || $commentUnread) {
                $unread[] = (int) $entry->id;
            }
        }

        return $unread;
    }

    /**
     * @param  list<FeedbackEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function presentMany(array $entries, User $user): array
    {
        if ($entries === []) {
            return [];
        }
        $entryIds = array_map(fn (FeedbackEntry $entry) => $entry->id, $entries);
        $authors = User::query()->whereIn('id', array_unique(array_map(fn (FeedbackEntry $entry) => $entry->author_id, $entries)))->get()->keyBy('id');
        $voted = array_map('intval', FeedbackVote::query()->whereIn('entry_id', $entryIds)->where('user_id', $user->id)->pluck('entry_id')->all());
        $counts = FeedbackComment::query()->whereIn('entry_id', $entryIds)->selectRaw('entry_id, COUNT(*) as cnt')->groupBy('entry_id')->pluck('cnt', 'entry_id');
        $unread = $this->unreadIds($user->id, $entryIds);
        $items = [];
        foreach ($entries as $entry) {
            $author = $authors->get($entry->author_id);
            if (! $author) {
                continue;
            }
            $items[] = $this->payload($entry, $author, in_array((int) $entry->id, $voted, true), in_array((int) $entry->id, $unread, true), (int) ($counts[$entry->id] ?? 0));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FeedbackEntry $entry, User $user): array
    {
        $author = User::query()->find($entry->author_id);
        $voted = FeedbackVote::query()->where('entry_id', $entry->id)->where('user_id', $user->id)->exists();
        $count = FeedbackComment::query()->where('entry_id', $entry->id)->count();
        $unread = in_array($entry->id, $this->unreadIds($user->id, [$entry->id]), true);

        return $this->payload($entry, $author, $voted, $unread, $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FeedbackEntry $entry, ?User $author, bool $voted, bool $unread, int $comments): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type,
            'title' => $entry->title,
            'description' => $entry->description,
            'status' => $entry->status,
            'author' => $author ? $this->author($author) : null,
            'vote_count' => (int) $entry->vote_count,
            'voted_by_me' => $voted,
            'unread_by_me' => $unread,
            'comment_count' => $comments,
            'created_at' => Iso::string($entry->created_at),
        ];
    }

    /**
     * @param  list<FeedbackComment>  $comments
     * @return list<array<string, mixed>>
     */
    private function presentComments(array $comments): array
    {
        if ($comments === []) {
            return [];
        }
        $authors = User::query()->whereIn('id', array_unique(array_map(fn (FeedbackComment $comment) => $comment->author_id, $comments)))->get()->keyBy('id');
        $items = [];
        foreach ($comments as $comment) {
            $author = $authors->get($comment->author_id);
            if (! $author) {
                continue;
            }
            $items[] = [
                'id' => $comment->id,
                'entry_id' => $comment->entry_id,
                'author' => $this->author($author),
                'body' => $comment->body,
                'created_at' => Iso::string($comment->created_at),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function author(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'role' => $user->role,
        ];
    }
}
