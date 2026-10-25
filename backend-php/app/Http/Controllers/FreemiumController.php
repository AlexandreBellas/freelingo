<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\FreemiumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreemiumController extends Controller
{
    use ResolvesApiUser;

    public function __construct(private readonly FreemiumService $freemium) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->freemium->status($this->apiUser($request)));
    }
}
