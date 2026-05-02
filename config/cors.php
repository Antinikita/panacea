<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'], // all your routes are under api/*, that's all you need

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('ALLOWED_ORIGINS', 'localhost')),

    'allowed_origins_patterns' => [ '#^http://(localhost|127\.0\.0\.1):\d+$#',
    '#^https?://192\.168\..*#',],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'Idempotency-Key',
        'X-Request-Id',
    ],

    'exposed_headers' => ['Authorization'],
    'max_age' => 0, // cache preflight for 24h, reduces OPTIONS requests

    'supports_credentials' => true, // token auth doesn't need cookies/credentials
];