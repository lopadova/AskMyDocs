<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API Connector (host overrides)
|--------------------------------------------------------------------------
|
| The package ships its own config/connector-api.php with safe defaults
| (SSRF guard on, https-only, output cap, chat-tools on). This host file
| overrides ONLY the bits AskMyDocs must control — chiefly the admin route
| middleware (R32): the package default leaves the routes UNAUTHENTICATED
| (`['api']`), so the host MUST replace it with its authenticated admin stack.
|
| mergeConfigFrom is a shallow top-level merge with the app config winning, so
| this `routes` block fully replaces the package's; every other top-level key
| (ssrf, output, chat_tools, tools, defaults, llm_assist) keeps the package
| default and stays env-overridable.
|
*/

return [
    'routes' => [
        'enabled' => (bool) env('API_CONNECTOR_ROUTES_ENABLED', true),
        'prefix' => env('API_CONNECTOR_ROUTES_PREFIX', 'api/admin/api-connectors'),
        // Authenticated admin stack — mirrors the host admin groups in
        // routes/api.php EXACTLY. `EncryptCookies` + `StartSession` are
        // MANDATORY: bootstrap/app.php sets up NO stateful-api group (it adds
        // session/cookie middleware inline on each route group instead), so
        // without them here `auth:sanctum` cannot read the SPA session and a
        // cookie-authenticated browser request gets 401 — even though a Bearer
        // token still works (the token guard needs no session). `api` adds
        // throttle + bindings; the gate reuses `manageConnectors` (admin +
        // super-admin). R32: this group has a matching row in
        // AdminAuthorizationMatrixTest.
        'middleware' => [
            'api',
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
            'can:manageConnectors',
        ],
    ],
];
