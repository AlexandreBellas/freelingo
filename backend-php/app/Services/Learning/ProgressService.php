<?php

namespace App\Services\Learning;

use App\Models\Progress;
use App\Models\StudyPlan;
use App\Models\UserCompetency;
use App\Support\Clock;
use App\Support\JsonDate;
use Illuminate\Support\Carbon;

final class ProgressService
{
    public const XP_LESSON_COMPLETE = 20;

    public const XP_EXERCISE_CORRECT = 5;

    public const XP_EXERCISE_WRONG = 1;

    public const XP_FLASHCARD_REVIEW = 2;

    public function today(): string
    {
        return Clock::today();
    }

    /**
     * @param  list<Progress>  $entries  newest first
     */
    public function currentStreak(array $entries): int
    {
        if ($entries === []) {
            return 0;
        }
        $latest = JsonDate::date($entries[0]->date);
        $yesterday = Clock::now()->subDay()->format('Y-m-d');
        if ($latest < $yesterday) {
            return 0;
        }

        return (int) $entries[0]->streak_day;
    }

    public function lockPlan(int $userId, int $planId): ?StudyPlan
    {
        return StudyPlan::query()->where('id', $planId)->where('user_id', $userId)->first();
    }

    public function updateDaily(
        int $userId,
        ?int $studyPlanId = null,
        bool $lessonCompleted = false,
        ?bool $exerciseCorrect = null,
        bool $flashcardReviewed = false,
        int $xp = 0,
        ?string $skill = null,
        ?float $skillScore = null,
        ?string $activityDate = null,
    ): Progress {
        $today = $activityDate ?? $this->today();
        if ($studyPlanId !== null) {
            $this->lockPlan($userId, $studyPlanId);
        }
        $query = Progress::query()->where('user_id', $userId);
        if ($studyPlanId !== null) {
            $query->where('study_plan_id', $studyPlanId);
        }
        $following = (clone $query)->where('date', '>=', $today)->orderBy('date')->get()->all();
        $entry = null;
        if ($following !== [] && JsonDate::date($following[0]->date) === $today) {
            $entry = array_shift($following);
        }
        if (! $entry) {
            $yesterday = Carbon::parse($today, 'UTC')->subDay()->format('Y-m-d');
            $previous = (clone $query)->where('date', '<', $today)->orderByDesc('date')->first();
            $streak = ($previous && JsonDate::date($previous->date) === $yesterday) ? $previous->streak_day + 1 : 1;
            $entry = Progress::query()->create([
                'user_id' => $userId,
                'date' => $today,
                'xp_earned' => 0,
                'lessons_completed' => 0,
                'exercises_correct' => 0,
                'exercises_total' => 0,
                'streak_day' => $streak,
                'skills' => $previous ? ($previous->skills ?? []) : [],
                'skill_updates' => [],
                'study_plan_id' => $studyPlanId,
            ]);
        }
        if ($lessonCompleted) {
            $entry->lessons_completed += 1;
            $entry->xp_earned += self::XP_LESSON_COMPLETE;
        }
        if ($exerciseCorrect !== null) {
            $entry->exercises_total += 1;
            if ($exerciseCorrect) {
                $entry->exercises_correct += 1;
                $entry->xp_earned += self::XP_EXERCISE_CORRECT;
            } else {
                $entry->xp_earned += self::XP_EXERCISE_WRONG;
            }
        }
        if ($flashcardReviewed) {
            $entry->xp_earned += self::XP_FLASHCARD_REVIEW;
        }
        if ($xp > 0) {
            $entry->xp_earned += $xp;
        }
        if ($skill !== null && $skillScore !== null) {
            $skills = $entry->skills ?? [];
            $old = $skills[$skill] ?? $skillScore;
            $skills[$skill] = $this->blend($old, $skillScore);
            $entry->skills = $skills;
            if ($entry->skill_updates !== null) {
                $updates = $entry->skill_updates;
                $updates[$skill] = [...($updates[$skill] ?? []), $skillScore];
                $entry->skill_updates = $updates;
            }
        }
        $entry->save();
        $previous = $entry;
        foreach ($following as $later) {
            $prevDate = JsonDate::date($previous->date);
            $laterDate = JsonDate::date($later->date);
            $nextDay = Carbon::parse($prevDate, 'UTC')->addDay()->format('Y-m-d');
            $later->streak_day = $laterDate === $nextDay ? $previous->streak_day + 1 : 1;
            if ($later->skill_updates !== null) {
                $skills = $previous->skills ?? [];
                foreach ($later->skill_updates as $laterSkill => $scores) {
                    foreach ($scores as $score) {
                        $old = $skills[$laterSkill] ?? $score;
                        $skills[$laterSkill] = $this->blend($old, (float) $score);
                    }
                }
                $later->skills = $skills;
            }
            $later->save();
            $previous = $later;
        }

        return $entry->refresh();
    }

    /**
     * @param  list<string>  $texts
     */
    public function upsertUnitCompetency(int $userId, string $unitId, array $texts, float $lessonScore, ?int $studyPlanId): void
    {
        if ($unitId === '' || $texts === []) {
            return;
        }
        $now = Clock::now();
        foreach ($texts as $text) {
            $row = UserCompetency::query()
                ->where('user_id', $userId)
                ->where('unit_id', $unitId)
                ->where('competency_text', $text)
                ->where('study_plan_id', $studyPlanId)
                ->first();
            if (! $row) {
                UserCompetency::query()->create([
                    'user_id' => $userId,
                    'unit_id' => $unitId,
                    'competency_text' => $text,
                    'score' => $lessonScore,
                    'mastered' => $lessonScore >= 0.80,
                    'updated_at' => $now,
                    'study_plan_id' => $studyPlanId,
                ]);
            } else {
                $row->score = $this->blend((float) $row->score, $lessonScore);
                $row->mastered = $row->score >= 0.80;
                $row->updated_at = $now;
                $row->save();
            }
        }
    }

    /**
     * @return list<array{unit_id: string, score: float, mastered_count: int, total_count: int}>
     */
    public function unitCompetencies(int $userId, ?int $studyPlanId): array
    {
        $query = UserCompetency::query()->where('user_id', $userId);
        if ($studyPlanId !== null) {
            $query->where('study_plan_id', $studyPlanId);
        }
        $rows = $query->orderBy('id')->get();
        $scores = [];
        $mastered = [];
        foreach ($rows as $row) {
            $scores[$row->unit_id][] = (float) $row->score;
            $mastered[$row->unit_id] = ($mastered[$row->unit_id] ?? 0) + ($row->mastered ? 1 : 0);
        }
        $result = [];
        foreach ($scores as $unitId => $values) {
            $result[] = [
                'unit_id' => $unitId,
                'score' => round(array_sum($values) / count($values), 3, PHP_ROUND_HALF_EVEN),
                'mastered_count' => $mastered[$unitId] ?? 0,
                'total_count' => count($values),
            ];
        }

        return $result;
    }

    private function blend(float $old, float $score): float
    {
        return round($old * 0.7 + $score * 0.3, 3, PHP_ROUND_HALF_EVEN);
    }
}
