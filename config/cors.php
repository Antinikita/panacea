<?php
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('ALLOWED_ORIGINS', 'http://localhost:3000')))
    ),

    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1):\d+$#',
        // Vercel preview deploys land on dynamic subdomains like
        // bagyt-git-feat-x-antinikitas-projects.vercel.app. We match
        // ONLY this project's preview URLs — `bagyt-...-antinikitas-projects` —
        // because a wildcard `*.vercel.app` would admit every other
        // Vercel customer's site into our CORS-credentialed origin list,
        // turning their apps into vectors against our API.
        '#^https://bagyt(-[a-z0-9-]+)?-antinikitas-projects\.vercel\.app$#',
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