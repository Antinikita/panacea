<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in idempotency for AI-write endpoints.
 *
 * Clients send a UUID `Idempotency-Key` header on retries; the first call's
 * JSON response is cached for 24h under idempotency:{user_id}:{key}. Subsequent
 * calls with the same key return the cached response, so a network blip that
 * makes a client retry doesn't create duplicate user/assistant messages.
 *
 * Skips when the header is absent (pure pass-through).
 */
class Idempotency
{
    private const TTL_SECONDS = 86_400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return $next($request);
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return new JsonResponse([
                'error' => 'Idempotency-Key must be a UUID v4',
            ], 400);
        }

        $cacheKey = 'idempotency:'.(Auth::id() ?? 'anon').':'.strtolower($key);

        if ($cached = Cache::get($cacheKey)) {
            $response = new JsonResponse($cached['body'], $cached['status']);
            $response->headers->set('Idempotent-Replay', 'true');

            return $response;
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            Cache::put($cacheKey, [
                'body' => $response->getData(true),
                'status' => $response->getStatusCode(),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
