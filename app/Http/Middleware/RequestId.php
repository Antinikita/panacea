<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every request with a UUID so logs from the same request can be
 * correlated across modules (controller -> AIService -> queue jobs).
 *
 * Honors an incoming X-Request-Id header if it's already a UUID, so a
 * gateway/proxy can propagate one. Otherwise generates a fresh v4.
 *
 * The id is:
 *   - pushed into Log::shareContext() so every Log::* call in the request
 *     scope carries it
 *   - returned as the X-Request-Id response header so clients can include
 *     it in error reports
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $id = $this->isValidUuid($incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    private function isValidUuid(?string $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
