<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Practice\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VocabularyController extends Controller
{
    /** @var list<string> */
    private const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public function index(Request $request): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['sets' => Catalog::vocabularySets($language)]);
    }

    public function byLevel(Request $request, string $level): JsonResponse
    {
        $upper = strtoupper($level);
        if (! in_array($upper, self::LEVELS, true)) {
            throw new ApiException('Invalid CEFR level. Must be one of: '.implode(', ', self::LEVELS), 400);
        }
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['sets' => Catalog::vocabularyByLevel($upper, $language)]);
    }

    public function show(Request $request, string $setId): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $set = Catalog::vocabularySet($setId, $language);
        if ($set === null) {
            throw new ApiException('Vocabulary set not found', 404);
        }

        return response()->json(['set' => $set]);
    }
}
