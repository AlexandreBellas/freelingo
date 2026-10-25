<?php

namespace App\Http\Controllers;

use App\Services\Practice\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurriculumController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $curriculum = Catalog::curriculum($language);
        $payload = [];
        foreach (Catalog::LEVELS as $level) {
            $payload[$level] = $curriculum[$level] ?? [];
        }

        return response()->json($payload);
    }

    public function show(Request $request, string $level): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(Catalog::curriculumUnits($level, $language));
    }
}
