<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Guardrails Admin SPA — HOST overrides (AskMyDocs)
|--------------------------------------------------------------------------
|
| Vendor defaults live in `vendor/padosoft/laravel-ai-guardrails-admin/
| config/ai-guardrails-admin.php`. The package serves a self-contained React
| SPA (its own prefix, its own prebuilt Vite assets) that is a pure HTTP
| consumer of the guardrails core API (wired behind admin RBAC in W2). Unlike
| finops-admin, the package mounts its catch-all route UNCONDITIONALLY on boot
| (it has no `enabled` flag), so AskMyDocs adds the default-OFF gate itself —
| exactly the flow-admin strategy (config/flow-admin.php):
|
|   - `enabled` (a HOST-ONLY extension, not in the vendor config) defaults to
|     FALSE. The cockpit stays dark on a fresh deploy until the operator flips
|     AI_GUARDRAILS_ADMIN_ENABLED=true AND publishes the assets
|     (`php artisan vendor:publish --tag=ai-guardrails-admin-assets --force`).
|   - The `guardrails-admin.enabled` middleware (App\Http\Middleware\
|     GuardrailsAdminEnabled, first in the stack) `abort(404)`s for every route
|     under the prefix while the switch is off — a clean 404, not a 500 (R43
|     OFF-state, correct semantic for a disabled subsystem).
|   - Mounted under /admin/ai-guardrails so it sits next to the rest of the
|     AskMyDocs admin surface (mirrors /admin/ai-finops).
|   - The package default middleware `['web','auth']` is replaced with
|     `guardrails-admin.enabled,web,auth,can:viewAiGuardrails`: the master
|     switch + session + the web auth guard (302 to /login for guests) + the
|     same view Gate the core API uses. Opening the panel needs
|     `viewAiGuardrails` (super-admin + admin); the WRITE actions hit the core
|     API which separately enforces `manageAiGuardrails` (super-admin) via the
|     method-aware guardrails.authorize middleware.
|   - `api_base` points at the host-relocated core prefix (api/admin/ai-guardrails).
|
*/

return [
    // HOST-ONLY master switch (not present in the vendor config). The
    // GuardrailsAdminEnabled middleware consults it; default-OFF.
    'enabled' => env('AI_GUARDRAILS_ADMIN_ENABLED', false),

    'mount_prefix' => env('AI_GUARDRAILS_ADMIN_PREFIX', 'admin/ai-guardrails'),

    'middleware' => [
        'guardrails-admin.enabled',
        'web',
        'auth',
        'can:viewAiGuardrails',
    ],

    // The SPA fetches the core guardrails API; point it at the host-relocated
    // prefix (W2 set the core api.prefix to api/admin/ai-guardrails).
    'api_base' => env('AI_GUARDRAILS_ADMIN_API_BASE', '/api/admin/ai-guardrails'),

    'theme_default' => env('AI_GUARDRAILS_ADMIN_THEME', 'dark'),

    'asset_path' => env('AI_GUARDRAILS_ADMIN_ASSET_PATH', 'vendor/ai-guardrails-admin'),
];
