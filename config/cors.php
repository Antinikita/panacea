<?php
return [
    'paths' => ['api/*'], // all your routes are under api/*, that's all you need

    'allowed_methods' => ['*'],


    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173'))
    ))),


    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*'
    ],

    'exposed_headers' => [],
    'max_age' => 86400, // cache preflight for 24h, reduces OPTIONS requests

    'supports_credentials' => false, // token auth doesn't need cookies/credentials
];