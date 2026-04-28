<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sanctum doesn't auto-prune expired personal_access_tokens rows.
// Without this, the table grows unbounded — every issued token stays
// in DB even after its expires_at has passed.
Schedule::call(function () {
    PersonalAccessToken::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->name('prune-expired-tokens')->onOneServer();
