<?php
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('ALLOWED_ORIGINS', 'http://localhost:3000')))
    ),

    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1):\d+$#',
    ],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'Idempotency-Key',
        'X-Request-Id',
    ],

    'exposed_headers' => ['Authorization'],
    'max_age' => 86400,

    'supports_credentials' => false,
];