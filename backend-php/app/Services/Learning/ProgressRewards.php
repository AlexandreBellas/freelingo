<?php

namespace App\Services\Learning;

use App\Models\ChatHistory;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\ProgressReward;
use App\Models\StudyPlan;
use Illuminate\Support\Carbon;

final class ProgressRewards
{
    public function __construct(private readonly ProgressService $progress) {}

    public function award(
        int $userId,
        int $planId,
        string $kind,
        string $sourceKey,
        int $xp,
        string $activityDate,
        ?int $dailyLimit = null,
    ): int {
        if ($this->progress->lockPlan($userId, $planId) === null) {
            return 0;
        }
        $existing = ProgressReward::query()
            ->where('study_plan_id', $planId)
            ->where('kind', $kind)
            ->where('source_key', $sourceKey)
            ->exists();
        if ($existing) {
            return 0;
        }
        if ($dailyLimit !== null) {
            $earned = (int) ProgressReward::query()
                ->where('study_plan_id', $planId)
                ->where('kind', $kind)
                ->whereDate('date', $activityDate)
                ->sum('xp');
            if ($earned + $xp > $dailyLimit) {
                return 0;
            }
        }
        ProgressReward::query()->create([
            'user_id' => $userId,
            'study_plan_id' => $planId,
            'kind' => $kind,
            'source_key' => $sourceKey,
            'date' => $activityDate,
            'xp' => $xp,
        ]);
        $this->progress->updateDaily($userId, $planId, xp: $xp, activityDate: $activityDate);

        return $xp;
    }

    public function rewardConversation(int $responseId): void
    {
        $response = ChatHistory::query()->find($responseId);
        if (
            ! $response
            || $response->role !== 'assistant'
            || $response->reply_to_id === null
            || ! in_array($response->modality, ['chat', 'voice'], true)
            || trim((string) $response->content) === ''
            || $response->conversation_id === null
        ) {
            return;
        }
        $contribution = ChatHistory::query()->find($response->reply_to_id);
        if (
            ! $contribution
            || $contribution->role !== 'user'
            || (int) $contribution->user_id !== (int) $response->user_id
            || (int) $contribution->conversation_id !== (int) $response->conversation_id
            || $contribution->modality !== $response->modality
            || ! preg_match('/\p{L}|\p{N}/u', (string) $contribution->content)
        ) {
            return;
        }
        $conversation = Conversation::query()->find($response->conversation_id);
        if (! $conversation || $conversation->study_plan_id === null) {
            return;
        }
        if ((int) $conversation->user_id !== (int) $response->user_id) {
            return;
        }
        $plan = $this->progress->lockPlan((int) $conversation->user_id, (int) $conversation->study_plan_id);
        if (! $plan || $plan->target_language !== $conversation->target_language) {
            return;
        }
        $activityDate = Carbon::parse($response->created_at)->utc()->format('Y-m-d');
        $start = Carbon::parse($activityDate, 'UTC')->startOfDay();
        $end = $start->copy()->addDay();
        $messages = ChatHistory::query()
            ->from('chat_history as learner')
            ->join('chat_history as replies', 'replies.reply_to_id', '=', 'learner.id')
            ->where('learner.conversation_id', $conversation->id)
            ->where('learner.user_id', $conversation->user_id)
            ->where('learner.role', 'user')
            ->where('learner.modality', $response->modality)
            ->where('replies.conversation_id', $conversation->id)
            ->where('replies.user_id', $conversation->user_id)
            ->where('replies.role', 'assistant')
            ->where('replies.modality', $response->modality)
            ->where('replies.created_at', '>=', $start)
            ->where('replies.created_at', '<', $end)
            ->get(['learner.content as learner_content', 'replies.content as reply_content']);
        $answered = [];
        foreach ($messages as $message) {
            $content = (string) $message->learner_content;
            $reply = (string) $message->reply_content;
            if (trim($reply) !== '' && preg_match('/\p{L}|\p{N}/u', $content)) {
                $answered[mb_strtolower(preg_replace('/\s+/u', ' ', trim($content)) ?? '')] = true;
            }
        }
        if ($answered === []) {
            return;
        }
        $this->progress->updateDaily((int) $conversation->user_id, $plan->id, activityDate: $activityDate);
        $voice = $response->modality === 'voice';
        $blocks = $voice ? min(1, intdiv(count($answered), 3)) : min(3, intdiv(count($answered), 5));
        for ($block = 1; $block <= $blocks; $block++) {
            $this->award(
                (int) $conversation->user_id,
                $plan->id,
                $voice ? 'voice' : 'chat',
                $conversation->id.':'.$activityDate.':'.$block,
                $voice ? 20 : 10,
                $activityDate,
                $voice ? 60 : 30,
            );
        }
    }

    public function rewardPlanCompletion(int $userId, int $planId, ?string $unitId = null, ?string $activityDate = null): int
    {
        $activityDate ??= $this->progress->today();
        $plan = $this->progress->lockPlan($userId, $planId);
        if (! $plan || ($unitId === null && ! $plan->completion_test_taken)) {
            return 0;
        }
        $expected = $this->scheduledLessons($plan, $unitId);
        if ($expected === []) {
            return 0;
        }
        $lessons = Lesson::query()->where('study_plan_id', $plan->id)->where('is_completed', true)->get();
        $completed = [];
        foreach ($lessons as $lesson) {
            $completed[$lesson->week_number.'|'.$lesson->day_number.'|'.$lesson->title] = true;
        }
        foreach ($expected as $key) {
            if (! isset($completed[$key])) {
                return 0;
            }
        }

        return $this->award(
            $userId,
            $plan->id,
            $unitId ? 'unit' : 'level',
            $unitId ?: 'completion',
            $unitId ? 30 : 100,
            $activityDate,
        );
    }

    /**
     * @return list<string>
     */
    private function scheduledLessons(StudyPlan $plan, ?string $unitId): array
    {
        $planData = $plan->generated_plan ?? [];
        $keys = [];
        foreach ($planData['weekly_plan'] ?? [] as $week) {
            foreach ($week['days'] ?? [] as $day) {
                $dayUnit = $day['unit_id'] ?? null;
                if (in_array($dayUnit, LearningCatalog::COMPLETION_UNIT_IDS, true)) {
                    continue;
                }
                if ($unitId !== null && $dayUnit !== $unitId) {
                    continue;
                }
                $keys[] = $week['week'].'|'.$day['day'].'|'.$day['title'];
            }
        }

        return $keys;
    }
}
