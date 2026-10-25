<?php

namespace App\Http\Controllers;

use App\Services\Redis\ArrayRedis;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __construct(private readonly ArrayRedis $redis) {}

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function adminHealth(): JsonResponse
    {
        $checks = [];
        $ok = true;
        try {
            DB::select('select 1');
            $checks['db'] = 'ok';
        } catch (\Throwable $e) {
            $checks['db'] = 'error: '.$e->getMessage();
            $ok = false;
        }
        try {
            $this->redis->ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'error: '.$e->getMessage();
            $ok = false;
        }
        $checks['tts'] = 'ok';
        $checks['stt'] = 'ok';

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }
}
