<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Invitations Admin SPA — HOST overrides (AskMyDocs)
|--------------------------------------------------------------------------
|
| Vendor defaults live in `vendor/padosoft/laravel-invitations-admin/config/
| invitations-admin.php`. The package serves a self-contained, prebuilt React
| SPA (its own Blade shell + hashed Vite assets out of resources/dist — no
| vendor:publish, no host JS toolchain) and gates route REGISTRATION on the
| `enabled` flag — so when disabled NO route is registered and every request
| under the prefix is a clean 404 (R43 OFF-state, no 500).
|
| AskMyDocs tweaks, mirroring the sibling self-contained admin SPAs
| (config/ai-finops-admin.php, config/flow-admin.php):
|
|   - Master switch defaults to FALSE. The panel stays dark on a fresh deploy;
|     operators flip INVITATIONS_ADMIN_ENABLED=true once the right roles are
|     granted. (The prebuilt bundle ships in the package, so there is no
|     publish step.)
|   - Mounted under /admin/invitations so it sits next to the rest of the
|     AskMyDocs admin surface and matches the core API prefix one level up.
|   - The package default middleware `['web']` is replaced with
|     `web,auth,can:manageInvitations`: session + the web auth guard (302 to
|     /login for guests) + the SAME gate the core invitations API + PR #363
|     used. Opening the panel needs `manageInvitations` (super-admin + admin);
|     every write button hits the core API, which independently re-enforces the
|     same gate via config/invitations.php's admin_middleware.
|   - api_base points at the host core route group (api/admin/invitations) that
|     PR #363 mounted — the single-core-API model: this panel, AskMyDocs, and
|     the MCP tools all consume the one core.
|   - tenant_label is informational only (the top-bar indicator); real tenant
|     switching is the host's job via the team switcher + X-Tenant-Id header.
*/

return [
    'enabled' => (bool) env('INVITATIONS_ADMIN_ENABLED', false),

    'route_prefix' => env('INVITATIONS_ADMIN_ROUTE_PREFIX', 'admin/invitations'),

    'middleware' => (function (): array {
        $resolved = array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('INVITATIONS_ADMIN_MIDDLEWARE', 'web,auth,can:manageInvitations'),
        )), static fn (string $name): bool => $name !== ''));

        // NEVER ship an empty middleware array. An operator who sets
        // INVITATIONS_ADMIN_MIDDLEWARE="" thinking they only drop `auth` would
        // otherwise expose the panel with NO session/auth/gate at all (R32
        // footgun) — fall back to the full secure default, same guard as
        // config/ai-finops-admin.php.
        return $resolved !== [] ? $resolved : ['web', 'auth', 'can:manageInvitations'];
    })(),

    // The SPA calls the core padosoft/laravel-invitations API that PR #363
    // mounted at api/admin/invitations; point the panel there explicitly.
    'api_base' => env('INVITATIONS_ADMIN_API_BASE', '/api/admin/invitations'),

    'tenant_label' => env('INVITATIONS_ADMIN_TENANT_LABEL', 'default'),
];
