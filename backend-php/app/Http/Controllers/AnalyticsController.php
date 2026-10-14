<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Analytics\LearningAnalytics;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsController extends Controller
{
    use ResolvesApiUser;

    public function __construct(private readonly LearningAnalytics $learning) {}

    public function ui(Request $request): Response
    {
        $this->apiUser($request);
        $body = $this->event($request, false);
        $this->learning->record($body['event'], $body['operation_id'], (string) $request->headers->get('user-agent', ''));

        return response()->noContent();
    }

    public function publicEvent(Request $request): Response
    {
        $body = $this->event($request, true);
        $this->learning->record($body['event'], $body['operation_id'], (string) $request->headers->get('user-agent', ''));

        return response()->noContent();
    }

    /**
     * @return array{event: string, operation_id: string}
     */
    private function event(Request $request, bool $public): array
    {
        $data = $request->json()->all();
        if (! is_array($data) || array_keys($data) !== ['event', 'operation_id'] && array_keys($data) !== ['operation_id', 'event']) {
            $allowed = ['event', 'operation_id'];
            $keys = is_array($data) ? array_keys($data) : [];
            sort($keys);
            $expected = $allowed;
            sort($expected);
            if ($keys !== $expected) {
                throw new ApiException('Validation error', 422);
            }
        }
        $event = $data['event'] ?? null;
        $operation = $data['operation_id'] ?? null;
        $allowedEvents = $public ? ['faq_viewed'] : LearningAnalytics::BROWSER_EVENTS;
        if (! is_string($event) || ! in_array($event, $allowedEvents, true)) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($operation) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $operation)) {
            throw new ApiException('Validation error', 422);
        }

        return ['event' => $event, 'operation_id' => $operation];
    }
}
