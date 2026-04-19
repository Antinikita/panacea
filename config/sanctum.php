<?php
return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')), // empty — not using cookie/session auth

    'guard' => ['web'],

    'expiration' => 525600, // tokens expire in 30 days (good practice)

    'middleware' => [
        'web',
    ],
];