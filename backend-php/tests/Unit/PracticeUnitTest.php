<?php

namespace Tests\Unit;

use App\Services\Llm\LlmAdapter;
use App\Services\Llm\LlmResponseError;
use App\Services\Llm\LlmTimeoutError;
use App\Services\Llm\LlmToolsUnsupportedError;
use App\Services\Llm\LlmUnavailableError;
use App\Services\Practice\Catalog;
use App\Services\Practice\ChatMarkdown;
use App\Services\Practice\ConversationPipeline;
use App\Services\Practice\CurriculumPlanner;
use App\Services\Practice\Languages;
use App\Services\Practice\PlanCapacityError;
use App\Services\Practice\Prompts;
use App\Services\Practice\ReadingService;
use App\Services\Practice\Text;
use App\Services\Speech\OpenAiTtsService;
use Tests\TestCase;

class PracticeUnitTest extends TestCase
{
    public function test_chat_markdown_speech_boundaries(): void
    {
        $cases = [
            ['Use **went**, not *goed*.', 'Use went, not goed.'],
            ['Try ***this*** and __that__.', 'Try this and that.'],
            ["Hello.\n\nHow are you?", "Hello.\n\nHow are you?"],
            ["First line\nsecond line.  \nThird line.", "First line\nsecond line.\nThird line."],
            ["- I went home.\n- She went shopping.", "I went home.\nShe went shopping."],
            ["3. **Go** home\n4. *Stay* here", "Go home.\nStay here."],
            ["Examples:\n\n- Hello!\n- Goodbye\n\nYour turn.", "Examples:\n\nHello!\nGoodbye.\n\nYour turn."],
            ["- Animals:\n  - cats\n  - dogs\n- Birds", "Animals:\ncats.\ndogs.\nBirds."],
            ["- Say \"Hello!\"\n- Say \"Goodbye\"", "Say \"Hello!\"\nSay \"Goodbye\"."],
            ['2026, 3.14, -5 and 2 * 3; well-known, user_name.', '2026, 3.14, -5 and 2 * 3; well-known, user_name.'],
            ['Literal \*word\* and \_term\_.', 'Literal *word* and _term_.'],
            ['An unfinished **correction', 'An unfinished **correction'],
            ["# Example\n\n> Read [this](https://example.com).", "Example\n\nRead this."],
            ["`went`\n\n```text\nI went home.\n```", "went\n\nI went home."],
            ['A <b>word</b> and ![description](https://example.com/image.png).', 'A word and description.'],
            ["<script>alert('hidden')</script>\n\nHello.", 'Hello.'],
            ['---', ''],
        ];
        foreach ($cases as [$text, $expected]) {
            $this->assertSame($expected, ChatMarkdown::toSpeech($text), $text);
        }
        $images = [
            ['caf&#233;', 'café'],
            ['&#50; cats', '2 cats'],
            ['caf&#xE9; &amp; tea', 'café & tea'],
            ['\*cats\* and \_dogs\_', '*cats* and _dogs_'],
            ['**caf&#233;**', 'café'],
            ["first\\\nsecond", "first\nsecond"],
            ["first  \nsecond", "first\nsecond"],
            ["first\nsecond", "first\nsecond"],
            ['a <b>word</b>', 'a word'],
            ['`<b>word</b>`', '<b>word</b>'],
            ['a \<b>word\</b>', 'a <b>word</b>'],
            ["outer ![first\\\nsecond](https://example.com/nested.png)", "outer first\nsecond"],
        ];
        foreach ($images as [$label, $expected]) {
            $this->assertSame($expected, ChatMarkdown::toSpeech("![{$label}](https://example.com/image.png)"), $label);
            $this->assertSame($expected, ChatMarkdown::toSpeech("![{$label}][ref]\n\n[ref]: https://example.com/image.png"), $label);
        }
        foreach (['https://example.com', 'javascript:alert%281%29', 'file:///private/example', 'data:text/plain,hello'] as $destination) {
            $this->assertSame('Read this.', ChatMarkdown::toSpeech("Read [**this**]({$destination})."), $destination);
            $this->assertSame('Read this.', ChatMarkdown::toSpeech("Read [**this**][ref].\n\n[ref]: {$destination}"), $destination);
            $this->assertSame('Read this.', ChatMarkdown::toSpeech("Read ![**this**]({$destination})."), $destination);
        }
    }

