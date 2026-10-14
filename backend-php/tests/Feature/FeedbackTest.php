<?php

namespace Tests\Feature;

use App\Models\FeedbackComment;
use App\Models\FeedbackEntry;
use App\Models\FeedbackReadState;
use App\Models\FeedbackVote;
use App\Models\User;
use App\Services\Security\PasswordHasher;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    public function test_feedback_crud_votes_comments_and_unread(): void
    {
        [$user, $headers] = $this->makeUser();
        [$admin, $adminHeaders] = $this->makeAdmin();

        $this->api('GET', '/api/feedback')->assertUnauthorized();
        $empty = $this->api('GET', '/api/feedback', [], $headers);
        $empty->assertOk();
        $empty->assertJsonPath('total', 0);
        $empty->assertJsonPath('items', []);

        $created = $this->api('POST', '/api/feedback', [
            'type' => 'feature',
            'title' => 'Dark mode',
            'description' => 'Please add dark mode support.',
        ], $headers);
        $created->assertCreated();
        $created->assertJsonPath('type', 'feature');
        $created->assertJsonPath('status', 'pending');
        $created->assertJsonPath('vote_count', 0);
        $created->assertJsonPath('voted_by_me', false);
        $created->assertJsonPath('comment_count', 0);
        $created->assertJsonPath('author.role', 'user');
        $created->assertJsonPath('unread_by_me', false);
        $entryId = $created->json('id');

        $bug = $this->api('POST', '/api/feedback', [
            'type' => 'bug',
            'title' => 'Crash on login',
            'description' => 'App crashes when I click login.',
        ], $adminHeaders);
        $bug->assertCreated();
        $bug->assertJsonPath('author.role', 'admin');

        $this->api('POST', '/api/feedback', ['type' => 'other', 'title' => 'X', 'description' => 'Y'], $headers)->assertStatus(422);
        $this->api('POST', '/api/feedback', ['type' => 'feature', 'title' => ' ', 'description' => 'Y'], $headers)->assertStatus(422);
        $this->api('GET', '/api/feedback/99999', [], $headers)->assertNotFound()->assertJsonPath('detail', 'feedback_entry_not_found');

        $vote = $this->api('POST', '/api/feedback/'.$entryId.'/vote', [], $headers);
        $vote->assertOk();
        $vote->assertJsonPath('voted', true);
        $vote->assertJsonPath('vote_count', 1);
        $removed = $this->api('POST', '/api/feedback/'.$entryId.'/vote', [], $headers);
        $removed->assertJsonPath('voted', false);
        $removed->assertJsonPath('vote_count', 0);
        $bugVote = $this->api('POST', '/api/feedback/'.$bug->json('id').'/vote', [], $headers);
        $bugVote->assertStatus(400);
        $bugVote->assertJsonPath('detail', 'only_features_are_voteable');

        $comment = $this->api('POST', '/api/feedback/'.$entryId.'/comments', ['body' => '  Sounds good  '], $adminHeaders);
        $comment->assertCreated();
        $comment->assertJsonPath('body', 'Sounds good');
        $comment->assertJsonPath('author.role', 'admin');
        $comments = $this->api('GET', '/api/feedback/'.$entryId.'/comments', [], $headers);
        $comments->assertOk();
        $comments->assertJsonPath('total', 1);

        $detail = $this->api('GET', '/api/feedback/'.$entryId, [], $headers);
        $detail->assertOk();
        $this->assertCount(1, $detail->json('comments'));
        $detail->assertJsonPath('unread_by_me', true);

        $marked = $this->api('POST', '/api/feedback/'.$entryId.'/read', [], $headers);
        $marked->assertOk();
        $marked->assertJsonPath('entry_id', $entryId);
        $this->api('GET', '/api/feedback/'.$entryId, [], $headers)->assertJsonPath('unread_by_me', false);

        $status = $this->api('PATCH', '/api/feedback/'.$entryId.'/status', ['status' => 'planned'], $adminHeaders);
        $status->assertOk();
        $status->assertJsonPath('status', 'planned');
        $this->api('PATCH', '/api/feedback/'.$entryId.'/status', ['status' => 'planned'], $headers)->assertForbidden();
        $this->api('PATCH', '/api/feedback/'.$entryId.'/status', ['status' => 'nope'], $adminHeaders)->assertStatus(422);

        $this->api('DELETE', '/api/feedback/'.$entryId.'/comments/'.$comment->json('id'), [], $headers)->assertForbidden();
        $this->api('DELETE', '/api/feedback/'.$entryId.'/comments/'.$comment->json('id'), [], $adminHeaders)->assertNoContent();
        $this->api('DELETE', '/api/feedback/'.$entryId.'/comments/999', [], $headers)->assertNotFound()->assertJsonPath('detail', 'comment_not_found');

        $this->api('DELETE', '/api/feedback/'.$bug->json('id'), [], $headers)->assertForbidden()->assertJsonPath('detail', 'forbidden');
        $this->api('DELETE', '/api/feedback/'.$bug->json('id'), [], $adminHeaders)->assertNoContent();
        $this->api('DELETE', '/api/feedback/'.$entryId, [], $headers)->assertNoContent();
        $this->assertSame(0, FeedbackVote::query()->count());
        $this->assertSame(0, FeedbackComment::query()->count());
    }

    public function test_feedback_filters_sort_and_unread_summary(): void
    {
        [$user, $headers] = $this->makeUser();
        $other = User::query()->create([
            'username' => 'unreadauthor',
            'email' => 'unreadauthor@test.com',
            'display_name' => 'Unread Author',
            'hashed_password' => PasswordHasher::hash('pass1234'),
            'role' => 'user',
            'native_language' => 'en',
            'target_language' => 'en-GB',
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        $now = Carbon::now('UTC');
        FeedbackEntry::query()->create(['type' => 'feature', 'title' => 'Alpha votes', 'description' => 'searchable alpha', 'status' => 'pending', 'author_id' => $user->id, 'vote_count' => 1, 'created_at' => $now->copy()->subMinutes(2)]);
        FeedbackEntry::query()->create(['type' => 'bug', 'title' => 'Beta bug', 'description' => 'other text', 'status' => 'pending', 'author_id' => $other->id, 'vote_count' => 5, 'created_at' => $now->copy()->subMinute()]);
        FeedbackEntry::query()->create(['type' => 'feature', 'title' => 'Done item', 'description' => 'finished', 'status' => 'done', 'author_id' => $other->id, 'vote_count' => 9, 'created_at' => $now]);

        $features = $this->api('GET', '/api/feedback?type=feature', [], $headers);
        $features->assertOk();
        $this->assertTrue(collect($features->json('items'))->every(fn ($row) => $row['type'] === 'feature'));
        $this->assertFalse(collect($features->json('items'))->contains(fn ($row) => $row['title'] === 'Done item'));

        $done = $this->api('GET', '/api/feedback?status=done', [], $headers);
        $done->assertOk();
        $this->assertSame('Done item', $done->json('items.0.title'));

        $search = $this->api('GET', '/api/feedback?q=Unread', [], $headers);
        $search->assertOk();
        $this->assertTrue(collect($search->json('items'))->contains(fn ($row) => $row['title'] === 'Beta bug'));

        $sorted = $this->api('GET', '/api/feedback?sort=votes&order=desc', [], $headers);
        $sorted->assertOk();
        $this->assertSame('Beta bug', $sorted->json('items.0.title'));

        $summary = $this->api('GET', '/api/feedback/unread-summary', [], $headers);
        $summary->assertOk();
        $this->assertSame(2, $summary->json('unread_count'));

        $own = $this->api('POST', '/api/feedback', ['type' => 'feature', 'title' => 'Own activity', 'description' => 'd'], $headers);
        $own->assertCreated();
        $this->api('POST', '/api/feedback/'.$own->json('id').'/comments', ['body' => 'My comment'], $headers)->assertCreated();
        $afterOwn = $this->api('GET', '/api/feedback/unread-summary', [], $headers);
        $afterOwn->assertJsonPath('unread_count', 2);

        $first = FeedbackEntry::query()->where('title', 'Beta bug')->firstOrFail();
        $marked = $this->api('POST', '/api/feedback/'.$first->id.'/read', [], $headers);
        $marked->assertOk();
        $this->api('GET', '/api/feedback/unread-summary', [], $headers)->assertJsonPath('unread_count', 1);

        $now = Carbon::now('UTC');
        $thread = FeedbackEntry::query()->create([
            'type' => 'bug',
            'title' => 'Comment activity',
            'description' => 'd',
            'status' => 'done',
            'author_id' => $user->id,
            'created_at' => $now,
        ]);
        FeedbackReadState::query()->create([
            'entry_id' => $thread->id,
            'user_id' => $user->id,
            'last_read_at' => $now->copy()->addSecond(),
        ]);
        FeedbackComment::query()->create([
            'entry_id' => $thread->id,
            'author_id' => $other->id,
            'body' => 'Other comment',
            'created_at' => $now->copy()->addSeconds(2),
        ]);
        $this->api('GET', '/api/feedback/unread-summary', [], $headers)->assertJsonPath('unread_count', 2);
        $this->api('GET', '/api/feedback/unread-summary')->assertUnauthorized();
    }
}
