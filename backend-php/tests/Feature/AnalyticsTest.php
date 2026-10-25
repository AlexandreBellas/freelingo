<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    private const USER_AGENT = 'FreeLingoTest/1.0';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freelingo.umami_script_url' => 'https://analytics.example/script.js',
            'freelingo.umami_website_id' => '11111111-1111-4111-8111-111111111111',
            'freelingo.app_base_url' => 'https://freelingo.test',
        ]);
        Http::fake([
            'https://analytics.example/*' => Http::response(['sessionId' => 'sess_1'], 200),
        ]);
    }

    public function test_browser_bridge_is_authenticated_closed_and_deduplicated(): void
    {
        [, $headers] = $this->makeUser();
        $operation = (string) Str::uuid();
        $body = ['event' => 'tour_started', 'operation_id' => $operation];
        $this->api('POST', '/api/analytics/ui', $body)->assertUnauthorized();

        $headers['User-Agent'] = self::USER_AGENT;
        $this->api('POST', '/api/analytics/ui', $body, $headers)->assertNoContent();
        $this->api('POST', '/api/analytics/ui', $body, $headers)->assertNoContent();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($operation) {
            $payload = $request->body();

            return str_contains($payload, 'tour_started')
                && str_contains($payload, '/dashboard')
                && ! str_contains($payload, $operation);
        });

        foreach ([
            ['event' => 'registration_completed', 'operation_id' => $operation],
            ['event' => 'tour_started', 'operation_id' => $operation, 'data' => ['email' => 'private@example.com']],
            ['event' => 'tour_started', 'operation_id' => 'not-a-uuid'],
            ['event' => 'tour_started', 'operation_id' => $operation, 'url' => '/private/1'],
        ] as $invalid) {
            $this->api('POST', '/api/analytics/ui', $invalid, $headers)->assertStatus(422);
        }
        Http::assertSentCount(1);
    }

    public function test_public_bridge_accepts_only_faq(): void
    {
        $headers = ['User-Agent' => self::USER_AGENT];
        $this->api('POST', '/api/analytics/public', [
            'event' => 'faq_viewed',
            'operation_id' => (string) Str::uuid(),
        ], $headers)->assertNoContent();
        Http::assertSent(fn ($request) => str_contains($request->body(), 'faq_viewed') && str_contains($request->body(), '/faq'));
        $this->api('POST', '/api/analytics/public', [
            'event' => 'voice_changed',
            'operation_id' => (string) Str::uuid(),
        ], $headers)->assertStatus(422);
    }

    public function test_success_events_export_names_only(): void
    {
        $headers = ['User-Agent' => self::USER_AGENT];
        $this->api('POST', '/api/auth/register', [
            'username' => 'newlearner',
            'email' => 'private@example.com',
            'password' => 'Test1234!@',
            'native_language' => 'es',
        ], $headers)->assertOk();
        $this->api('POST', '/api/auth/register', [
            'username' => 'newlearner',
            'email' => 'private@example.com',
            'password' => 'Test1234!@',
            'native_language' => 'es',
        ], $headers)->assertStatus(409);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, 'registration_completed')
                && str_contains($body, '/register')
                && ! str_contains($body, 'private@example.com');
        });

        Http::fake([
            'https://analytics.example/*' => Http::response(['sessionId' => 'sess_2'], 200),
        ]);
        [, $auth] = $this->makeUser();
        $auth['User-Agent'] = self::USER_AGENT;
        $created = $this->api('POST', '/api/memories', ['content' => 'Private learning context'], $auth);
        $created->assertCreated();
        $this->api('GET', '/api/memories', [], $auth)->assertOk();
        $this->api('DELETE', '/api/memories/'.$created->json('id'), [], $auth)->assertNoContent();
        $this->api('DELETE', '/api/memories/'.$created->json('id'), [], $auth)->assertNotFound();

        $names = [];
        foreach (Http::recorded() as [$request]) {
            $payload = json_decode($request->body(), true);
            $names[] = $payload['payload']['name'] ?? null;
            $this->assertSame('/settings/memories', $payload['payload']['url']);
            $this->assertStringNotContainsString('Private learning context', $request->body());
        }
        $this->assertSame(['memory_created', 'memories_viewed', 'memory_deleted'], $names);

        $config = $this->api('GET', '/api/config');
        $config->assertOk();
        $config->assertJsonPath('analytics_enabled', true);
        $this->assertArrayNotHasKey('website_id', $config->json());
        $this->assertArrayNotHasKey('script_url', $config->json());
    }
}
