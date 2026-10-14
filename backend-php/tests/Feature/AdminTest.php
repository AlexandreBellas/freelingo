<?php

namespace Tests\Feature;

use App\Models\FeedbackEntry;
use App\Models\Lesson;
use App\Models\Review;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Security\PasswordHasher;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_admin_user_management(): void
    {
        [$admin, $adminHeaders] = $this->makeAdmin();
        [$user, $userHeaders] = $this->makeUser();

        $list = $this->api('GET', '/api/admin/users', [], $adminHeaders);
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, $list->json('total'));
        $this->assertTrue(collect($list->json('items'))->contains(fn ($row) => $row['username'] === 'admin'));

        $this->api('GET', '/api/admin/users', [], $userHeaders)->assertForbidden();
        $this->api('GET', '/api/admin/users')->assertUnauthorized();

        $created = $this->api('POST', '/api/admin/users', [
            'username' => 'newadminuser',
            'email' => 'newadminuser@example.com',
            'password' => 'Test1234!@',
            'display_name' => 'New Admin User',
            'native_language' => 'es',
            'target_language' => 'en',
            'role' => 'user',
        ], $adminHeaders);
        $created->assertOk();
        $created->assertJsonPath('username', 'newadminuser');
        $created->assertJsonPath('role', 'user');
        $this->assertNotNull(UserLanguage::query()->where('user_id', $created->json('id'))->where('target_language', 'en')->first());

        $this->api('POST', '/api/admin/users', [
            'username' => 'newadminuser',
            'email' => 'other@example.com',
            'password' => 'Test1234!@',
            'display_name' => 'Dup',
            'native_language' => 'es',
            'target_language' => 'en',
        ], $adminHeaders)->assertStatus(409)->assertJsonPath('detail', 'Username already taken');

        $this->api('POST', '/api/admin/users', [
            'username' => 'langtest',
            'password' => 'Test1234!@',
            'display_name' => 'Lang Test',
            'native_language' => 'xx',
            'role' => 'user',
        ], $adminHeaders)->assertStatus(422);
        $this->api('POST', '/api/admin/users', [
            'username' => 'emailtest',
            'password' => 'Test1234!@',
            'display_name' => 'Email Test',
            'native_language' => 'es',
            'email' => 'not-an-email',
            'role' => 'user',
        ], $adminHeaders)->assertStatus(422);

        $shown = $this->api('GET', '/api/admin/users/'.$user->id, [], $adminHeaders);
        $shown->assertOk();
        $shown->assertJsonPath('username', 'testuser');
        $this->api('GET', '/api/admin/users/99999', [], $adminHeaders)->assertNotFound();

        $updated = $this->api('PATCH', '/api/admin/users/'.$user->id, ['display_name' => 'Updated by Admin'], $adminHeaders);
        $updated->assertOk();
        $updated->assertJsonPath('display_name', 'Updated by Admin');
        $this->api('PATCH', '/api/admin/users/99999', ['display_name' => 'Ghost'], $adminHeaders)->assertNotFound();

        $this->api('POST', '/api/admin/invite', [], $userHeaders)->assertForbidden();
        $this->api('DELETE', '/api/admin/users/'.$admin->id, [], $adminHeaders)->assertStatus(400)->assertJsonPath('detail', 'Cannot delete yourself');
        $this->api('PATCH', '/api/admin/users/'.$admin->id, ['is_active' => false], $adminHeaders)->assertStatus(400)->assertJsonPath('detail', 'Cannot deactivate yourself');
        $deleted = $this->api('DELETE', '/api/admin/users/'.$user->id, [], $adminHeaders);
        $deleted->assertOk();
        $deleted->assertJsonPath('detail', 'User deleted');
        $this->api('DELETE', '/api/admin/users/99999', [], $adminHeaders)->assertNotFound();

        $invite = $this->api('POST', '/api/admin/invite', [], $adminHeaders);
        $invite->assertOk();
        $this->assertStringContainsString('/register?invite=', $invite->json('invite_url'));
    }

    public function test_admin_filters_and_overview(): void
    {
        [$admin, $adminHeaders] = $this->makeAdmin();
        [$user] = $this->makeUser();

        $base = $this->api('GET', '/api/admin/stats', [], $adminHeaders);
        $base->assertOk();
        $base->assertJsonPath('users_total', 2);
        $base->assertJsonPath('users_active', 2);
        $base->assertJsonPath('feedback_total', 0);
        $base->assertJsonPath('reviews_pending', 0);

        $this->makeExtra('trial-stats-user', ['subscription_status' => 'trialing']);
        $this->makeExtra('past-due-stats-user', ['is_active' => false, 'subscription_status' => 'past_due']);
        $user->subscription_status = 'active';
        $user->save();
        $now = Carbon::now('UTC');
        FeedbackEntry::query()->create(['type' => 'bug', 'title' => 'Pending bug', 'description' => 'Needs triage', 'status' => 'pending', 'author_id' => $user->id, 'created_at' => $now]);
        FeedbackEntry::query()->create(['type' => 'feature', 'title' => 'Pending feature', 'description' => 'Needs planning', 'status' => 'pending', 'author_id' => $admin->id, 'created_at' => $now]);
        FeedbackEntry::query()->create(['type' => 'bug', 'title' => 'Done bug', 'description' => 'Already fixed', 'status' => 'done', 'author_id' => $user->id, 'created_at' => $now]);
        Review::query()->create([
            'user_id' => $user->id,
            'user_display_name' => $user->display_name,
            'target_language' => 'en-GB',
            'rating' => 5,
            'comment' => 'Great learning flow',
            'is_approved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stats = $this->api('GET', '/api/admin/stats', [], $adminHeaders);
        $stats->assertOk();
        $stats->assertJsonPath('users_total', 4);
        $stats->assertJsonPath('users_active', 3);
        $stats->assertJsonPath('users_inactive', 1);
        $stats->assertJsonPath('subscriptions_active', 1);
        $stats->assertJsonPath('subscriptions_trialing', 1);
        $stats->assertJsonPath('subscriptions_past_due', 1);
        $stats->assertJsonPath('feedback_total', 3);
        $stats->assertJsonPath('feedback_pending', 2);
        $stats->assertJsonPath('feedback_bug_pending', 1);
        $stats->assertJsonPath('reviews_pending', 1);
        $this->api('GET', '/api/admin/stats', [], $this->makeUser(['username' => 'plain', 'email' => 'plain@example.com'])[1])->assertForbidden();
        $this->api('GET', '/api/admin/stats')->assertUnauthorized();

        $this->makeExtra('inactive-filter-user', ['is_active' => false]);
        $this->makeExtra('past-due-filter-user', ['subscription_status' => 'past_due']);
        $this->makeExtra('combined-filter-target', ['is_active' => false, 'subscription_status' => 'canceled']);
        $this->makeExtra('combined-filter-decoy', ['is_active' => true, 'subscription_status' => 'canceled']);

        $admins = $this->api('GET', '/api/admin/users?role=admin', [], $adminHeaders);
        $admins->assertOk();
        $this->assertTrue(collect($admins->json('items'))->every(fn ($row) => $row['role'] === 'admin'));

        $inactive = $this->api('GET', '/api/admin/users?is_active=false', [], $adminHeaders);
        $inactive->assertOk();
        $this->assertTrue(collect($inactive->json('items'))->contains(fn ($row) => $row['username'] === 'inactive-filter-user'));

        $pastDue = $this->api('GET', '/api/admin/users?subscription=past_due', [], $adminHeaders);
        $pastDue->assertOk();
        $this->assertTrue(collect($pastDue->json('items'))->every(fn ($row) => $row['subscription_status'] === 'past_due'));

        $combined = $this->api('GET', '/api/admin/users?q=combined-filter&role=user&is_active=false&subscription=canceled', [], $adminHeaders);
        $combined->assertOk();
        $names = collect($combined->json('items'))->pluck('username');
        $this->assertTrue($names->contains('combined-filter-target'));
        $this->assertFalse($names->contains('combined-filter-decoy'));

        $page = $this->api('GET', '/api/admin/users?skip=0&limit=3', [], $adminHeaders);
        $page->assertOk();
        $this->assertCount(3, $page->json('items'));
        $this->assertSame(3, $page->json('limit'));
    }

    public function test_user_stats_and_quota(): void
    {
        [, $adminHeaders] = $this->makeAdmin();
        [$user] = $this->makeUser();

        $zero = $this->api('GET', '/api/admin/users/'.$user->id.'/stats', [], $adminHeaders);
        $zero->assertOk();
        $zero->assertJsonPath('user_id', $user->id);
        $zero->assertJsonPath('xp_total', 0);
        $zero->assertJsonPath('lessons_completed', 0);
        $zero->assertJsonPath('chat_messages_sent', 0);
        $zero->assertJsonPath('tokens_total', 0);
        $zero->assertJsonPath('current_cefr', null);

        $language = UserLanguage::query()->where('user_id', $user->id)->firstOrFail();
        $plan = StudyPlan::query()->create([
            'user_id' => $user->id,
            'user_language_id' => $language->id,
            'cefr_level' => 'B1',
            'target_language' => 'en-US',
            'goals' => ['speaking'],
            'duration_weeks' => 4,
            'days_per_week' => 4,
            'generated_plan' => [],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        Lesson::query()->create([
            'study_plan_id' => $plan->id,
            'title' => 'Completed Lesson',
            'lesson_type' => 'grammar',
            'cefr_level' => 'B1',
            'week_number' => 1,
            'day_number' => 1,
            'content' => [],
            'is_completed' => true,
        ]);
        $stats = $this->api('GET', '/api/admin/users/'.$user->id.'/stats', [], $adminHeaders);
        $stats->assertOk();
        $stats->assertJsonPath('lessons_completed', 1);
        $stats->assertJsonPath('current_cefr', 'B1');
        $this->assertCount(1, $stats->json('per_language'));

        $this->api('GET', '/api/admin/users/99999/stats', [], $adminHeaders)->assertNotFound();
        $this->api('GET', '/api/admin/users/'.$user->id.'/stats', [], $this->makeUser(['username' => 'nostats', 'email' => 'nostats@example.com'])[1])->assertForbidden();

        $quota = $this->api('GET', '/api/admin/users/'.$user->id.'/quota', [], $adminHeaders);
        $quota->assertOk();
        $quota->assertJsonPath('sessions_this_week', 0);
        $quota->assertJsonPath('minutes_today', 0);
        $this->assertArrayHasKey('sessions_limit', $quota->json());
        $this->assertArrayHasKey('minutes_limit', $quota->json());
        $this->api('GET', '/api/admin/users/99999/quota', [], $adminHeaders)->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExtra(string $username, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'username' => $username,
            'email' => $username.'@test.com',
            'display_name' => $username,
            'hashed_password' => PasswordHasher::hash('pass1234'),
            'role' => 'user',
            'native_language' => 'es',
            'target_language' => 'en-GB',
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ], $overrides));
    }
}
