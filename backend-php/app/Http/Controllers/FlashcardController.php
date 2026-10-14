<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Flashcard;
use App\Models\FlashcardReviewReceipt;
use App\Services\Learning\ActivePlan;
use App\Services\Learning\FlashcardService;
use App\Services\Learning\LessonVoicePractice;
use App\Services\Learning\Presenters;
use App\Services\Learning\ProgressService;
use App\Services\Learning\RequestChecks;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmTimeoutException;
use App\Services\Llm\LlmUnavailableException;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class FlashcardController extends Controller
{
    public function __construct(
        private readonly ActivePlan $plans,
        private readonly FlashcardService $cards,
        private readonly ProgressService $progress,
        private readonly LessonVoicePractice $practice,
    ) {}

    public function due(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->required($user);
        $due = Flashcard::query()
            ->where('user_id', $user->id)
            ->where('study_plan_id', $plan->id)
            ->whereDate('next_review', '<=', Clock::today())
            ->where('is_suspended', false)
            ->orderBy('next_review')
            ->orderBy('id')
            ->get();
        $total = Flashcard::query()->where('user_id', $user->id)->where('study_plan_id', $plan->id)->count();

        return response()->json([
            'due' => $due->map(fn (Flashcard $card) => Presenters::card($card))->all(),
            'total' => $total,
            'cefr_level' => $plan->cefr_level,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->required($user);
        $rows = Flashcard::query()
            ->where('user_id', $user->id)
            ->where('study_plan_id', $plan->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rows->map(fn (Flashcard $card) => Presenters::card($card))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $content = RequestChecks::flashcardContent(RequestChecks::body($request->json()->all()));
        $plan = $this->plans->required($user);
        $card = $this->makeCard($user->id, $plan->id, $content);

        return response()->json(Presenters::card($card));
    }

    public function bulk(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $planId = RequestChecks::strictInt($data, 'study_plan_id', 1, 2147483647);
        $language = RequestChecks::requireString($data, 'target_language', false);
        RequestChecks::maxChars($language, 10, ['body', 'target_language']);
        if (! isset($data['flashcards']) || ! is_array($data['flashcards']) || ! array_is_list($data['flashcards'])) {
            RequestChecks::fail(['body', 'flashcards']);
        }
        $items = [];
        foreach ($data['flashcards'] as $index => $item) {
            if (! is_array($item)) {
                RequestChecks::fail(['body', 'flashcards', $index]);
            }
            $items[] = RequestChecks::flashcardContent($item, ['body', 'flashcards', $index]);
        }
        $plan = $this->plans->required($user);
        if ($plan->id !== $planId || $plan->target_language !== $language) {
            throw new ApiException('flashcard_context_changed', 409);
        }
        $existing = [];
        foreach (Flashcard::query()->where('user_id', $user->id)->where('study_plan_id', $plan->id)->pluck('word') as $word) {
            $existing[$this->cards->normalizeWord((string) $word)] = true;
        }
        $created = 0;
        foreach ($items as $item) {
            $normalized = $this->cards->normalizeWord($item['word']);
            if ($normalized === '' || isset($existing[$normalized])) {
                continue;
            }
            $item['word'] = trim($item['word']);
            $this->makeCard($user->id, $plan->id, $item);
            $existing[$normalized] = true;
            $created++;
        }

        return response()->json(['created' => $created]);
    }

    public function review(Request $request, int $cardId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $quality = RequestChecks::strictInt($data, 'quality', 0, 5);
        $reviewId = RequestChecks::uuid($data['review_id'] ?? null, ['body', 'review_id']);
        $owner = Flashcard::query()->where('id', $cardId)->where('user_id', $user->id)->value('study_plan_id');
        if ($owner === null) {
            throw new ApiException('Flashcard not found', 404);
        }
        return DB::transaction(function () use ($request, $user, $cardId, $quality, $reviewId, $owner) {
            $this->progress->lockPlan($user->id, (int) $owner);
            $card = Flashcard::query()->where('id', $cardId)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $card) {
                throw new ApiException('Flashcard not found', 404);
            }
            $receipt = FlashcardReviewReceipt::query()
                ->where('flashcard_id', $card->id)
                ->where('review_id', $reviewId)
                ->first();
            if ($receipt) {
                if ((int) $receipt->quality !== $quality) {
                    throw new ApiException('flashcard_review_conflict', 409);
                }
                $request->attributes->set('flashcard_review_replayed', true);

                return response()->json($receipt->response);
            }
            if ($card->is_suspended) {
                throw new ApiException('flashcard_suspended', 409);
            }
            $this->cards->sm2($card, $quality);
            $card->save();
            $request->attributes->set('vocabulary_reviewed', $card->source === 'from_text');
            $this->progress->updateDaily(
                $user->id,
                $card->study_plan_id,
                flashcardReviewed: true,
                skill: 'vocabulary',
                skillScore: min($quality / 5.0, 1.0),
            );
            $payload = Presenters::card($card->refresh(), ['xp_earned' => ProgressService::XP_FLASHCARD_REVIEW]);
            FlashcardReviewReceipt::query()->create([
                'flashcard_id' => $card->id,
                'review_id' => $reviewId,
                'quality' => $quality,
                'response' => $payload,
            ]);

            return response()->json($payload);
        });
    }

    public function generate(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $topic = RequestChecks::requireString($data, 'topic', false);
        $count = RequestChecks::intWithDefault($data, 'count', 5, 1, 20);
        $cefr = RequestChecks::optionalString($data, 'cefr_level', 'B1');
        $plan = $this->plans->required($user);
        try {
            $cards = app(FlashcardService::class);
            $existing = [];
            foreach (Flashcard::query()->where('user_id', $user->id)->where('study_plan_id', $plan->id)->pluck('word') as $word) {
                $existing[$cards->normalizeWord((string) $word)] = true;
            }
            $result = $cards->generate($topic, $count, $cefr, $user->native_language, $plan->target_language);
            $unique = [];
            $skippedDuplicates = 0;
            $skippedEmpty = 0;
            $seen = [];
            foreach ($result['flashcards'] as $card) {
                $normalized = $cards->normalizeWord($card['word']);
                if ($normalized === '') {
                    $skippedEmpty++;

                    continue;
                }
                if (isset($existing[$normalized]) || isset($seen[$normalized])) {
                    $skippedDuplicates++;

                    continue;
                }
                $seen[$normalized] = true;
                $existing[$normalized] = true;
                $card['word'] = trim($card['word']);
                $unique[] = $card;
                $this->makeCard($user->id, $plan->id, $card);
            }

            return response()->json([
                'flashcards' => $unique,
                'created' => count($unique),
                'skipped_duplicates' => $skippedDuplicates,
                'skipped_empty' => $skippedEmpty,
            ]);
        } catch (LlmTimeoutException) {
            throw new ApiException('The AI model took too long.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        }
    }

    public function fromWord(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $word = RequestChecks::requireString($data, 'word');
        RequestChecks::maxChars($word, 100, ['body', 'word']);
        $context = RequestChecks::optionalString($data, 'context', '');
        $cefr = RequestChecks::optionalString($data, 'cefr_level', 'B1');
        $lessonId = null;
        if (array_key_exists('lesson_id', $data) && $data['lesson_id'] !== null) {
            $lessonId = RequestChecks::strictInt($data, 'lesson_id', 1, 2147483647);
        }
        if ($lessonId !== null) {
            $source = $this->practice->source($user->id, $lessonId);
            if (! $source) {
                throw new ApiException('Lesson not found', 404);
            }
            [, $plan] = $source;
            $cefr = $plan->cefr_level;
        } else {
            $plan = $this->plans->required($user);
        }
        $existing = $this->findExisting($user->id, $plan->id, $word);
        if ($existing) {
            $response = $this->existingResponse($existing);
            if ($response) {
                return response()->json($response);
            }
        }
        try {
            $cardData = app(FlashcardService::class)->lookup(trim($word), $context, $cefr, $user->native_language, $plan->target_language);
        } catch (LlmTimeoutException) {
            throw new ApiException('The AI model took too long.', 504);
        } catch (LlmUnavailableException) {
            throw new ApiException('ai_service_unavailable', 503);
        } catch (LlmException) {
            throw new ApiException('ai_service_error', 502);
        }
        $existing = $this->findExisting($user->id, $plan->id, $cardData['word']);
        if ($existing) {
            $response = $this->existingResponse($existing);
            if ($response) {
                return response()->json($response);
            }
        }
        $card = $this->makeCard($user->id, $plan->id, $cardData, 'from_text');

        return response()->json(Presenters::card($card, ['already_saved' => false]));
    }

    public function vocabulary(Request $request): JsonResponse
    {
        return $this->library($request, true);
    }

    public function library(Request $request, bool $vocabularyOnly = false): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->required($user);
        $page = $request->query('page', 1);
        $limit = $request->query('limit', 10);
        if (! is_numeric($page) || (int) $page != $page || (int) $page < 1) {
            RequestChecks::fail(['query', 'page']);
        }
        if (! is_numeric($limit) || (int) $limit != $limit || (int) $limit < 1 || (int) $limit > 100) {
            RequestChecks::fail(['query', 'limit']);
        }
        $page = (int) $page;
        $limit = (int) $limit;
        $search = (string) $request->query('search', '');
        $query = Flashcard::query()->where('user_id', $user->id)->where('study_plan_id', $plan->id);
        if ($vocabularyOnly) {
            $query->where('source', 'from_text');
            if ($search !== '') {
                $query->whereRaw('lower(word) like ?', ['%'.mb_strtolower($search).'%']);
            }
            $total = (clone $query)->count();
            $items = $query->orderByRaw('lower(word)')->offset(($page - 1) * $limit)->limit($limit)->get();
            $pages = max(1, (int) ceil($total / $limit));

            return response()->json([
                'items' => $items->map(fn (Flashcard $card) => Presenters::card($card))->all(),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
            ]);
        }
        if (mb_strlen($search) > 255) {
            RequestChecks::fail(['query', 'search']);
        }
        $source = (string) $request->query('source', 'all');
        $status = (string) $request->query('status', 'all');
        if (! in_array($source, ['all', 'from_text'], true)) {
            RequestChecks::fail(['query', 'source']);
        }
        if (! in_array($status, ['all', 'due', 'new', 'suspended'], true)) {
            RequestChecks::fail(['query', 'status']);
        }
        if ($source === 'from_text') {
            $query->where('source', 'from_text');
        }
        if ($status === 'suspended') {
            $query->where('is_suspended', true);
        } elseif (in_array($status, ['due', 'new'], true)) {
            $query->where('is_suspended', false);
            if ($status === 'due') {
                $query->whereDate('next_review', '<=', Clock::today());
            } else {
                $query->where('interval', 0)->where('repetitions', 0);
            }
        }
        if (trim($search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search));
            $needle = '%'.mb_strtolower($escaped).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw("lower(word) like ? escape '\\'", [$needle])
                    ->orWhereRaw("lower(translation) like ? escape '\\'", [$needle]);
            });
        }
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $items = $query->orderByRaw('lower(word)')->orderBy('id')->offset(($page - 1) * $limit)->limit($limit)->get();

        return response()->json([
            'items' => $items->map(fn (Flashcard $card) => Presenters::card($card))->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));

        return response()->json(['back_content' => $user->flashcard_back_content ?: 'definition']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        RequestChecks::forbidExtra($data, ['back_content']);
        $value = RequestChecks::requireString($data, 'back_content', false);
        if (! in_array($value, ['definition', 'translation'], true)) {
            RequestChecks::fail(['body', 'back_content']);
        }
        $user->flashcard_back_content = $value;
        $user->save();

        return response()->json(['back_content' => $value]);
    }

    public function update(Request $request, int $cardId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        RequestChecks::forbidExtra($data, ['word', 'definition', 'example_sentence', 'translation']);
        foreach (['word', 'definition', 'example_sentence', 'translation'] as $field) {
            if (! array_key_exists($field, $data)) {
                RequestChecks::fail(['body', $field]);
            }
            if ($data[$field] === null && in_array($field, ['example_sentence', 'translation'], true)) {
                $data[$field] = '';
            }
            if (! is_string($data[$field])) {
                RequestChecks::fail(['body', $field]);
            }
            $data[$field] = trim($data[$field]);
        }
        if ($data['word'] === '' || $data['definition'] === '') {
            RequestChecks::fail(['body', $data['word'] === '' ? 'word' : 'definition']);
        }
        RequestChecks::maxChars($data['word'], 100, ['body', 'word']);
        RequestChecks::maxChars($data['definition'], 10000, ['body', 'definition']);
        RequestChecks::maxChars($data['example_sentence'], 10000, ['body', 'example_sentence']);
        RequestChecks::maxChars($data['translation'], 255, ['body', 'translation']);
        $card = $this->owned($user->id, $cardId);
        if ($this->cards->normalizeWord($data['word']) !== $this->cards->normalizeWord($card->word)) {
            $duplicate = $this->findExisting($user->id, $card->study_plan_id, $data['word']);
            if ($duplicate && $duplicate->id !== $card->id) {
                throw new ApiException('flashcard_duplicate', 409);
            }
        }
        $card->fill($data);
        $card->save();

        return response()->json(Presenters::card($card));
    }

    public function suspension(Request $request, int $cardId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        RequestChecks::forbidExtra($data, ['is_suspended']);
        if (! array_key_exists('is_suspended', $data) || ! is_bool($data['is_suspended'])) {
            RequestChecks::fail(['body', 'is_suspended']);
        }
        $card = $this->owned($user->id, $cardId);
        $card->is_suspended = $data['is_suspended'];
        $card->save();

        return response()->json(Presenters::card($card));
    }

    public function destroy(Request $request, int $cardId): Response
    {
        $user = Presenters::user($request->attributes->get('user'));
        $card = $this->owned($user->id, $cardId);
        $card->delete();

        return response()->noContent();
    }

    /**
     * @param  array{word: string, definition: string, example_sentence: string, translation: string}  $content
     */
    private function makeCard(int $userId, int $planId, array $content, ?string $source = null): Flashcard
    {
        return Flashcard::query()->create([
            'user_id' => $userId,
            'study_plan_id' => $planId,
            'word' => $content['word'],
            'definition' => $content['definition'],
            'example_sentence' => $content['example_sentence'],
            'translation' => $content['translation'],
            'source' => $source,
            'is_suspended' => false,
            'ease_factor' => 2.5,
            'interval' => 0,
            'repetitions' => 0,
            'next_review' => Clock::today(),
            'created_at' => Clock::now(),
        ]);
    }

    private function owned(int $userId, int $cardId): Flashcard
    {
        $card = Flashcard::query()->where('id', $cardId)->where('user_id', $userId)->first();
        if (! $card) {
            throw new ApiException('Flashcard not found', 404);
        }

        return $card;
    }

    private function findExisting(int $userId, int $planId, string $word): ?Flashcard
    {
        $normalized = $this->cards->normalizeWord($word);
        if ($normalized === '') {
            return null;
        }
        foreach (Flashcard::query()->where('user_id', $userId)->where('study_plan_id', $planId)->orderBy('id')->get() as $card) {
            if ($this->cards->normalizeWord($card->word) === $normalized) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingResponse(Flashcard $card): ?array
    {
        $already = $card->source === 'from_text';
        if (! $already) {
            $updated = Flashcard::query()->where('id', $card->id)->where('user_id', $card->user_id)->update(['source' => 'from_text']);
            if ($updated === 0) {
                return null;
            }
            $card->refresh();
        }

        return Presenters::card($card, ['already_saved' => $already]);
    }
}
