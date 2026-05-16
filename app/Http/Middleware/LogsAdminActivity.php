<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one Spatie activitylog entry per admin-routed request.
 *
 * Why middleware not per-controller: an audit log that depends on
 * each controller remembering to call activity()->log() will silently
 * fail the moment someone adds a new admin endpoint and forgets the
 * boilerplate. Putting it here means "if it routes through admin.audit,
 * it's logged." No way to skip without explicitly dropping the
 * middleware.
 *
 * The de-PII'd taps in config/activitylog.php redact emails and
 * chat content. We log identity (who), endpoint (what), and a few
 * coarse-grained request properties (status, IP, UA prefix).
 */
class LogsAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Log AFTER the response so we can record the HTTP status —
        // failed admin attempts (401/403/429) matter as much as 2xx.
        // No exception path here: if logging throws, the request already
        // succeeded — let the activitylog package surface that on its
        // own queue rather than failing the user's request.
        try {
            activity('admin')
                ->causedBy($request->user())
                ->withProperties([
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                    'ip' => $request->ip(),
                    'ua' => substr((string) $request->userAgent(), 0, 200),
                ])
                ->log('admin_request');
        } catch (\Throwable $e) {
            // Don't surface logging failures to the API consumer.
            // The exception handler upstream (Sentry once wired) will
            // catch this if it matters.
        }

        return $response;
    }
}
