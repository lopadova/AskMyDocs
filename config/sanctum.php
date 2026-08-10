<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests originating from the listed hosts will receive stateful SPA
    | authentication cookies (session + XSRF). The list is comma-separated
    | and parsed from env('SANCTUM_STATEFUL_DOMAINS'). Entries must be
    | bare hosts (no scheme), optionally with a port. Wildcards are allowed
    | (see Sanctum docs).
    |
    */

    'stateful' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:3000,localhost:5173,localhost:8000,127.0.0.1,127.0.0.1:8000,::1'
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | For SPA stateful authentication the session guard must be listed first
    | so Sanctum recognises the session cookie and falls through to the
    | bearer token path only when the cookie is missing.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | First-party SPA sessions are unaffected by this setting; it applies to
    | issued personal access tokens (the Tauri desktop PAT) only. Previously
    | `null` (never expire); SEC-TOKEN-001 requires a bounded lifetime, so it now
    | defaults to 30 days and is env-configurable. A leaked PAT can therefore
    | only be replayed for a bounded window even if it is never explicitly
    | revoked (and password reset revokes all of a user's tokens outright).
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
