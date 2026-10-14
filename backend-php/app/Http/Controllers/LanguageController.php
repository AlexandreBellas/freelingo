<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\ChatHistory;
use App\Models\Conversation;
use App\Models\LlmUsage;
use App\Models\Progress;
use App\Models\StudyPlan;
use App\Models\UserLanguage;
use App\Services\UserLanguageService;
use App\Support\TargetLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LanguageController extends Controller
{
    use ResolvesApiUser;

    public function __construct(private readonly UserLanguageService $languages) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $items = [];
        foreach ($this->languages->all($user->id) as $language) {
            $plan = $this->planInfo($user->id, $language->target_language);
            $items[] = [
                'target_language' => $language->target_language,
                'is_active' => (bool) $language->is_active,
                'plan' => $plan,
                'progress' => $plan ? $this->progressInfo($user->id, $language->target_language) : null,
            ];
        }

        return response()->json([
            'languages' => $items,
            'all_supported_languages' => TargetLanguages::available(),
        ]);
    }

    public function active(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $active = $this->languages->active($user->id);
        if (! $active) {
            throw new ApiException('No active language set', 404);
        }

        return response()->json([
            'target_language' => $active->target_language,
            'is_active' => (bool) $active->is_active,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $target = $this->target($request);
        $entry = $this->languages->add($user->id, $target);

        return response()->json([
            'target_language' => $entry->target_language,
            'is_active' => (bool) $entry->is_active,
        ], 201);
    }

    public function switch(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $target = $this->target($request);
        $entry = $this->languages->switch($user->id, $target);
        $user->target_language = $target;
        $user->save();

        return response()->json([
            'target_language' => $entry->target_language,
            'is_active' => (bool) $entry->is_active,
        ]);
    }

    public function destroy(Request $request, string $targetLanguage): Response
    {
        $user = $this->apiUser($request);
        $language = UserLanguage::query()
            ->where('user_id', $user->id)
            ->where('target_language', $targetLanguage)
            ->first();
        if (! $language) {
            throw new ApiException('Language not found', 404);
        }
        $count = UserLanguage::query()->where('user_id', $user->id)->count();
        if ($count <= 1) {
            throw new ApiException('Cannot delete the only language', 400);
        }
        if ($language->is_active) {
            throw new ApiException('Cannot delete the active language', 400);
        }

        DB::transaction(function () use ($language) {
            $planIds = StudyPlan::query()->where('user_language_id', $language->id)->pluck('id');
            if ($planIds->isNotEmpty()) {
                ChatHistory::query()->whereIn('study_plan_id', $planIds)->update(['reply_to_id' => null]);
                ChatHistory::query()->whereIn('study_plan_id', $planIds)->delete();
                Conversation::query()->whereIn('study_plan_id', $planIds)->delete();
                LlmUsage::query()->whereIn('study_plan_id', $planIds)->delete();
            }
            $language->delete();
        });

        return response()->noContent();
    }

    private function target(Request $request): string
    {
        $data = $request->json()->all();
        if (! is_array($data) || ! isset($data['target_language']) || ! is_string($data['target_language'])) {
            throw new ApiException('Validation error', 422);
        }
        TargetLanguages::assertAvailable($data['target_language']);

        return $data['target_language'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planInfo(int $userId, string $targetLanguage): ?array
    {
        $languageId = UserLanguage::query()
            ->where('user_id', $userId)
            ->where('target_language', $targetLanguage)
            ->value('id');
        if (! $languageId) {
            return null;
        }
        $plan = StudyPlan::query()
            ->where('user_language_id', $languageId)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();
        if (! $plan) {
            return null;
        }
        $totalDays = (int) $plan->duration_weeks * (int) $plan->days_per_week;
        $pct = $totalDays > 0 ? round(((int) $plan->progress_day / $totalDays) * 100, 1) : 0.0;

        return [
            'id' => $plan->id,
            'cefr_level' => $plan->cefr_level,
            'progress_day' => (int) $plan->progress_day,
            'total_days' => $totalDays,
            'completion_pct' => $pct,
        ];
    }

    /**
     * @return array<string, int>|null
     */
    private function progressInfo(int $userId, string $targetLanguage): ?array
    {
        $planId = StudyPlan::query()
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('user_languages.user_id', $userId)
            ->where('user_languages.target_language', $targetLanguage)
            ->where('study_plans.is_active', true)
            ->value('study_plans.id');
        if (! $planId) {
            return null;
        }
        $entries = Progress::query()->where('study_plan_id', $planId)->orderByDesc('date')->get();
        $totalXp = 0;
        $lessons = 0;
        foreach ($entries as $entry) {
            $totalXp += (int) $entry->xp_earned;
            $lessons += (int) $entry->lessons_completed;
        }

        return [
            'total_xp' => $totalXp,
            'current_streak' => $this->streak($entries->all()),
            'lessons_completed' => $lessons,
        ];
    }

    /**
     * @param  list<Progress>  $entries
     */
    private function streak(array $entries): int
    {
        if ($entries === []) {
            return 0;
        }
        $latest = $entries[0]->date;
        if (! $latest instanceof \DateTimeInterface) {
            return 0;
        }
        $cutoff = Carbon::now('UTC')->startOfDay()->subDay();
        if (Carbon::instance(\DateTime::createFromInterface($latest))->startOfDay()->lt($cutoff)) {
            return 0;
        }

        return (int) $entries[0]->streak_day;
    }
}
