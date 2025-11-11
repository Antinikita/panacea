<?php 
// Source - https://stackoverflow.com/a
// Posted by Kamyar Safari
// Retrieved 2025-11-10, License - CC BY-SA 4.0

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'   => ['*'],
    'allowed_origins'   => [
        'http://localhost:5173',
        'https://394b47e2a667.ngrok-free.app'
    ],
    'allowed_origins_patterns' => [
        '*localhost*'
    ],
    'allowed_headers'   => ['*'],
    'exposed_headers'   => [],
    'max_age'           => 3600,
    'supports_credentials' => true,
];
