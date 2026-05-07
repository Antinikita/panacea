<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sanctum doesn't auto-prune expired personal_access_tokens rows.
// Two shapes need handling:
//   1. Tokens with explicit expires_at past now (we now stamp this on
//      every createToken via AuthController::tokenExpiration()).
//   2. Legacy / library-default tokens with expires_at = NULL — Sanctum
//      authenticates them only while created_at is within the configured
//      sanctum.expiration window. Once they're past that, they're
//      effectively dead and should be pruned by age.
// Without the second branch, every legacy token stays in the table forever.
Schedule::call(function () {
    $ttlMinutes = (int) config('sanctum.expiration', 0);

    PersonalAccessToken::query()
        ->where(function ($q) use ($ttlMinutes) {
            $q->where(function ($qq) {
                $qq->whereNotNull('expires_at')->where('expires_at', '<', now());
            });
            if ($ttlMinutes > 0) {
                $q->orWhere(function ($qq) use ($ttlMinutes) {
                    $qq->whereNull('expires_at')
                       ->where('created_at', '<', now()->subMinutes($ttlMinutes));
                });
            }
        })
        ->delete();
})->daily()->name('prune-expired-tokens')->onOneServer();

// activity_log retention. Spatie's vendor default is 365 days; the
// command honors that without arguments. Without this schedule the
// table grows forever — every login, every encrypted-attribute change,
// every password-reset event — and PII queries against it slow over
// time. Run weekly off-peak.
Schedule::command('activitylog:clean')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('prune-activity-log')
    ->onOneServer();
