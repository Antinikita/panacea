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
//
// Gated behind a shared secret in HEALTH_PROBE_TOKEN (or auth:sanctum) so
// a public endpoint can't be used to fingerprint the AI-service status
// + latency on every request. When HEALTH_PROBE_TOKEN is unset, the
// route falls through and only Sanctum-authenticated callers can reach
// it. Hash-comparison via hash_equals avoids timing attacks.
Route::get('/health/deep', function (\Illuminate\Http\Request $request) {
    $expected = (string) env('HEALTH_PROBE_TOKEN', '');
    $supplied = (string) $request->header('X-Health-Probe-Token', '');
    $tokenOk = $expected !== '' && hash_equals($expected, $supplied);
    $authedOk = (bool) $request->user('sanctum');
    if (! $tokenOk && ! $authedOk) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

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
