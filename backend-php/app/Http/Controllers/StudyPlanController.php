<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\User;
use App\Services\Learning\ActivePlan;
use App\Services\Learning\CompletionService;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\PlanCapacityException;
use App\Services\Learning\Presenters;
use App\Services\Learning\RequestChecks;
use App\Services\Learning\StudyPlanGenerator;
use App\Services\UserLanguageService;
use App\Support\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudyPlanController extends Controller
{
    public function __construct(
        private readonly ActivePlan $plans,
        private readonly StudyPlanGenerator $generator,
        private readonly CompletionService $completion,
        private readonly LessonGenerator $lessons,
        private readonly UserLanguageService $languages,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $language = $request->query('language');
        $plan = is_string($language) && $language !== ''
            ? $this->plans->forLanguage($user, $language)
            : $this->plans->optional($user);

        if (! $plan) {
            return response()->json(null)->setData(null);
        }

        return response()->json(Presenters::plan($plan));
    }

    public function generate(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $cefr = RequestChecks::cefr($data);
        $weeks = RequestChecks::intWithDefault($data, 'duration_weeks', 12, 1, 100000);
        $days = RequestChecks::intWithDefault($data, 'days_per_week', 4, 1, 100000);
        $goals = $data['goals'] ?? ['grammar', 'vocabulary', 'reading', 'writing'];
        if (! is_array($goals)) {
            RequestChecks::fail(['body', 'goals']);
        }
        $language = $data['target_language'] ?? null;
        if ($language !== null && ! is_string($language)) {
            RequestChecks::fail(['body', 'target_language']);
        }
        if (! $language) {
            $active = $this->plans->language($user);
            $language = $active?->target_language ?: $user->target_language;
        }
        $units = LearningCatalog::units($cefr, $language);
        try {
            $this->generator->assertCapacity($units, $weeks, $days);
        } catch (PlanCapacityException $e) {
            throw new ApiException($e->getMessage(), 400);
        }
        $userLang = $this->languages->ensure($user->id, $language);
        StudyPlan::query()->where('user_language_id', $userLang->id)->where('is_active', true)->update(['is_active' => false]);
        $generated = $this->generator->generate([
            'cefr_level' => $cefr,
            'duration_weeks' => $weeks,
            'days_per_week' => $days,
        ], $language);
        $plan = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $userLang->id,
            'cefr_level' => $cefr,
            'target_language' => $language,
            'goals' => array_values($goals),
            'duration_weeks' => $weeks,
            'days_per_week' => $days,
            'current_unit' => $units[0]['id'] ?? '',
            'generated_plan' => $generated,
            'is_active' => true,
            'created_at' => Clock::now(),
        ]);

        return response()->json(Presenters::plan($plan));
    }

    public function today(Request $request): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $plan = $this->plans->required($user);
        $totalDays = $plan->duration_weeks * $plan->days_per_week;
        $all = Lesson::query()->where('study_plan_id', $plan->id)->get();
        $byDay = [];
        foreach ($all as $lesson) {
            $byDay[$lesson->week_number.'|'.$lesson->day_number][] = $lesson;
        }
        $original = $plan->progress_day;
        while ($plan->progress_day < $totalDays) {
            $week = intdiv($plan->progress_day, $plan->days_per_week) + 1;
            $day = ($plan->progress_day % $plan->days_per_week) + 1;
            $dayLessons = $byDay[$week.'|'.$day] ?? [];
            if ($dayLessons !== [] && collect($dayLessons)->every(fn (Lesson $lesson) => $lesson->is_completed)) {
                $plan->progress_day++;
            } else {
                break;
            }
        }
        if ($plan->progress_day !== $original) {
            $plan->save();
        }
        $pendingCount = $all->filter(function (Lesson $lesson) use ($plan) {
            $index = ($lesson->week_number - 1) * $plan->days_per_week + ($lesson->day_number - 1);

            return ! $lesson->is_completed
                && $index < $plan->progress_day
                && ! ($plan->completion_test_taken && in_array($lesson->unit_id, LearningCatalog::COMPLETION_UNIT_IDS, true));
        })->count();
        $completion = $this->completion->state($plan, $pendingCount > 0);
        if ($plan->progress_day >= $totalDays) {
            return response()->json($this->todayPayload($plan, [], $totalDays, $pendingCount, $completion));
        }
        $currentWeek = intdiv($plan->progress_day, $plan->days_per_week) + 1;
        $currentDay = ($plan->progress_day % $plan->days_per_week) + 1;
        $weekly = is_array($plan->generated_plan) ? ($plan->generated_plan['weekly_plan'] ?? null) : null;
        if (! $weekly) {
            throw new ApiException('Study plan data is malformed', 500);
        }
        $week = null;
        foreach ($weekly as $candidate) {
            if (($candidate['week'] ?? null) === $currentWeek) {
                $week = $candidate;
                break;
            }
        }
        if (! $week) {
            return response()->json($this->todayPayload($plan, [], $totalDays, $pendingCount, $completion));
        }
        $lessonByTitle = [];
        foreach ($byDay[$currentWeek.'|'.$currentDay] ?? [] as $row) {
            $lessonByTitle[$row->title] = $row;
        }
        $byUnit = [];
        foreach ($all->sortBy(fn (Lesson $lesson) => sprintf('%05d-%05d-%010d', $lesson->week_number, $lesson->day_number, $lesson->id)) as $lesson) {
            if ($lesson->unit_id) {
                $byUnit[$lesson->unit_id][] = $lesson;
            }
        }
        $today = [];
        foreach ($week['days'] ?? [] as $day) {
            if (($day['day'] ?? null) !== $currentDay) {
                continue;
            }
            $title = (string) ($day['title'] ?? '');
            $type = (string) ($day['lesson_type'] ?? '');
            $unitId = (string) ($day['unit_id'] ?? '');
            $existing = $lessonByTitle[$title] ?? null;
            $lessonId = $existing?->id;
            $completed = (bool) ($existing?->is_completed);
            $grammar = [];
            $vocab = [];
            if ($unitId !== '') {
                foreach (LearningCatalog::units($plan->cefr_level, $plan->target_language) as $unit) {
                    if ($unit['id'] === $unitId) {
                        $grammar = $unit['grammar_points'];
                        $vocab = $unit['vocabulary_set_ids'];
                        break;
                    }
                }
            }
            $planId = $plan->id;
            if ($lessonId === null && ! in_array($unitId, LearningCatalog::COMPLETION_UNIT_IDS, true)) {
                $previous = [];
                foreach ($byUnit[$unitId] ?? [] as $sibling) {
                    if ($sibling->title !== $title) {
                        $previous[] = [
                            'title' => $sibling->title,
                            'lesson_type' => $sibling->lesson_type,
                            'content' => $sibling->content,
                        ];
                    }
                }
                try {
                    $content = $this->lessons->generate(
                        $plan->cefr_level,
                        $type,
                        $title,
                        $currentWeek,
                        $currentDay,
                        $unitId,
                        $grammar,
                        $vocab,
                        $plan->target_language,
                        $user->native_language,
                        $previous,
                    );
                    $exercises = $content['exercises'] ?? [];
                    if ($exercises === []) {
                        throw new \RuntimeException('Lesson generated with no exercises');
                    }
                    $lesson = Lesson::query()->create([
                        'study_plan_id' => $plan->id,
                        'title' => $title,
                        'lesson_type' => $type,
                        'cefr_level' => $plan->cefr_level,
                        'week_number' => $currentWeek,
                        'day_number' => $currentDay,
                        'unit_id' => $unitId !== '' ? $unitId : null,
                        'content' => $content,
                    ]);
                    foreach ($exercises as $exercise) {
                        Exercise::query()->create([
                            'lesson_id' => $lesson->id,
                            'exercise_type' => $exercise['type'] ?? 'multiple_choice',
                            'question' => $exercise['question'] ?? '',
                            'options' => $exercise['options'] ?? null,
                            'correct_answer' => $exercise['correct'] ?? '',
                            'explanation' => $exercise['explanation'] ?? null,
                        ]);
                    }
                    $lessonId = $lesson->id;
                    if ($unitId !== '') {
                        $byUnit[$unitId][] = $lesson;
                    }
                } catch (QueryException $e) {
                    $duplicate = Lesson::query()
                        ->where('study_plan_id', $planId)
                        ->where('week_number', $currentWeek)
                        ->where('day_number', $currentDay)
                        ->where('title', $title)
                        ->first();
                    if ($duplicate && $this->isUniqueViolation($e)) {
                        $lessonId = $duplicate->id;
                        $completed = (bool) $duplicate->is_completed;
                    }
                } catch (\Throwable) {
                    // A failed generation leaves the slot unopened.
                }
            }
            if (in_array($unitId, LearningCatalog::COMPLETION_UNIT_IDS, true) && $completion['state'] === 'taken') {
                continue;
            }
            if ($lessonId !== null) {
                $today[] = [
                    'id' => $lessonId,
                    'title' => $title,
                    'lesson_type' => $type,
                    'week' => $currentWeek,
                    'day' => $currentDay,
                    'objectives' => $day['objectives'] ?? [],
                    'estimated_minutes' => $day['estimated_minutes'] ?? 0,
                    'unit_id' => $unitId,
                    'is_completed' => $completed,
                ];
            }
        }

        return response()->json($this->todayPayload($plan, $today, $totalDays, $pendingCount, $completion));
    }

    public function skip(Request $request): JsonResponse
    {
        $plan = $this->plans->required(Presenters::user($request->attributes->get('user')));
        $total = $plan->duration_weeks * $plan->days_per_week;
        $plan->progress_day = min($plan->progress_day + 1, $total);
        $plan->save();

        return response()->json(['progress_day' => $plan->progress_day, 'total_days' => $total]);
    }

    public function pending(Request $request): JsonResponse
    {
        $plan = $this->plans->required(Presenters::user($request->attributes->get('user')));
        $rows = Lesson::query()->where('study_plan_id', $plan->id)->where('is_completed', false)->get();
        $pending = [];
        foreach ($rows as $lesson) {
            $index = ($lesson->week_number - 1) * $plan->days_per_week + ($lesson->day_number - 1);
            if ($index < $plan->progress_day && ! ($plan->completion_test_taken && in_array($lesson->unit_id, LearningCatalog::COMPLETION_UNIT_IDS, true))) {
                $pending[] = [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'lesson_type' => $lesson->lesson_type,
                    'week_number' => $lesson->week_number,
                    'day_number' => $lesson->day_number,
                ];
            }
        }

        return response()->json($pending);
    }

    public function lessons(Request $request): JsonResponse
    {
        $plan = $this->plans->required(Presenters::user($request->attributes->get('user')));
        $rows = Lesson::query()
            ->where('study_plan_id', $plan->id)
            ->orderBy('week_number')
            ->orderBy('day_number')
            ->orderBy('id')
            ->get();

        return response()->json($rows->map(fn (Lesson $lesson) => [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'lesson_type' => $lesson->lesson_type,
            'week_number' => $lesson->week_number,
            'day_number' => $lesson->day_number,
            'unit_id' => $lesson->unit_id,
            'is_completed' => (bool) $lesson->is_completed,
        ])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     * @param  array<string, mixed>  $completion
     * @return array<string, mixed>
     */
    private function todayPayload(StudyPlan $plan, array $lessons, int $totalDays, int $pendingCount, array $completion): array
    {
        return [
            'plan_id' => $plan->id,
            'cefr_level' => $plan->cefr_level,
            'lessons' => $lessons,
            'progress_day' => $plan->progress_day,
            'total_days' => $totalDays,
            'pending_count' => $pendingCount,
            'completion' => $completion,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
