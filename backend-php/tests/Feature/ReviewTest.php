<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    public function test_review_lifecycle_and_public_listing(): void
    {
        [$user, $headers] = $this->makeUser();
        [, $adminHeaders] = $this->makeAdmin();

        $empty = $this->api('GET', '/api/reviews/me', [], $headers);
        $empty->assertOk();
        $empty->assertExactJson(['has_review' => false, 'review' => null]);

        $this->api('POST', '/api/reviews', [], $headers)->assertStatus(422);
        $this->api('POST', '/api/reviews', ['rating' => 0], $headers)->assertStatus(422);
        $this->api('POST', '/api/reviews', ['rating' => 6], $headers)->assertStatus(422);

        $created = $this->api('POST', '/api/reviews', [
            'rating' => 5,
            'comment' => '  FreeLingo helps me practice every day.  ',
        ], $headers);
        $created->assertCreated();
        $created->assertJsonPath('user_id', $user->id);
        $created->assertJsonPath('user_display_name', 'Test User');
        $created->assertJsonPath('target_language', 'en-US');
        $created->assertJsonPath('rating', 5);
        $created->assertJsonPath('comment', 'FreeLingo helps me practice every day.');
        $created->assertJsonPath('is_approved', false);

        $duplicate = $this->api('POST', '/api/reviews', ['rating' => 4], $headers);
        $duplicate->assertStatus(409);
        $duplicate->assertJsonPath('detail', 'review_already_exists');

        $mine = $this->api('GET', '/api/reviews/me', [], $headers);
        $mine->assertOk();
        $mine->assertJsonPath('has_review', true);
        $mine->assertJsonPath('review.rating', 5);

        $updated = $this->api('PATCH', '/api/reviews/me', ['rating' => 4, 'comment' => 'Updated review'], $headers);
        $updated->assertOk();
        $updated->assertJsonPath('rating', 4);
        $updated->assertJsonPath('comment', 'Updated review');
        $updated->assertJsonPath('is_approved', false);

        $this->api('GET', '/api/reviews/public')->assertOk()->assertExactJson([]);

        Review::query()->where('user_id', $user->id)->update(['is_approved' => true, 'rating' => 3]);
        $this->api('GET', '/api/reviews/public')->assertExactJson([]);

        Review::query()->where('user_id', $user->id)->update([
            'is_approved' => true,
            'rating' => 4,
            'comment' => 'Great app',
            'user_display_name' => 'Landing User',
            'target_language' => 'fr-FR',
        ]);
        $public = $this->api('GET', '/api/reviews/public');
        $public->assertOk();
        $this->assertCount(1, $public->json());
        $this->assertSame('Landing User', $public->json('0.user_display_name'));
        $this->assertSame('fr-FR', $public->json('0.target_language'));
        $this->assertArrayNotHasKey('is_approved', $public->json('0'));
        $this->assertArrayNotHasKey('user_id', $public->json('0'));

        $adminList = $this->api('GET', '/api/admin/reviews', [], $adminHeaders);
        $adminList->assertOk();
        $adminList->assertJsonPath('total', 1);
        $adminList->assertJsonPath('items.0.is_approved', true);

        $reviewId = $public->json('0.id');
        $approved = $this->api('PATCH', '/api/admin/reviews/'.$reviewId, ['is_approved' => false], $adminHeaders);
        $approved->assertOk();
        $approved->assertJsonPath('is_approved', false);

        $this->api('GET', '/api/admin/reviews', [], $headers)->assertForbidden();
        $this->api('DELETE', '/api/admin/reviews/'.$reviewId, [], $adminHeaders)->assertNoContent();
        $this->assertNull(Review::query()->find($reviewId));

        $missing = $this->api('PATCH', '/api/reviews/me', ['rating' => 2], $headers);
        $missing->assertNotFound();
        $missing->assertJsonPath('detail', 'review_not_found');
        $this->api('DELETE', '/api/reviews/me', [], $headers)->assertNotFound()->assertJsonPath('detail', 'review_not_found');
    }

    public function test_blank_comment_is_stored_as_null(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('POST', '/api/reviews', ['rating' => 5, 'comment' => '   '], $headers);
        $response->assertCreated();
        $response->assertJsonPath('comment', null);
    }

    public function test_admin_review_filters(): void
    {
        [$user, $headers] = $this->makeUser();
        [, $adminHeaders] = $this->makeAdmin();
        Review::query()->create([
            'user_id' => $user->id,
            'user_display_name' => 'Test User',
            'target_language' => 'en-US',
            'rating' => 5,
            'comment' => 'Nice',
            'is_approved' => false,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
        $filtered = $this->api('GET', '/api/admin/reviews?is_approved=false&rating=5&target_language=en-US&order=asc', [], $adminHeaders);
        $filtered->assertOk();
        $filtered->assertJsonPath('total', 1);
        $this->api('GET', '/api/reviews/me')->assertUnauthorized();
        $this->api('DELETE', '/api/reviews/me', [], $headers)->assertNoContent();
    }
}
