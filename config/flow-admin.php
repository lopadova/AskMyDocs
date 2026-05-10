<?php

declare(strict_types=1);
use App\Flow\Admin\AskMyDocsFlowAuthorizer;

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch (AskMyDocs-specific extension)
    |--------------------------------------------------------------------------
    | Not present in the vendor's published config. AskMyDocs adds it so
    | the cockpit stays dark on a fresh deploy until the operator opts
    | in by setting FLOW_ADMIN_ENABLED=true. When false, the
    | `App\Http\Middleware\FlowAdminEnabled` middleware (auto-attached
    | by FlowAdminIntegrationServiceProvider) returns a 404 for every
    | route under the configured prefix — satisfying R14 (correct
    | semantic for a disabled subsystem) and preventing operator panic
    | over an unfamiliar admin surface materialising silently.
    */
    'enabled' => env('FLOW_ADMIN_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    | The URI prefix for all laravel-flow-admin routes. Defaults to
    | `admin/flows` so the cockpit nests under the AskMyDocs admin shell
    | (mirroring the `admin/pii-redactor` mount strategy from sub-PR 5).
    */
    'prefix' => env('FLOW_ADMIN_PREFIX', 'admin/flows'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Applied to every admin route. Require auth in production by default.
    | Override with FLOW_ADMIN_MIDDLEWARE="web,auth,verified" or similar.
    | The AskMyDocs default prepends `flow-admin.enabled` (the master
    | switch middleware) and appends `can:viewFlowAdmin` (the Spatie
    | role-backed Gate). The package's empty-fallback safety net is
    | preserved.
    |
    | If the env value is empty, whitespace-only, or resolves to no entries
    | after trim/filter, we fall back to ['web']. We never ship an empty
    | middleware array: that would silently disable session, CSRF, and the
    | session-driven authenticator on the admin routes — a footgun for
    | operators who set FLOW_ADMIN_MIDDLEWARE="" thinking they were disabling
    | only `auth`.
    */
    'middleware' => (function (): array {
        $resolved = array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'FLOW_ADMIN_MIDDLEWARE',
                    'web,auth,flow-admin.enabled,can:viewFlowAdmin',
                ),
            ),
        ), static fn (string $name): bool => $name !== ''));

        return $resolved !== [] ? $resolved : ['web'];
    })(),

    /*
    |--------------------------------------------------------------------------
    | Read model adapter
    |--------------------------------------------------------------------------
    | 'eloquent' — default, reads from the flow_* tables via padosoft/laravel-flow.
    | 'array'    — deterministic seed-42 fixtures; used for Playwright E2E tests.
    */
    'adapter' => env('FLOW_ADMIN_ADAPTER', 'eloquent'),

    /*
    |--------------------------------------------------------------------------
    | Action authorizer
    |--------------------------------------------------------------------------
    | Default deny-by-default implementation. Override in host apps to integrate
    | your permission model and make read / mutation actions available.
    |
    | AskMyDocs binds {@see AskMyDocsFlowAuthorizer} which maps the 8
    | methods on the package's ActionAuthorizer contract to Spatie
    | role checks (super-admin / admin / dpo). Tenant scoping happens
    | inside that class — every action checks the row's tenant_id
    | against the active TenantContext (R30).
    */
    'authorizer' => AskMyDocsFlowAuthorizer::class,

    /*
    |--------------------------------------------------------------------------
    | Auto-refresh polling interval (milliseconds)
    |--------------------------------------------------------------------------
    */
    'polling_interval_ms' => (int) env('FLOW_ADMIN_POLLING_MS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Default theme
    |--------------------------------------------------------------------------
    | 'dark' or 'light'. Persisted per-user in cookie flow_admin_theme.
    */
    'theme_default' => env('FLOW_ADMIN_THEME', 'dark'),

    /*
    |--------------------------------------------------------------------------
    | Default step visualization
    |--------------------------------------------------------------------------
    | 'timeline', 'gantt', or 'dag'.
    */
    'step_viz_default' => env('FLOW_ADMIN_STEP_VIZ', 'timeline'),
];
