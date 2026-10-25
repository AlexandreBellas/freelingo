<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HttpAnalytics
{
    /** @var array<string, string> */
    private const SUCCESS = [
        'POST /api/auth/register' => 'registration_completed',
        'GET /api/auth/verify-email' => 'email_verified',
        'POST /api/flashcards/{card_id}/review' => 'flashcard_reviewed',
        'POST /api/grammar/{slug}/native-help' => 'grammar_help_viewed',
        'GET /api/vocabulary/{set_id}' => 'vocabulary_viewed',
        'GET /api/phrasebook' => 'phrasebook_viewed',
        'POST /api/phrasebook/{category_id}/native-help' => 'phrasebook_help_viewed',
        'POST /api/languages' => 'language_added',
        'PUT /api/languages/active' => 'language_selected',
        'GET /api/memories' => 'memories_viewed',
        'POST /api/memories' => 'memory_created',
        'DELETE /api/memories/{memory_id}' => 'memory_deleted',
        'DELETE /api/memories' => 'memories_cleared',
        'POST /api/feedback' => 'feedback_created',
        'POST /api/feedback/{entry_id}/vote' => 'feedback_vote_changed',
        'POST /api/feedback/{entry_id}/comments' => 'feedback_comment_created',
        'POST /api/reviews' => 'review_submitted',
        'POST /api/contact' => 'contact_sent',
    ];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly LearningAnalytics $learning,
        private readonly RetentionAnalytics $retention,
    ) {}

    public function handle(Request $request, int $status): void
    {
        if (! $this->analytics->enabled() || $status < 200 || $status >= 300) {
            return;
        }
        $route = $request->route();
        if ($route === null) {
            return;
        }
        $path = '/'.ltrim($route->uri(), '/');
        $key = strtoupper($request->getMethod()).' '.$path;
        if ($key === 'POST /api/flashcards/{card_id}/review' && $request->attributes->get('flashcard_review_replayed')) {
            return;
        }
        $events = [];
        if (isset(self::SUCCESS[$key])) {
            $events[] = self::SUCCESS[$key];
        }
        if ($key === 'POST /api/flashcards/from-word' && $request->attributes->get('vocabulary_saved')) {
            $events[] = 'vocabulary_saved';
        }
        if ($key === 'POST /api/flashcards/{card_id}/review' && $request->attributes->get('vocabulary_reviewed')) {
            $events[] = 'saved_vocabulary_reviewed';
        }
        if ($key === 'PATCH /api/auth/me' && $request->attributes->get('preferences_saved')) {
            $events[] = 'preferences_saved';
        }
        $retention = $key === 'GET /api/progress/summary';
        if ($events === [] && ! $retention) {
            return;
        }
        $userAgent = (string) $request->headers->get('user-agent', '');
        foreach ($events as $event) {
            $source = $event === 'vocabulary_saved'
                ? $request->attributes->get('vocabulary_saved_id')
                : null;
            $this->learning->record($event, (string) ($source ?: Str::uuid()), $userAgent);
        }
        if ($retention) {
            $this->retention->publish($userAgent);
        }
    }
}
