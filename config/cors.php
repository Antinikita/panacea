<?php 
// Source - https://stackoverflow.com/a
// Posted by Kamyar Safari
// Retrieved 2025-11-10, License - CC BY-SA 4.0

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],
    'allowed_methods'   => ['*'],
    'allowed_origins'   => [
        'http://localhost:5173',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers'   => ['*'],
    'exposed_headers'   => [],
    'max_age'           => 3600,
    'supports_credentials' => true,
];
