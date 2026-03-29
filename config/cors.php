<?php
return [
    'paths' => ['api/*'], // all your routes are under api/*, that's all you need

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'https://steeply-unremunerated-margarito.ngrok-free.dev', // fix: .app not .dev
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*'
    ],

    'exposed_headers' => [],
    'max_age' => 86400, // cache preflight for 24h, reduces OPTIONS requests

    'supports_credentials' => false, // token auth doesn't need cookies/credentials
];