<?php

$defaultAllowedOrigins = implode(',', [
    'http://localhost:5173',
    'http://localhost:3000',
]);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin) => trim($origin),
        explode(',', env('CORS_ALLOWED_ORIGINS', $defaultAllowedOrigins))
    ))),

    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
