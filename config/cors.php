<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Tuned for a React SPA authenticating via Sanctum session cookies. The
| browser will only send cookies cross-origin when `supports_credentials`
| is true AND `allowed_origins` lists every origin explicitly (wildcard
| `*` is invalid for credentialed requests per the CORS spec).
|
| When adding a new dev/staging origin, update BOTH:
|   - CORS_ALLOWED_ORIGINS (full origins, with scheme)
|   - SANCTUM_STATEFUL_DOMAINS (bare hosts, no scheme)
|
*/

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://localhost:3000,http://localhost:8000'
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