    public function test_cjk_list_boundaries(): void
    {
        foreach (['ja-JP', 'zh-CN'] as $language) {
            $this->assertSame("你好。\n再见！", ChatMarkdown::toSpeech("- **你好**\n- 再见！", $language));
        }
    }

    public function test_autolink_labels_remain_verbatim(): void
    {
        $label = 'https://example.com/%32%30%32%36';
        $this->assertSame("Read {$label}.", ChatMarkdown::toSpeech("Read <{$label}>."));
    }

    public function test_reading_score(): void
    {
        $questions = [
            ['index' => 0, 'correct' => 'A'],
            ['index' => 1, 'correct' => 'B'],
            ['index' => 2, 'correct' => 'C'],
        ];
        $this->assertSame([3, 30], ReadingService::score($questions, ['0' => 'A', '1' => 'B', '2' => 'C']));
        $this->assertSame([0, 0], ReadingService::score($questions, ['0' => 'B', '1' => 'A', '2' => 'D']));
        $this->assertSame([2, 20], ReadingService::score($questions, ['0' => 'a', '1' => 'b', '2' => 'D']));
    }

    public function test_tts_cache_key_changes_with_instructions(): void
    {
        $plain = new OpenAiTtsService('key', 'tts-1', 'nova', 1.0);
        $capable = new OpenAiTtsService('key', 'gpt-4o-mini-tts', 'nova', 1.0);
        $this->assertNull($plain->getInstructions('en-GB'));
        $this->assertNotNull($capable->getInstructions('en-GB'));
        $this->assertSame($plain->getCacheKey('Hello', 'nova', 'en-GB'), $plain->getCacheKey('Hello', 'nova', 'en-GB'));
        $this->assertNotSame(
            $capable->getCacheKey('Hello', 'nova', null),
            $capable->getCacheKey('Hello', 'nova', 'en-GB'),
        );
    }

    public function test_split_tts_sentences(): void
    {
        $this->assertSame(['Hello.', 'How are you?'], ConversationPipeline::splitTtsSentences('Hello. How are you?'));
        $this->assertSame(['No stop'], ConversationPipeline::splitTtsSentences('No stop'));
    }

    public function test_conversation_prompt_includes_student_and_memory(): void
    {
        $prompt = Prompts::buildConversationSystemPrompt(
            'Ana',
            'B1',
            Languages::nativeName('es'),
            Languages::name('en-GB'),
            "\nStudent context:\n- Learning goals: travel\n",
            "<user_memories>\n<memory>Lives in Madrid</memory>\n</user_memories>\n",
        );
        $this->assertStringContainsString('Ana', $prompt);
        $this->assertStringContainsString('Spanish', $prompt);
        $this->assertStringContainsString('Lives in Madrid', $prompt);
        $this->assertStringContainsString(Prompts::tutorDisplayName(), $prompt);
        $this->assertStringContainsString('save_user_memory', Prompts::memoryInstruction('Spanish'));
    }

    public function test_json_only_instruction_is_shared(): void
    {
        $this->assertSame(Prompts::jsonOnly(), Prompts::jsonOnly());
        $this->assertNotSame('', Prompts::jsonOnly());
    }

    public function test_language_overlay_fallback(): void
    {
        $this->assertSame('', Prompts::languageOverlay('xx-YY'));
        $this->assertNotSame('', Prompts::languageOverlay('ja'));
    }

    public function test_curriculum_without_units_returns_no_slots(): void
    {
        $this->assertSame([], CurriculumPlanner::distributeUnits([], 4, 5));
    }

