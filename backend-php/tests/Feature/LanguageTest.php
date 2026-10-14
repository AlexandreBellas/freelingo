<?php

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\StudyPlan;
use App\Models\UserLanguage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    public function test_add_switch_list_and_delete_language(): void
    {
        [$user, $headers] = $this->makeUser();

        $created = $this->api('POST', '/api/languages', ['target_language' => 'es-ES'], $headers);
        $created->assertCreated();
        $created->assertJsonPath('target_language', 'es-ES');
        $created->assertJsonPath('is_active', true);

        $duplicate = $this->api('POST', '/api/languages', ['target_language' => 'es-ES'], $headers);
        $duplicate->assertStatus(409);

        $active = $this->api('GET', '/api/languages/active', [], $headers);
        $active->assertOk();
        $active->assertJsonPath('target_language', 'es-ES');

        $language = UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->firstOrFail();
        $plan = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $language->id,
            'cefr_level' => 'A2',
            'target_language' => 'es-ES',
            'goals' => ['travel'],
            'duration_weeks' => 4,
            'days_per_week' => 3,
            'current_unit' => 'greetings',
            'progress_day' => 3,
            'generated_plan' => [],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        $memory = Memory::query()->create([
            'user_id' => $user->id,
            'study_plan_id' => $plan->id,
            'content' => 'User enjoys cooking',
            'source' => 'chat',
            'created_at' => Carbon::now('UTC'),
        ]);

        $listed = $this->api('GET', '/api/languages', [], $headers);
        $listed->assertOk();
        $listed->assertJsonStructure(['languages', 'all_supported_languages']);
        $this->assertTrue(collect($listed->json('languages'))->contains(fn ($row) => $row['target_language'] === 'en-US'));
        $this->assertContains('ja-JP', $listed->json('all_supported_languages'));
        $this->assertContains('ko-KR', $listed->json('all_supported_languages'));
        $this->assertContains('zh-CN', $listed->json('all_supported_languages'));
        $spanish = collect($listed->json('languages'))->firstWhere('target_language', 'es-ES');
        $this->assertSame('A2', $spanish['plan']['cefr_level']);
        $this->assertSame(12, $spanish['plan']['total_days']);
        $this->assertEquals(25.0, $spanish['plan']['completion_pct']);

        $switched = $this->api('PUT', '/api/languages/active', ['target_language' => 'en-US'], $headers);
        $switched->assertOk();
        $switched->assertJsonPath('target_language', 'en-US');
        $user->refresh();
        $this->assertSame('en-US', $user->target_language);

        $only = $this->api('DELETE', '/api/languages/en-US', [], $headers);
        $only->assertStatus(400);
        $only->assertJsonPath('detail', 'Cannot delete the active language');

        $removed = $this->api('DELETE', '/api/languages/es-ES', [], $headers);
        $removed->assertNoContent();
        $this->assertNull(UserLanguage::query()->where('user_id', $user->id)->where('target_language', 'es-ES')->first());
        $memory->refresh();
        $this->assertSame($user->id, $memory->user_id);
        $this->assertNull($memory->study_plan_id);
    }

    public function test_language_validation_and_guards(): void
    {
        [, $headers] = $this->makeUser();

        $invalid = $this->api('POST', '/api/languages', ['target_language' => 'xx-XX'], $headers);
        $invalid->assertStatus(422);

        $this->api('POST', '/api/languages', ['target_language' => 'ja-JP'], $headers)->assertCreated()->assertJsonPath('target_language', 'ja-JP');
        $this->api('POST', '/api/languages', ['target_language' => 'ko-KR'], $headers)->assertCreated();
        $this->api('POST', '/api/languages', ['target_language' => 'zh-CN'], $headers)->assertCreated();

        $missing = $this->api('PUT', '/api/languages/active', ['target_language' => 'fr-FR'], $headers);
        $missing->assertNotFound();
        $missing->assertJsonPath('detail', 'Language not found for user');

        $this->api('GET', '/api/languages', [], [])->assertUnauthorized();
        $last = $this->api('DELETE', '/api/languages/missing', [], $headers);
        $last->assertNotFound();
        $last->assertJsonPath('detail', 'Language not found');
    }

    public function test_cannot_delete_the_only_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('DELETE', '/api/languages/en-US', [], $headers);
        $response->assertStatus(400);
        $response->assertJsonPath('detail', 'Cannot delete the only language');
    }
}
