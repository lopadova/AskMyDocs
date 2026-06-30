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
        // Authenticated admin stack — mirrors the host connectors group.
        // `api` brings the SPA stateful-Sanctum + throttle + bindings; the
        // gate reuses `manageConnectors` (admin + super-admin) since API
        // connectors live in the Connettori section. R32: this group has a
        // matching row in AdminAuthorizationMatrixTest.
        'middleware' => ['api', 'auth:sanctum', 'tenant.authorize', 'can:manageConnectors'],
    ],
];
