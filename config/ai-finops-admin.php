<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI FinOps Admin SPA — HOST overrides (AskMyDocs)
|--------------------------------------------------------------------------
|
| Vendor defaults live in `vendor/padosoft/laravel-ai-finops-admin/config/
| ai-finops-admin.php`. The package mounts a self-contained React SPA (its
| own prefix, its own prebuilt Vite assets) and gates route REGISTRATION on
| the `enabled` flag — so when disabled NO route is registered and every
| request under the prefix is a clean 404 (R43 OFF-state, no 500).
|
| AskMyDocs tweaks, mirroring the sibling self-contained admin SPAs
| (config/pii-redactor-admin.php, config/flow-admin.php):
|
|   - Master switch defaults to FALSE. The cockpit stays dark on a fresh
|     deploy; operators flip AI_FINOPS_ADMIN_ENABLED=true once they have
|     granted the right roles and published the assets
|     (`php artisan vendor:publish --tag=ai-finops-admin-assets --force`).
|   - Mounted under /admin/ai-finops so it sits next to the rest of the
|     AskMyDocs admin surface.
|   - The package default middleware `['web','auth']` is replaced with
|     `web,auth,can:viewAiFinOps`: session + the web auth guard (302 to
|     /login for guests) + the same view Gate the core API uses. Opening the
|     panel needs `viewAiFinOps` (super-admin + admin); the WRITE buttons hit
|     the core API which separately enforces `manageAiFinOps` (super-admin)
|     via the method-aware finops.authorize middleware.
|   - api_base points at the host-relocated core prefix (api/admin/ai-finops).
*/

return [
    'enabled' => env('AI_FINOPS_ADMIN_ENABLED', false),

    'route' => [
        'prefix' => env('AI_FINOPS_ADMIN_PREFIX', 'admin/ai-finops'),
        'middleware' => (function (): array {
            $resolved = array_values(array_filter(array_map('trim', explode(
                ',',
                (string) env('AI_FINOPS_ADMIN_MIDDLEWARE', 'web,auth,can:viewAiFinOps'),
            )), static fn (string $name): bool => $name !== ''));

            // NEVER ship an empty middleware array. An operator who sets
            // AI_FINOPS_ADMIN_MIDDLEWARE="" (or all-whitespace) thinking they
            // disable only `auth` would otherwise expose the cockpit with NO
            // session/auth/gate at all (R32 footgun). Fall back to the full
            // secure default — same guard as config/flow-admin.php.
            return $resolved !== [] ? $resolved : ['web', 'auth', 'can:viewAiFinOps'];
        })(),
    ],

    // The SPA calls the core laravel-ai-finops API. The core prefix was
    // relocated to api/admin/ai-finops by config/ai-finops.php, so point the
    // SPA there explicitly rather than relying on the vendor fallback.
    'api_base' => env('AI_FINOPS_ADMIN_API_BASE', '/api/admin/ai-finops'),

    'app_name' => env('AI_FINOPS_ADMIN_APP_NAME', 'AskMyDocs · AI FinOps'),

    'logout_url' => env('AI_FINOPS_ADMIN_LOGOUT_URL', '/logout'),
];
