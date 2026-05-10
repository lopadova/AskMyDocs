<?php

declare(strict_types=1);

/*
 * AskMyDocs — padosoft/laravel-pii-redactor-admin v1.0.2 integration config.
 *
 * Defaults shipped by the package are PRESERVED for backward compatibility.
 * AskMyDocs-specific tweaks:
 *
 *   - Master switch defaults to false (disabled). Operators flip
 *     PII_REDACTOR_ADMIN_ENABLED=true once they've granted the
 *     `pii.detokenize` permission to the right roles.
 *   - Default mount path moved under /admin/pii-redactor so the SPA
 *     console sits next to the rest of the AskMyDocs admin surface
 *     instead of on a sibling top-level path.
 *   - Default middleware stack adds `auth` and the per-ability `can:`
 *     gate so a user who reaches
 *     the route without the `viewPiiRedactorAdmin` Gate is rejected
 *     at the HTTP layer, not deeper in the controller. ResolveTenant
 *     runs unconditionally (prepended globally in bootstrap/app.php),
 *     so we don't need to list it here.
 *
 * The 3 ability names map 1:1 to the 3 Gates wired in
 * AppServiceProvider::registerPiiRedactorAdminGates() — see that
 * method for the Spatie-role check behind each ability.
 */
return [
    'enabled' => env('PII_REDACTOR_ADMIN_ENABLED', false),

    'route_prefix' => env('PII_REDACTOR_ADMIN_ROUTE_PREFIX', 'admin/pii-redactor'),
    'api_prefix' => env('PII_REDACTOR_ADMIN_API_PREFIX', 'admin/pii-redactor/api'),

    /*
     * Middleware stack. The package gates ALL routes (web + API) with
     * this list. We require web (session) + auth (Sanctum-aware guard
     * resolution) + the view-level Gate, so:
     *
     *   - Unauthenticated requests → 302 to /login (web auth behaviour).
     *   - Authenticated-but-unauthorised requests → 403 from `can:`.
     *
     * The detokenise + raw-samples Gates are checked INSIDE the
     * package controllers (Authorize::ability() calls). The view Gate
     * here is the outer fence.
     */
    'middleware' => array_filter(array_map('trim', explode(
        ',',
        env('PII_REDACTOR_ADMIN_MIDDLEWARE', 'web,auth,can:viewPiiRedactorAdmin'),
    ))),

    'abilities' => [
        'view' => env('PII_REDACTOR_ADMIN_VIEW_ABILITY', 'viewPiiRedactorAdmin'),
        'detokenise' => env('PII_REDACTOR_ADMIN_DETOKENISE_ABILITY', 'detokenisePiiRedactor'),
        'raw_samples' => env('PII_REDACTOR_ADMIN_RAW_SAMPLES_ABILITY', 'viewPiiRedactorRawSamples'),
    ],

    'throttle' => [
        'scan' => env('PII_REDACTOR_ADMIN_SCAN_THROTTLE', '30,1'),
        'redact' => env('PII_REDACTOR_ADMIN_REDACT_THROTTLE', '30,1'),
        'detokenise' => env('PII_REDACTOR_ADMIN_DETOKENISE_THROTTLE', '6,1'),
    ],

    'token_maps' => [
        'per_page' => (int) env('PII_REDACTOR_ADMIN_TOKEN_MAPS_PER_PAGE', 25),
    ],
];
