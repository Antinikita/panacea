<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Module routes are loaded by each Modules/<Name>/<Name>ServiceProvider.
// Only global infrastructure routes belong here.

Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Deeper probe: confirms the ai-service is reachable. Used for the
// system-status indicator on the demo page and for ops alerting.
// Cheap /health stays for load balancers — this one costs an outbound HTTP.
Route::get('/health/deep', function () {
    $aiBase = rtrim(env('AI_MODULE_URL', ''), '/');
    $ai = ['status' => 'unknown', 'latency_ms' => null];

    if ($aiBase) {
        $start = microtime(true);
        try {
            $response = Http::connectTimeout(3)->timeout(3)->get($aiBase);
            $ai = [
                'status' => $response->status() < 500 ? 'ok' : 'down',
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        } catch (ConnectionException $e) {
            $ai = [
                'status' => 'down',
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    return response()->json([
        'laravel' => 'ok',
        'ai_service' => $ai,
    ], $ai['status'] === 'down' ? 503 : 200);
});
