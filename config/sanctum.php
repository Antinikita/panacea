<?php
return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')), // empty — not using cookie/session auth

    'guard' => ['web'],

    'expiration' => 43200, // 30 days, in minutes

    'middleware' => [
        'web',
    ],
];