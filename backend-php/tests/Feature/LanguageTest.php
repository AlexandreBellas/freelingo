<?php

namespace Tests\Feature;

use Tests\TestCase;

class LanguageTest extends TestCase
{
    public function test_lists_the_language_created_with_the_user(): void
    {
        [, $headers] = $this->makeUser(['target_language' => 'en-GB']);
        $response = $this->api('GET', '/api/languages', [], $headers);
        $response->assertOk();
        $this->assertSame('en-GB', $response->json('languages.0.target_language'));
        $this->assertTrue($response->json('languages.0.is_active'));
    }

    public function test_adds_and_switches_a_language(): void
    {
        [, $headers] = $this->makeUser(['target_language' => 'en-GB']);
        $created = $this->api('POST', '/api/languages', ['target_language' => 'es-ES'], $headers);
        $created->assertStatus(201);
        $this->assertSame('es-ES', $created->json('target_language'));

        $active = $this->api('GET', '/api/languages/active', [], $headers);
        $active->assertOk()->assertJsonPath('target_language', 'es-ES');

        $this->api('PUT', '/api/languages/active', ['target_language' => 'en-GB'], $headers)
            ->assertOk()
            ->assertJsonPath('target_language', 'en-GB');
    }

    public function test_languages_require_auth(): void
    {
        $this->api('GET', '/api/languages')->assertStatus(401);
    }
}