    public function test_plan_capacity_boundaries(): void
    {
        $units = [[
            'id' => 'u1',
            'level' => 'A1',
            'title' => 'Unit',
            'lesson_types' => ['grammar', 'vocabulary'],
            'grammar_points' => ['to-be'],
            'vocabulary_set_ids' => ['greetings_a1'],
            'competency_checklist' => ['Say hello'],
        ]];
        $slots = CurriculumPlanner::distributeUnits($units, 2, 3, 'en-GB');
        $this->assertNotEmpty($slots);
        $this->assertSame('completion-test', $slots[array_key_last($slots)]['unit_id'] ?? $slots[array_key_last($slots)]['id'] ?? null);
        try {
            CurriculumPlanner::assertPlanCapacity($units, 1, 1);
            $threw = false;
        } catch (PlanCapacityError) {
            $threw = true;
        }
        $this->assertTrue($threw);
    }

    public function test_catalog_language_fallback_and_levels(): void
    {
        $english = Catalog::vocabularySets('en-GB');
        $unknown = Catalog::vocabularySets('xx-YY');
        $this->assertNotEmpty($english);
        $this->assertSame($english[0]['id'], $unknown[0]['id']);
        $this->assertNotEmpty(Catalog::vocabularySets('ja-JP'));
        $this->assertNotEmpty(Catalog::grammarTopics('zh-CN'));
        $this->assertNotEmpty(Catalog::phrasebookCategories('ko-KR'));
        $this->assertSame([], Catalog::curriculumUnits('Z9', 'en-GB'));
    }

    public function test_llm_adapter_errors_and_json(): void
    {
        $adapter = new LlmAdapter('ollama', 'test');
        $adapter->responder = fn () => throw new \RuntimeException('connection refused');
        try {
            $adapter->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('expected unavailable');
        } catch (LlmUnavailableError) {
            $this->assertTrue(true);
        }
        $adapter->responder = fn () => throw new \RuntimeException('request timed out');
        try {
            $adapter->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('expected timeout');
        } catch (LlmTimeoutError) {
            $this->assertTrue(true);
        }
        $adapter->responder = fn () => 'not-json';
        try {
            $adapter->structuredOutput([['role' => 'user', 'content' => 'give json']]);
            $this->fail('expected response error');
        } catch (LlmResponseError $error) {
            $this->assertNotNull($error->rawResponse);
        }
        $this->assertSame(['a' => 1], LlmAdapter::parseLlmJson("```json\n{\"a\": 1}\n```"));
        $this->assertTrue(LlmAdapter::isToolsUnsupported(new \RuntimeException("provider does not support tools")));
        $this->assertInstanceOf(LlmToolsUnsupportedError::class, new LlmToolsUnsupportedError('no tools'));
    }

    public function test_canonical_hash_is_stable(): void
    {
        $this->assertSame(
            Text::sha256Canonical(['b' => 1, 'a' => ['z' => 2, 'y' => 3]]),
            hash('sha256', '{"a":{"y":3,"z":2},"b":1}'),
        );
    }

    public function test_frontend_catalog_integrity(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3).'/frontend/src/data/curriculum.ts');
        $this->assertNotFalse($src);
        preg_match_all("/grammar_points:\\s*\\[([^\\]]*)\\]/", $src, $blocks);
        $referenced = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([^']+)'/", $block, $slugs);
            foreach ($slugs[1] as $slug) {
                $referenced[$slug] = true;
            }
        }
        $defined = [];
        foreach (['en-GB', 'en-US', 'es-ES', 'it-IT', 'pt-PT', 'fr-FR', 'de-DE', 'ja-JP', 'ko-KR', 'zh-CN'] as $language) {
            foreach (Catalog::grammarTopics($language) as $topic) {
                $defined[$topic['slug']] = true;
                foreach ($topic['related'] ?? [] as $related) {
                    $this->assertArrayHasKey($related, array_column(Catalog::grammarTopics($language), 'slug', 'slug'), $language.' '.$related);
                }
            }
            $ids = [];
            foreach (Catalog::vocabularySets($language) as $set) {
                $this->assertArrayNotHasKey($set['id'], $ids);
                $ids[$set['id']] = true;
            }
            foreach (Catalog::curriculum($language) as $units) {
                foreach ($units as $unit) {
                    foreach ($unit['vocabulary_set_ids'] ?? [] as $id) {
                        $this->assertArrayHasKey($id, $ids, $language.' '.$id);
                    }
                }
            }
        }
        foreach (array_keys($referenced) as $slug) {
            $this->assertArrayHasKey($slug, $defined, $slug);
        }
    }
}
