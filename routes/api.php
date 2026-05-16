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

    $aiUrl = (string) env('AI_MODULE_URL', '');
    $ai = ['status' => 'unknown', 'latency_ms' => null];

    if ($aiUrl) {
        // Probe the SAME endpoint Laravel actually calls, with the SAME
        // auth header, so a token mismatch surfaces here instead of
        // looking healthy. POST an empty body — the service should
        // reject it with 422 (missing field) but ONLY after passing
        // auth. 401/403 mean the token is wrong; 5xx means the
        // service itself is broken. Anything else (200, 422, 400)
        // means "reachable and authenticated", which is what we
        // actually need to report.
        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'X-Service-Token' => (string) env('AI_SERVICE_TOKEN', ''),
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(3)
                ->timeout(5)
                ->post($aiUrl, []);

            $code = $response->status();
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($code === 401 || $code === 403) {
                $ai = ['status' => 'unauthorized', 'http' => $code, 'latency_ms' => $latency];
            } elseif ($code >= 500) {
                $ai = ['status' => 'down', 'http' => $code, 'latency_ms' => $latency];
            } else {
                $ai = ['status' => 'ok', 'http' => $code, 'latency_ms' => $latency];
            }
        } catch (ConnectionException $e) {
            $ai = [
                'status' => 'down',
                'http' => null,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    // 200 only when auth handshake actually works. 503 for any failure
    // mode (down OR unauthorized) so external uptime monitors can alert
    // on the real condition, not just "service responded at all".
    $httpStatus = $ai['status'] === 'ok' ? 200 : 503;

    return response()->json([
        'laravel' => 'ok',
        'ai_service' => $ai,
    ], $httpStatus);
});
