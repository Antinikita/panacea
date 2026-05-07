<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in idempotency for AI-write endpoints.
 *
 * Clients send a UUID `Idempotency-Key` header on retries; the first call's
 * JSON response is cached for 24h under idempotency:{user_id}:{key}.
 *
 * The cached body is encrypted at rest because responses contain decrypted
 * PII (anamnesis fields, chat messages). Otherwise the file cache would
 * shadow-copy plaintext medical content into storage/framework/cache for
 * a full day after every AI write.
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

        if ($cipher = Cache::get($cacheKey)) {
            try {
                $cached = json_decode(Crypt::decryptString($cipher), true, 512, JSON_THROW_ON_ERROR);
                $response = new JsonResponse($cached['body'], $cached['status']);
                $response->headers->set('Idempotent-Replay', 'true');

                return $response;
            } catch (\Throwable $e) {
                Log::warning('Idempotency cache entry could not be decoded; falling through', [
                    'cache_key' => $cacheKey,
                    'error' => $e->getMessage(),
                ]);
                Cache::forget($cacheKey);
            }
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            $payload = json_encode([
                'body' => $response->getData(true),
                'status' => $response->getStatusCode(),
            ], JSON_UNESCAPED_UNICODE);

            Cache::put($cacheKey, Crypt::encryptString($payload), self::TTL_SECONDS);
        }

        return $response;
    }
}
