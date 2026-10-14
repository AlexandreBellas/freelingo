<?php

namespace Tests\Feature;

use Tests\TestCase;

class VocabularyTest extends TestCase
{
    public function test_list_vocabulary_sets_default_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/vocabulary', [], $headers);
        $response->assertOk();
        $sets = $response->json('sets');
        $this->assertNotEmpty($sets);
        $this->assertTrue(collect($sets)->contains(fn ($set) => str_contains($set['id'], '_a1') || str_contains($set['id'], '_a2')));
        foreach ($sets as $set) {
            $this->assertArrayHasKey('words', $set);
            $this->assertNotEmpty($set['words']);
            $this->assertArrayHasKey('word', $set['words'][0]);
        }
    }

    public function test_list_vocabulary_sets_spanish_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/vocabulary?language=es-ES', [], $headers);
        $response->assertOk();
        $sets = $response->json('sets');
        $this->assertNotEmpty($sets);
        $this->assertTrue(collect($sets)->contains(fn ($set) => str_contains($set['id'], '_a1')));
    }

    public function test_list_vocabulary_requires_auth(): void
    {
        $this->api('GET', '/api/vocabulary')->assertStatus(401);
    }

    public function test_list_vocabulary_by_level_a1(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/vocabulary/level/A1', [], $headers);
        $response->assertOk();
        foreach ($response->json('sets') as $set) {
            $this->assertSame('A1', $set['level']);
        }
    }

    public function test_list_vocabulary_by_level_case_insensitive(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/vocabulary/level/a1', [], $headers);
        $response->assertOk();
        $this->assertNotEmpty($response->json('sets'));
        $this->assertSame('A1', $response->json('sets.0.level'));
    }

    public function test_list_vocabulary_by_level_invalid(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/vocabulary/level/X9', [], $headers);
        $response->assertStatus(400);
        $this->assertStringContainsString('Invalid CEFR level', $response->json('detail'));
    }

    public function test_get_vocabulary_set_detail_and_missing(): void
    {
        [, $headers] = $this->makeUser();
        $list = $this->api('GET', '/api/vocabulary', [], $headers);
        $setId = $list->json('sets.0.id');
        $detail = $this->api('GET', '/api/vocabulary/'.$setId, [], $headers);
        $detail->assertOk();
        $this->assertSame($setId, $detail->json('set.id'));
        $this->api('GET', '/api/vocabulary/nonexistent_set_id_123', [], $headers)
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Vocabulary set not found');
    }
}
