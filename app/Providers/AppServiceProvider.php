<?php

namespace App\Providers;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'chat' => Chat::class,
            'chat_message' => ChatMessage::class,
            'anamnesis' => Anamnesis::class,
        ]);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Strict per-IP cap on /login + /register to slow brute-force.
        RateLimiter::for('auth-strict', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Per-user cap on AI-write endpoints (chat send/regenerate/update,
        // anamnesis generate). 30/min covers heavy demo use without
        // letting a single account run up the AI bill.
        RateLimiter::for('ai-write', fn (Request $request) => Limit::perMinute(30)
            ->by(optional($request->user())->id ?: $request->ip()));

        // Generic per-user cap on the rest of the authenticated API.
        RateLimiter::for('api-default', fn (Request $request) => Limit::perMinute(120)
            ->by(optional($request->user())->id ?: $request->ip()));
    }
}
