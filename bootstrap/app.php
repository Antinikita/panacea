<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // <--- Make sure this path is correct
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', null));

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\RequestId::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->web(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\RequestId::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->alias([
            'idempotency' => \App\Http\Middleware\Idempotency::class,
            'admin.audit' => \App\Http\Middleware\LogsAdminActivity::class,
            'sanctum.inactivity' => \App\Http\Middleware\RevokeIdleSanctumTokens::class,
            // Spatie permission middlewares — registered explicitly because
            // we don't autoload Spatie's package middleware aliases.
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // No-op when SENTRY_LARAVEL_DSN is empty (local dev). In prod
        // it captures unhandled exceptions and HTTP 5xx with breadcrumbs.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();