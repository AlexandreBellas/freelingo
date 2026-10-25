<?php

namespace Tests\Feature;

use App\Models\GameAdmission;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreemiumTest extends TestCase
{
    public function test_freemium_status_reports_limits_trial_and_games(): void
    {
        [$user, $headers] = $this->makeUser();
        config([
            'freelingo.stripe_enabled' => true,
            'freelingo.freemium_trial_enabled' => true,
            'freelingo.freemium_chat_daily_messages' => 5,
            'freelingo.freemium_games_daily' => 3,
        ]);
        $user->freemium_trial_ends_at = Carbon::now('UTC')->addDay();
        $user->save();

        $redis = $this->app->make(ArrayRedis::class);
        $redis->set('freemium:chat:'.$user->id.':'.Carbon::now('UTC')->format('Y-m-d'), '2');
        GameAdmission::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'date' => Carbon::now('UTC')->toDateString(),
            'status' => 'consumed',
            'deadline' => Carbon::now('UTC')->addHour(),
        ]);

        $response = $this->api('GET', '/api/freemium/status', [], $headers);
        $response->assertOk();
        $response->assertJsonPath('trial_active', true);
        $response->assertJsonPath('chat_limit', 5);
        $response->assertJsonPath('chat_remaining', 3);
        $response->assertJsonPath('games_limit', 3);
        $response->assertJsonPath('games_remaining', 2);
        $this->assertNotNull($response->json('trial_ends_at'));
        $this->assertArrayHasKey('voice_remaining_seconds', $response->json());
        $this->assertArrayHasKey('reading_limit', $response->json());

        config(['freelingo.stripe_enabled' => false]);
        $this->api('GET', '/api/freemium/status', [], $headers)->assertJsonPath('trial_active', false);
        $this->api('GET', '/api/freemium/status')->assertUnauthorized();
    }
}
