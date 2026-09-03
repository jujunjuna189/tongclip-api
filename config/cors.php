<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://127.0.0.1:5173'),
        'http://localhost:5173',
        'https://tongclip.rssolusi.com',
    ],
    'allowed_origins_patterns' => [
        '#^http://localhost:517[3-9]$#',
        '#^http://127\.0\.0\.1:517[3-9]$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
