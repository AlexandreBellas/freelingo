<?php

namespace App\Http\Controllers;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Services\Learning\ActivePlan;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\Presenters;
use App\Services\Learning\ProgressService;
use App\Support\Clock;
use App\Support\JsonDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(
        private readonly ActivePlan $plans,
        private readonly ProgressService $progress,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->optional($user);
        $empty = [
            'total_xp' => 0,
            'current_streak' => 0,
            'total_lessons' => 0,
            'total_exercises' => 0,
            'exercises_correct' => 0,
            'accuracy' => 0.0,
            'skills' => new \stdClass,
            'today_xp' => 0,
            'activity_week' => $this->activityWeek([]),
            'vocabulary_level' => null,
            'vocabulary_mastered' => 0,
            'vocabulary_total' => 0,
            'vocabulary_progress' => 0.0,
        ];
        if (! $plan) {
            return response()->json($empty);
        }
        [$mastered, $total, $ratio] = $this->vocabulary($user->id, $plan);
        $entries = Progress::query()->where('study_plan_id', $plan->id)->orderByDesc('date')->get()->all();
        $vocab = [
            'vocabulary_level' => $plan->cefr_level,
            'vocabulary_mastered' => $mastered,
            'vocabulary_total' => $total,
            'vocabulary_progress' => round($ratio, 2, PHP_ROUND_HALF_EVEN),
        ];
        if ($entries === []) {
            return response()->json(array_merge($empty, $vocab));
        }
        $totalXp = array_sum(array_map(fn (Progress $entry) => $entry->xp_earned, $entries));
        $lessons = array_sum(array_map(fn (Progress $entry) => $entry->lessons_completed, $entries));
        $exercises = array_sum(array_map(fn (Progress $entry) => $entry->exercises_total, $entries));
        $correct = array_sum(array_map(fn (Progress $entry) => $entry->exercises_correct, $entries));
        $skills = $entries[0]->skills ?? [];

        return response()->json([
            'total_xp' => $totalXp,
            'current_streak' => $this->progress->currentStreak($entries),
            'total_lessons' => $lessons,
            'total_exercises' => $exercises,
            'exercises_correct' => $correct,
            'accuracy' => $exercises > 0 ? round($correct / $exercises, 2, PHP_ROUND_HALF_EVEN) : 0.0,
            'skills' => $skills === [] ? new \stdClass : $skills,
            'today_xp' => JsonDate::date($entries[0]->date) === $this->progress->today() ? $entries[0]->xp_earned : 0,
            'activity_week' => $this->activityWeek($entries),
            ...$vocab,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->optional($user);
        if (! $plan) {
            return response()->json(['entries' => []]);
        }
        $entries = Progress::query()->where('study_plan_id', $plan->id)->orderByDesc('date')->limit(90)->get();

        return response()->json(['entries' => $entries->map(fn (Progress $entry) => Presenters::progress($entry))->all()]);
    }

    public function competencies(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->optional($user);
        if (! $plan) {
            return response()->json([]);
        }

        return response()->json($this->progress->unitCompetencies($user->id, $plan->id));
    }

    /**
     * @param  list<Progress>  $entries
     * @return list<array{date: string, active: bool, xp: int}>
     */
    private function activityWeek(array $entries): array
    {
        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[JsonDate::date($entry->date)] = $entry;
        }
        $days = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $day = Clock::now()->subDays($offset)->format('Y-m-d');
            $days[] = [
                'date' => $day,
                'active' => isset($byDate[$day]),
                'xp' => isset($byDate[$day]) ? $byDate[$day]->xp_earned : 0,
            ];
        }

        return $days;
    }

    /**
     * @return array{0: int, 1: int, 2: float}
     */
    private function vocabulary(int $userId, \App\Models\StudyPlan $plan): array
    {
        $words = LearningCatalog::vocabularyWords($plan->cefr_level, $plan->target_language);
        $total = count($words);
        if ($total === 0) {
            return [0, 0, 0.0];
        }
        $mastered = Flashcard::query()
            ->where('user_id', $userId)
            ->where('study_plan_id', $plan->id)
            ->where('repetitions', '>', 0)
            ->pluck('word')
            ->map(fn ($word) => mb_strtolower(trim((string) $word)))
            ->all();
        $known = array_fill_keys($mastered, true);
        $count = 0;
        foreach ($words as $word) {
            if (isset($known[mb_strtolower(trim($word))])) {
                $count++;
            }
        }

        return [$count, $total, $count / $total];
    }
}
