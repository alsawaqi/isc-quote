<?php

return [
    'issuer' => env('APP_URL', 'http://localhost'),
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'ttl' => (int) env('JWT_TTL', 120),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 1440),
    'remember_refresh_ttl' => (int) env('JWT_REMEMBER_REFRESH_TTL', 43200),
];
