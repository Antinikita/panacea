<?php
return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')), // empty — not using cookie/session auth

    'guard' => ['web'],

    // 7 days default (in minutes); override via SANCTUM_EXPIRATION env.
    // Stolen tokens are useful only for this window before forcing re-auth.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 10080),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'web',
    ],
];