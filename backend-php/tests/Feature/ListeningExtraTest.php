<?php

namespace Tests\Feature;

use App\Models\ListeningExercise;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListeningExtraTest extends TestCase
{
    public function test_listening_history_empty(): void
    {
        [, $headers] = $this->listener();

        $response = $this->api('GET', '/api/listening/history', [], $headers);

        $response->assertOk();
        $this->assertSame([], $response->json('items'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_listening_history_pagination_fields(): void
    {
        [, $headers] = $this->listener();

        $response = $this->api('GET', '/api/listening/history?skip=0&limit=5', [], $headers);

        $response->assertOk();
        $this->assertArrayHasKey('skip', $response->json());
        $this->assertArrayHasKey('limit', $response->json());
        $this->assertSame(0, $response->json('skip'));
        $this->assertSame(5, $response->json('limit'));
    }

    public function test_listening_history_limit_capped_at_50(): void
    {
        [, $headers] = $this->listener();

        $response = $this->api('GET', '/api/listening/history?skip=0&limit=100', [], $headers);

        $response->assertOk();
        $this->assertLessThanOrEqual(50, $response->json('limit'));
    }

    public function test_listening_history_requires_auth(): void
    {
        $this->api('GET', '/api/listening/history', [], ['Authorization' => 'Bearer invalid'])->assertStatus(401);
    }

    public function test_listening_audio_exercise_not_found(): void
    {
        [, $headers] = $this->listener();

        $response = $this->api('GET', '/api/listening/audio/99999', [], $headers);

        $response->assertStatus(404);
        $this->assertSame('exercise_not_found', $response->json('detail'));
    }

    public function test_listening_audio_file_not_found(): void
    {
        [, $headers] = $this->listener();
        $exercise = ListeningExercise::query()->create([
            'level' => 'B1',
            'target_language' => 'en-US',
            'exercise_type' => 'monologue',
            'topic' => 'test',
            'text' => 'Test audio text.',
            'audio_path' => '/nonexistent/path/999.mp3',
            'duration_seconds' => 0,
            'questions' => [],
            'play_count' => 0,
            'created_at' => Carbon::now('UTC'),
        ]);
        $path = rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/listening/'.$exercise->id.'.mp3';
        if (is_file($path)) {
            unlink($path);
        }

        $response = $this->api('GET', '/api/listening/audio/'.$exercise->id, [], $headers);

        $response->assertStatus(404);
        $this->assertSame('audio_not_found', $response->json('detail'));
    }

    public function test_listening_audio_requires_auth(): void
    {
        $this->api('GET', '/api/listening/audio/1', [], ['Authorization' => 'Bearer invalid'])->assertStatus(401);
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function listener(): array
    {
        [$user, $headers] = $this->makeUser([
            'username' => 'luser',
            'email' => 'luser@example.com',
            'display_name' => 'Listening User',
        ]);
        $active = UserLanguage::query()->where('user_id', $user->id)->where('is_active', true)->firstOrFail();
        StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $active->id,
            'cefr_level' => 'A1',
            'target_language' => 'en-US',
            'goals' => ['grammar'],
            'current_unit' => '',
            'progress_day' => 0,
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'generated_plan' => [],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);

        return [$user, $headers];
    }
}
