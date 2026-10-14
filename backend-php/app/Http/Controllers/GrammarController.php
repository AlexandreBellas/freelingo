<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Practice\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrammarController extends Controller
{
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
}
