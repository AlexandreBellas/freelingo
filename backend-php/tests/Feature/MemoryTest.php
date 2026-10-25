<?php

namespace Tests\Feature;

use App\Models\Memory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    public function test_memory_crud(): void
    {
        [$user, $headers] = $this->makeUser();

        $empty = $this->api('GET', '/api/memories', [], $headers);
        $empty->assertOk();
        $empty->assertExactJson(['memories' => []]);

        $created = $this->api('POST', '/api/memories', ['content' => '  Prefers concise explanations  '], $headers);
        $created->assertCreated();
        $created->assertJsonPath('source', 'manual');
        $created->assertJsonPath('content', 'Prefers concise explanations');
        $memory = Memory::query()->findOrFail($created->json('id'));
        $this->assertSame($user->id, $memory->user_id);
        $this->assertNull($memory->study_plan_id);

        $duplicate = $this->api('POST', '/api/memories', ['content' => 'Prefers concise explanations'], $headers);
        $duplicate->assertStatus(409);
        $duplicate->assertJsonPath('detail', 'memory_already_exists');

        foreach (['', '   ', str_repeat('x', 201)] as $content) {
            $this->api('POST', '/api/memories', ['content' => $content], $headers)->assertStatus(422);
        }

        Memory::query()->create([
            'user_id' => $user->id,
            'content' => 'Fact 2',
            'source' => 'voice',
            'created_at' => Carbon::now('UTC')->addSecond(),
        ]);
        $listed = $this->api('GET', '/api/memories', [], $headers);
        $listed->assertOk();
        $this->assertCount(2, $listed->json('memories'));
        $this->assertSame('voice', $listed->json('memories.1.source'));

        $deleted = $this->api('DELETE', '/api/memories/'.$memory->id, [], $headers);
        $deleted->assertNoContent();
        $this->assertNull(Memory::query()->find($memory->id));
        $this->api('DELETE', '/api/memories/99999', [], $headers)->assertNotFound()->assertJsonPath('detail', 'Memory not found');

        [$admin] = $this->makeAdmin();
        $foreign = Memory::query()->create([
            'user_id' => $admin->id,
            'content' => 'Admin memory',
            'source' => 'chat',
            'created_at' => Carbon::now('UTC'),
        ]);
        $this->api('DELETE', '/api/memories/'.$foreign->id, [], $headers)->assertNotFound();

        $cleared = $this->api('DELETE', '/api/memories', [], $headers);
        $cleared->assertOk();
        $cleared->assertJsonPath('deleted', 1);
        $this->assertSame(0, Memory::query()->where('user_id', $user->id)->count());
        $this->api('GET', '/api/memories')->assertUnauthorized();
    }
}
