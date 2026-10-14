<?php

namespace Tests\Feature;

use App\Models\ResourceNativeHelp;
use App\Services\Llm\LlmAdapter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GrammarPhrasebookTest extends TestCase
{
    public function test_list_grammar_topics_default_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar', [], $headers);
        $response->assertOk();
        $topics = $response->json('topics');
        $this->assertIsArray($topics);
        $this->assertNotEmpty($topics);
        foreach ($topics as $topic) {
            $this->assertArrayHasKey('slug', $topic);
            $this->assertArrayHasKey('title', $topic);
            $this->assertArrayHasKey('level', $topic);
            $this->assertArrayHasKey('category', $topic);
            $this->assertArrayHasKey('summary', $topic);
            $this->assertArrayHasKey('explanation', $topic);
            $this->assertIsArray($topic['rules']);
            $this->assertIsArray($topic['examples']);
            foreach ($topic['examples'] as $example) {
                $this->assertArrayHasKey('text', $example);
                $this->assertArrayNotHasKey('english', $example);
            }
            $this->assertArrayHasKey('common_mistakes', $topic);
            $this->assertArrayHasKey('related', $topic);
        }
    }

    public function test_list_grammar_topics_spanish_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar?language=es-ES', [], $headers);
        $response->assertOk();
        $titles = array_column($response->json('topics'), 'title');
        $this->assertNotEmpty($titles);
        $matched = false;
        foreach ($titles as $title) {
            if (str_contains($title, 'Pretérito') || str_contains($title, 'pretérito') || str_contains($title, 'sido') || str_contains($title, 'haber')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched);
    }

    public function test_list_grammar_requires_auth(): void
    {
        $this->api('GET', '/api/grammar')->assertStatus(401);
    }

    public function test_get_grammar_topic_detail_exists(): void
    {
        [, $headers] = $this->makeUser();
        $topics = $this->api('GET', '/api/grammar', [], $headers)->json('topics');
        $this->assertNotEmpty($topics);
        $slug = $topics[0]['slug'];

        $response = $this->api('GET', '/api/grammar/'.$slug, [], $headers);

        $response->assertOk();
        $this->assertSame($slug, $response->json('topic.slug'));
        $this->assertSame($topics[0]['title'], $response->json('topic.title'));
        $this->assertSame($topics[0]['level'], $response->json('topic.level'));
        $this->assertCount(count($topics[0]['rules']), $response->json('topic.rules'));
    }

    public function test_get_grammar_topic_detail_not_found(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar/nonexistent-topic-slug-xyz', [], $headers);
        $response->assertStatus(404);
        $this->assertSame('Grammar topic not found', $response->json('detail'));
    }

    public function test_get_grammar_topic_detail_spanish(): void
    {
        [, $headers] = $this->makeUser();
        $topics = $this->api('GET', '/api/grammar?language=es-ES', [], $headers)->json('topics');
        $slug = $topics[0]['slug'];

        $response = $this->api('GET', '/api/grammar/'.$slug.'?language=es-ES', [], $headers);

        $response->assertOk();
        $this->assertSame($slug, $response->json('topic.slug'));
    }

    public function test_get_grammar_topic_detail_requires_auth(): void
    {
        $this->api('GET', '/api/grammar/some-slug')->assertStatus(401);
    }

    public function test_different_languages_return_different_topics(): void
    {
        [, $headers] = $this->makeUser();
        $english = $this->api('GET', '/api/grammar?language=en-US', [], $headers)->json('topics');
        $spanish = $this->api('GET', '/api/grammar?language=es-ES', [], $headers)->json('topics');
        $this->assertNotSame(array_column($english, 'slug'), array_column($spanish, 'slug'));
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        [, $headers] = $this->makeUser();
        $english = $this->api('GET', '/api/grammar?language=en-GB', [], $headers)->json('topics');
        $unknown = $this->api('GET', '/api/grammar?language=xx-XX', [], $headers)->json('topics');
        $this->assertSame(array_column($english, 'slug'), array_column($unknown, 'slug'));
    }

    public function test_japanese_language_returns_japanese_topics(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar?language=ja-JP', [], $headers);
        $response->assertOk();
        $topics = $response->json('topics');
        $this->assertNotEmpty($topics);
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['slug'] === 'hiragana'));
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['title'] === 'ひらがな'));
    }

    public function test_korean_language_returns_korean_topics(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar?language=ko-KR', [], $headers);
        $response->assertOk();
        $topics = $response->json('topics');
        $this->assertNotEmpty($topics);
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['slug'] === 'hangul-basics'));
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['title'] === '한글 기본'));
    }

    public function test_chinese_language_returns_chinese_topics(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/grammar?language=zh-CN', [], $headers);
        $response->assertOk();
        $topics = $response->json('topics');
        $this->assertNotEmpty($topics);
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['slug'] === 'pinyin-tones'));
        $this->assertTrue(collect($topics)->contains(fn (array $topic) => $topic['title'] === '拼音和声调'));
    }

    public function test_generate_grammar_native_help(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptGrammarHelp();
        $slug = $this->api('GET', '/api/grammar?language=en-GB', [], $headers)->json('topics.0.slug');

        $response = $this->api('POST', '/api/grammar/'.$slug.'/native-help?language=en-GB', [], $headers);

        $response->assertOk();
        $this->assertSame('Resumen breve', $response->json('native_help.summary'));
        $this->assertCount(3, $response->json('native_help.key_points'));
        $this->assertSame('I am learning.', $response->json('native_help.examples.0.sentence'));
        $this->assertSame(1, $calls());
    }

    public function test_generate_grammar_native_help_uses_cache(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptGrammarHelp();
        $slug = $this->api('GET', '/api/grammar?language=en-GB', [], $headers)->json('topics.0.slug');

        $first = $this->api('POST', '/api/grammar/'.$slug.'/native-help?language=en-GB', [], $headers);
        $second = $this->api('POST', '/api/grammar/'.$slug.'/native-help?language=en-GB', [], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $calls());
    }

    public function test_generate_grammar_native_help_refreshes_stale_cache(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptGrammarHelp();
        $slug = $this->api('GET', '/api/grammar?language=en-GB', [], $headers)->json('topics.0.slug');
        $now = Carbon::now('UTC');
        ResourceNativeHelp::query()->create([
            'resource_type' => 'grammar',
            'resource_key' => $slug,
            'target_language' => 'en-GB',
            'native_language' => 'es',
            'source_hash' => 'stale',
            'content' => [
                'summary' => 'Viejo',
                'explanation' => 'Viejo',
                'key_points' => [],
                'examples' => [],
                'common_traps' => [],
                'mini_glossary' => [],
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->api('POST', '/api/grammar/'.$slug.'/native-help?language=en-GB', [], $headers);

        $response->assertOk();
        $this->assertSame('Resumen breve', $response->json('native_help.summary'));
        $this->assertSame(1, $calls());
    }

    public function test_generate_grammar_native_help_not_found(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('POST', '/api/grammar/nope/native-help?language=en-GB', [], $headers);
        $response->assertStatus(404);
        $this->assertSame('Grammar topic not found', $response->json('detail'));
    }

    public function test_list_phrasebook_categories_default_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $this->assertArrayHasKey('id', $category);
            $this->assertArrayHasKey('level', $category);
            $this->assertArrayHasKey('situation', $category);
            $this->assertArrayHasKey('icon', $category);
            $this->assertIsArray($category['phrases']);
            $this->assertNotEmpty($category['phrases']);
            foreach ($category['phrases'] as $phrase) {
                $this->assertArrayHasKey('text', $phrase);
                $this->assertArrayHasKey('context', $phrase);
                $this->assertArrayHasKey('register', $phrase);
                $this->assertArrayNotHasKey('english', $phrase);
            }
        }
    }

    public function test_list_phrasebook_categories_spanish_language(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook?language=es-ES', [], $headers);
        $response->assertOk();
        $situations = array_column($response->json('categories'), 'situation');
        $this->assertNotEmpty($situations);
        $this->assertTrue(collect($situations)->contains(fn (string $situation) => str_contains($situation, 'Saludos')));
    }

    public function test_list_phrasebook_requires_auth(): void
    {
        $this->api('GET', '/api/phrasebook')->assertStatus(401);
    }

    public function test_list_phrasebook_by_level_a1(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook/level/A1', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $this->assertSame('A1', $category['level']);
        }
    }

    public function test_list_phrasebook_by_level_case_insensitive(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook/level/a1', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $this->assertSame('A1', $category['level']);
        }
    }

    public function test_list_phrasebook_by_level_invalid(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook/level/X9', [], $headers);
        $response->assertStatus(400);
        $this->assertStringContainsString('Invalid CEFR level', (string) $response->json('detail'));
    }

    public function test_list_phrasebook_by_level_spanish(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook/level/A2?language=es-ES', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $this->assertSame('A2', $category['level']);
        }
    }

    public function test_list_phrasebook_by_level_requires_auth(): void
    {
        $this->api('GET', '/api/phrasebook/level/A1')->assertStatus(401);
    }

    public function test_get_phrasebook_category_detail_exists(): void
    {
        [, $headers] = $this->makeUser();
        $categories = $this->api('GET', '/api/phrasebook', [], $headers)->json('categories');
        $categoryId = $categories[0]['id'];

        $response = $this->api('GET', '/api/phrasebook/'.$categoryId, [], $headers);

        $response->assertOk();
        $this->assertSame($categoryId, $response->json('category.id'));
        $this->assertSame($categories[0]['level'], $response->json('category.level'));
        $this->assertSame($categories[0]['situation'], $response->json('category.situation'));
        $this->assertCount(count($categories[0]['phrases']), $response->json('category.phrases'));
    }

    public function test_get_phrasebook_category_detail_not_found(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook/nonexistent_category_id_123', [], $headers);
        $response->assertStatus(404);
        $this->assertSame('Phrasebook category not found', $response->json('detail'));
    }

    public function test_get_phrasebook_category_detail_spanish(): void
    {
        [, $headers] = $this->makeUser();
        $categories = $this->api('GET', '/api/phrasebook?language=es-ES', [], $headers)->json('categories');
        $categoryId = $categories[0]['id'];

        $response = $this->api('GET', '/api/phrasebook/'.$categoryId.'?language=es-ES', [], $headers);

        $response->assertOk();
        $this->assertSame($categoryId, $response->json('category.id'));
    }

    public function test_get_phrasebook_category_detail_requires_auth(): void
    {
        $this->api('GET', '/api/phrasebook/some_id')->assertStatus(401);
    }

    public function test_different_languages_return_different_categories(): void
    {
        [, $headers] = $this->makeUser();
        $english = $this->api('GET', '/api/phrasebook?language=en-US', [], $headers)->json('categories');
        $spanish = $this->api('GET', '/api/phrasebook?language=es-ES', [], $headers)->json('categories');
        $this->assertNotSame(array_column($english, 'situation'), array_column($spanish, 'situation'));
    }

    public function test_unknown_phrasebook_language_falls_back_to_english(): void
    {
        [, $headers] = $this->makeUser();
        $english = $this->api('GET', '/api/phrasebook?language=en-GB', [], $headers)->json('categories');
        $unknown = $this->api('GET', '/api/phrasebook?language=xx-XX', [], $headers)->json('categories');
        $this->assertSame(array_column($english, 'id'), array_column($unknown, 'id'));
    }

    public function test_japanese_language_returns_japanese_categories(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook?language=ja-JP', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['id'] === 'greetings_ja_a1'));
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['situation'] === 'あいさつと自己紹介'));
    }

    public function test_korean_language_returns_korean_categories(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook?language=ko-KR', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['id'] === 'greetings_a1'));
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['situation'] === '인사와 소개'));
    }

    public function test_chinese_language_returns_chinese_categories(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/phrasebook?language=zh-CN', [], $headers);
        $response->assertOk();
        $categories = $response->json('categories');
        $this->assertNotEmpty($categories);
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['id'] === 'greetings_a1'));
        $this->assertTrue(collect($categories)->contains(fn (array $category) => $category['situation'] === '问候和介绍'));
    }

    public function test_generate_phrasebook_native_help(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptPhrasebookHelp();
        $categoryId = $this->api('GET', '/api/phrasebook?language=en-GB', [], $headers)->json('categories.0.id');

        $response = $this->api('POST', '/api/phrasebook/'.$categoryId.'/native-help?language=en-GB', [], $headers);

        $response->assertOk();
        $this->assertSame('Resumen de situación', $response->json('native_help.summary'));
        $this->assertCount(3, $response->json('native_help.usage_tips'));
        $this->assertSame('Hello', $response->json('native_help.phrase_notes.0.phrase'));
        $this->assertSame(1, $calls());
    }

    public function test_generate_phrasebook_native_help_uses_cache(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptPhrasebookHelp();
        $categoryId = $this->api('GET', '/api/phrasebook?language=en-GB', [], $headers)->json('categories.0.id');

        $first = $this->api('POST', '/api/phrasebook/'.$categoryId.'/native-help?language=en-GB', [], $headers);
        $second = $this->api('POST', '/api/phrasebook/'.$categoryId.'/native-help?language=en-GB', [], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $calls());
    }

    public function test_generate_phrasebook_native_help_refreshes_stale_cache(): void
    {
        [, $headers] = $this->makeUser();
        $calls = $this->scriptPhrasebookHelp();
        $categoryId = $this->api('GET', '/api/phrasebook?language=en-GB', [], $headers)->json('categories.0.id');
        $now = Carbon::now('UTC');
        ResourceNativeHelp::query()->create([
            'resource_type' => 'phrasebook',
            'resource_key' => $categoryId,
            'target_language' => 'en-GB',
            'native_language' => 'es',
            'source_hash' => 'stale',
            'content' => [
                'summary' => 'Viejo',
                'usage_tips' => [],
                'register_notes' => [],
                'phrase_notes' => [],
                'common_traps' => [],
                'mini_glossary' => [],
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->api('POST', '/api/phrasebook/'.$categoryId.'/native-help?language=en-GB', [], $headers);

        $response->assertOk();
        $this->assertSame('Resumen de situación', $response->json('native_help.summary'));
        $this->assertSame(1, $calls());
    }

    public function test_generate_phrasebook_native_help_not_found(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('POST', '/api/phrasebook/nope/native-help?language=en-GB', [], $headers);
        $response->assertStatus(404);
        $this->assertSame('Phrasebook category not found', $response->json('detail'));
    }

    /**
     * @return callable(): int
     */
    private function scriptGrammarHelp(): callable
    {
        $adapter = new LlmAdapter('ollama', 'test');
        $calls = 0;
        $adapter->responder = function () use (&$calls) {
            $calls++;

            return json_encode([
                'summary' => 'Resumen breve',
                'explanation' => 'Explicación clara para estudiar este punto.',
                'key_points' => ['Punto clave 1', 'Punto clave 2', 'Punto clave 3'],
                'examples' => [['sentence' => 'I am learning.', 'note' => 'Ejemplo útil.']],
                'common_traps' => [['mistake' => 'Confundir la forma', 'fix' => 'Revisa el patrón.']],
                'mini_glossary' => [['term' => 'pattern', 'meaning' => 'patrón', 'note' => 'Úsalo como guía.']],
            ], JSON_UNESCAPED_UNICODE);
        };
        $this->app->instance(LlmAdapter::class, $adapter);

        return function () use (&$calls): int {
            return $calls;
        };
    }

    /**
     * @return callable(): int
     */
    private function scriptPhrasebookHelp(): callable
    {
        $adapter = new LlmAdapter('ollama', 'test');
        $calls = 0;
        $adapter->responder = function () use (&$calls) {
            $calls++;

            return json_encode([
                'summary' => 'Resumen de situación',
                'usage_tips' => ['Consejo 1', 'Consejo 2', 'Consejo 3'],
                'register_notes' => ['Nota de registro'],
                'phrase_notes' => [['phrase' => 'Hello', 'note' => 'Úsalo al saludar.']],
                'common_traps' => [['mistake' => 'Usarlo demasiado formal', 'fix' => 'Elige según contexto.']],
                'mini_glossary' => [['term' => 'Hello', 'meaning' => 'Hola', 'note' => 'Saludo básico.']],
            ], JSON_UNESCAPED_UNICODE);
        };
        $this->app->instance(LlmAdapter::class, $adapter);

        return function () use (&$calls): int {
            return $calls;
        };
    }
}
