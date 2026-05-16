<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes Sanctum tokens that haven't been used in SANCTUM_INACTIVITY_MINUTES.
 *
 * Sanctum's built-in `expiration` is absolute (N minutes from token
 * creation) — a token issued on a public computer at 09:00 stays valid
 * until 09:00 + N regardless of whether the user walked away at 09:05.
 * This middleware adds the missing inactivity dimension: if the last
 * recorded use of the current token was more than INACTIVITY_MINUTES
 * ago, the token is deleted and the request gets a 401.
 *
 * Where to add it: in the `auth:sanctum` middleware chain on routes
 * that touch sensitive data (admin, profile, anamnesis, chat).
 */
class RevokeIdleSanctumTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) env('SANCTUM_INACTIVITY_MINUTES', 60);

        if ($minutes > 0) {
            $token = $request->user()?->currentAccessToken();

            // PersonalAccessToken model — guard against transient-token
            // bypass that older Sanctum versions used in tests.
            if ($token && method_exists($token, 'getKey') && $token->last_used_at) {
                $idleFor = now()->diffInMinutes($token->last_used_at, false);

                // diffInMinutes returns negative when last_used_at is in
                // the past (which it almost always is). Compare against
                // the negative threshold so "idleFor < -minutes" means
                // "older than minutes ago."
                if ($idleFor < -$minutes) {
                    $token->delete();

                    return response()->json([
                        'message' => 'Session expired due to inactivity. Please sign in again.',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
