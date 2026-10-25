<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Practice\Catalog;
use App\Services\Practice\NativeHelp;
use App\Services\Practice\Prompts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrammarController extends Controller
{
    use ResolvesApiUser;
    public function index(Request $request): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['topics' => Catalog::grammarTopics($language)]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $topic = Catalog::grammarTopic($slug, $language);
        if ($topic === null) {
            throw new ApiException('Grammar topic not found', 404);
        }

        return response()->json(['topic' => $topic]);
    }

    public function nativeHelp(Request $request, string $slug, NativeHelp $help): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $topic = Catalog::grammarTopic($slug, $language);
        if ($topic === null) {
            throw new ApiException('Grammar topic not found', 404);
        }
        $source = [
            'slug' => $topic['slug'],
            'title' => $topic['title'],
            'level' => $topic['level'],
            'category' => $topic['category'],
            'summary' => $topic['summary'],
            'explanation' => $topic['explanation'],
            'structure' => $topic['structure'] ?? null,
            'rules' => $topic['rules'] ?? [],
            'examples' => array_map(fn (array $example) => [
                'text' => $example['text'],
                'translation' => $example['translation'] ?? null,
                'note' => $example['note'] ?? null,
            ], $topic['examples'] ?? []),
            'common_mistakes' => array_map(fn (array $mistake) => [
                'wrong' => $mistake['wrong'],
                'correct' => $mistake['correct'],
                'note' => $mistake['note'] ?? null,
            ], $topic['common_mistakes'] ?? []),
        ];
        $content = $help->resolve(
            $this->apiUser($request),
            'grammar',
            $topic['slug'],
            $language,
            $source,
            fn (string $target, string $native, string $json) => Prompts::grammarNativeHelp($target, $native, $json),
        );

        return response()->json(['native_help' => $content]);
    }
}
