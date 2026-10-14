<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Practice\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhrasebookController extends Controller
{
    /** @var list<string> */
    private const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public function index(Request $request): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['categories' => Catalog::phrasebookCategories($language)]);
    }

    public function byLevel(Request $request, string $level): JsonResponse
    {
        $upper = strtoupper($level);
        if (! in_array($upper, self::LEVELS, true)) {
            throw new ApiException('Invalid CEFR level. Must be one of: '.implode(', ', self::LEVELS), 400);
        }
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['categories' => Catalog::phrasebookByLevel($upper, $language)]);
    }

    public function show(Request $request, string $categoryId): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $category = Catalog::phrasebookCategory($categoryId, $language);
        if ($category === null) {
            throw new ApiException('Phrasebook category not found', 404);
        }

        return response()->json(['category' => $category]);
    }

    public function audio(Request $request, string $categoryId, int $phraseIndex): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $category = Catalog::phrasebookCategory($categoryId, $language);
        if ($category === null) {
            throw new ApiException('Phrasebook category not found', 404);
        }
        $phrases = $category['phrases'] ?? [];
        if ($phraseIndex < 0 || $phraseIndex >= count($phrases)) {
            throw new ApiException('Phrase not found', 404);
        }

        throw new ApiException('TTS service is not enabled', 503);
    }
}
