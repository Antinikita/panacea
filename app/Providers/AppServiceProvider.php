<?php

namespace App\Providers;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        Password::defaults(function () {
            $rule = Password::min(8)->letters()->numbers();

            // HIBP breach check via uncompromised() hits the Have-I-Been-Pwned
            // API on every password validation. We skip it in local + testing
            // (network-free dev / fast tests) but enable it for staging and
            // production so real users can't pick a known-compromised password.
            return $this->app->environment('local', 'testing')
                ? $rule
                : $rule->uncompromised();
        });

        // Force HTTPS for every generated URL in production. Has no
        // effect locally, where APP_ENV=local. Pair with a web-server
        // level redirect for inbound HTTP→HTTPS.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // The reset email link goes to the SPA's /reset-password page,
        // not Laravel — the SPA reads the token+email and POSTs them to
        // the API. Without this, Laravel's default URL points at a
        // non-existent web route.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return rtrim(config('app.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // Strict throttle on auth endpoints (login, register, forgot-password,
        // reset-password). Two limits combined — whichever trips first blocks
        // the request:
        //   - 5/min per source IP: kills a single host hammering credentials.
        //   - 5/15min per attempted email: defeats credential-stuffing across
        //     a botnet of fresh IPs all targeting one account, which the IP
        //     limiter alone won't catch.
        RateLimiter::for('auth-strict', function (Request $request) {
            $limits = [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
            ];
            $email = mb_strtolower(trim((string) $request->input('email', '')));
            if ($email !== '') {
                $limits[] = Limit::perMinutes(15, 5)->by('email:'.$email);
            }
            return $limits;
        });

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
