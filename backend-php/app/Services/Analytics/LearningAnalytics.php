<?php

namespace App\Services\Analytics;

use App\Services\Redis\ArrayRedis;

class LearningAnalytics
{
    /** @var array<string, string> */
    public const PATHS = [
        'assessment_started' => '/assessment',
        'assessment_completed' => '/assessment',
        'study_plan_created' => '/plan',
        'lesson_completed' => '/lesson',
        'lingu_chat_practiced' => '/chat',
        'lingu_voice_practiced' => '/conversation',
        'listening_started' => '/listening',
        'listening_completed' => '/listening',
        'listening_replayed' => '/listening',
        'reading_started' => '/reading',
        'reading_completed' => '/reading',
        'reading_replayed' => '/reading',
        'registration_completed' => '/register',
        'email_verified' => '/verify-email',
        'flashcard_reviewed' => '/flashcards',
        'vocabulary_saved' => '/flashcards/vocabulary',
        'saved_vocabulary_reviewed' => '/flashcards/vocabulary',
        'grammar_viewed' => '/grammar',
        'grammar_help_viewed' => '/grammar',
        'vocabulary_viewed' => '/vocabulary',
        'phrasebook_viewed' => '/phrasebook',
        'phrasebook_help_viewed' => '/phrasebook',
        'language_added' => '/settings/languages',
        'language_selected' => '/settings/languages',
        'preferences_saved' => '/settings',
        'memories_viewed' => '/settings/memories',
        'memory_created' => '/settings/memories',
        'memory_deleted' => '/settings/memories',
        'memories_cleared' => '/settings/memories',
        'feedback_created' => '/feedback',
        'feedback_vote_changed' => '/feedback',
        'feedback_comment_created' => '/feedback',
        'review_submitted' => '/settings',
        'contact_sent' => '/contact',
        'tour_started' => '/dashboard',
        'tour_completed' => '/dashboard',
        'tour_skipped' => '/dashboard',
        'dashboard_lesson_clicked' => '/dashboard',
        'dashboard_plan_clicked' => '/dashboard',
        'dashboard_assessment_clicked' => '/dashboard',
        'dashboard_flashcards_clicked' => '/dashboard',
        'dashboard_chat_clicked' => '/dashboard',
        'dashboard_voice_clicked' => '/dashboard',
        'flashcard_session_started' => '/flashcards',
        'vocabulary_audio_played' => '/vocabulary',
        'phrasebook_audio_played' => '/phrasebook',
        'progress_calendar_viewed' => '/progress',
        'progress_skills_viewed' => '/progress',
        'progress_rewards_viewed' => '/progress',
        'language_selector_opened' => '/settings/languages',
        'appearance_changed' => '/settings',
        'voice_changed' => '/settings',
        'voice_preview_played' => '/settings',
        'faq_viewed' => '/faq',
        'detective_started' => '/games/error-detective',
        'detective_completed' => '/games/error-detective',
        'detective_abandoned' => '/games/error-detective',
        'detective_answer_correct' => '/games/error-detective',
        'detective_answer_incorrect' => '/games/error-detective',
        'sentence_order_started' => '/games/sentence-order',
        'sentence_order_completed' => '/games/sentence-order',
        'sentence_order_abandoned' => '/games/sentence-order',
        'sentence_order_answer_correct' => '/games/sentence-order',
        'sentence_order_answer_incorrect' => '/games/sentence-order',
        'vocabulary_pairs_started' => '/games/vocabulary-pairs',
        'vocabulary_pairs_completed' => '/games/vocabulary-pairs',
        'vocabulary_pairs_abandoned' => '/games/vocabulary-pairs',
        'vocabulary_pairs_answer_correct' => '/games/vocabulary-pairs',
        'vocabulary_pairs_answer_incorrect' => '/games/vocabulary-pairs',
    ];

    /** @var list<string> */
    public const BROWSER_EVENTS = [
        'grammar_viewed', 'tour_started', 'tour_completed', 'tour_skipped',
        'dashboard_lesson_clicked', 'dashboard_plan_clicked', 'dashboard_assessment_clicked',
        'dashboard_flashcards_clicked', 'dashboard_chat_clicked', 'dashboard_voice_clicked',
        'flashcard_session_started', 'vocabulary_audio_played', 'phrasebook_audio_played',
        'progress_calendar_viewed', 'progress_skills_viewed', 'progress_rewards_viewed',
        'language_selector_opened', 'appearance_changed', 'voice_changed', 'voice_preview_played',
        'faq_viewed',
    ];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ArrayRedis $redis,
    ) {}

    public function record(string $event, string $sourceId, string $userAgent): bool
    {
        if (! $this->analytics->enabled() || trim($userAgent) === '') {
            return false;
        }
        try {
            $namespace = in_array($event, ['lingu_chat_practiced', 'lingu_voice_practiced'], true)
                ? 'lingu_practice'
                : $event;
            $claimed = $this->redis->set('analytics:learning:'.$namespace.':'.$sourceId, '1', 'NX');
            if (! $claimed) {
                return false;
            }
            $path = self::PATHS[$event] ?? null;
            if ($path === null) {
                return false;
            }

            return $this->analytics->track($event, $userAgent, $path);
        } catch (\Throwable) {
            return false;
        }
    }
}
