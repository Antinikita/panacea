<?php
return [
    'stateful' => [], // empty — not using cookie/session auth

    'guard' => [], // change from 'web' to 'api'

    'expiration' => 60 * 24 * 30, // tokens expire in 30 days (good practice)

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];