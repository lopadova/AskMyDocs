<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI FinOps — HOST overrides (AskMyDocs)
|--------------------------------------------------------------------------
|
| Vendor defaults live in `vendor/padosoft/laravel-ai-finops/config/
| ai-finops.php`. `mergeConfigFrom` does a SHALLOW array_merge, so any
| top-level key declared here REPLACES the package's version of that key
| entirely — every key we DON'T declare (pricing, features, audit,
| alerts, footprint, storage, integrations, block_status, hook, …) keeps
| the package default untouched.
|
| We override only four areas: the master switches, the SECURITY-CRITICAL
| route stack, multi-tenant attribution, and currency. Everything else
| ships sane EU-friendly defaults from the package.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switches
    |--------------------------------------------------------------------------
    | Metering is ON by default: we WANT the usage ledger to start filling the
    | moment the package is deployed (the whole point of the AiManager hook —
    | see App\FinOps\AiCallMeter — is full cross-provider coverage). Enforcement
    | (hard budget/policy HTTP-402 blocks) defaults OFF: observe-first. Flip
    | AI_FINOPS_ENFORCEMENT=true only after budgets/policies are seeded.
    */
    'enabled' => env('AI_FINOPS_ENABLED', true),
    'metering' => env('AI_FINOPS_METERING', true),
    'enforcement' => env('AI_FINOPS_ENFORCEMENT', false),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy (R30/R31)
    |--------------------------------------------------------------------------
    | AskMyDocs is multi-tenant. The resolver returns the request-scoped
    | tenant id from App\Support\TenantContext so every ledger row, budget
    | and rollup is attributed to the active tenant. Class-string (not a
    | closure) so config:cache keeps working.
    */
    'tenancy' => [
        'enabled' => true,
        'resolver' => \App\FinOps\HostTenantResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | Base = USD because provider list prices (and config/ai.php cost_rates)
    | are quoted in USD per 1M tokens; budgets compare against spend in the
    | base currency. Display = EUR for the (Italian) operators.
    */
    'currency' => [
        'base' => env('AI_FINOPS_CURRENCY', 'USD'),
        'display' => env('AI_FINOPS_DISPLAY_CURRENCY', 'EUR'),
        'fx_provider' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes — SECURITY-CRITICAL middleware override (R32)
    |--------------------------------------------------------------------------
    | The package ships `routes.middleware => ['api']` + `auth_middleware =>
    | ['auth']` — i.e. NO Sanctum, NO tenant scoping, NO RBAC. Those routes
    | expose the spend ledger, budgets, policies, kill-switches, cost-centers,
    | approvals and the audit trail. Shipping the package default would leave
    | every one of those reachable with only Laravel's stock `auth` guard and
    | no tenant isolation.
    |
    | `middleware` wraps EVERY package route (incl. the public `health`
    | probe): the host admin stack — cookies + session (so the Sanctum
    | STATEFUL guard sees the SPA cookie) + `auth:sanctum` + `tenant.authorize`
    | (R30 tenant isolation). `auth_middleware` is applied ON TOP of the
    | privileged (non-health) endpoints: `finops.authorize` is the method-aware
    | gate — safe methods (GET/HEAD) require `viewAiFinOps` (super-admin +
    | admin), mutating methods (POST/PUT/PATCH/DELETE) require `manageAiFinOps`
    | (super-admin only). The package controllers do NO internal authorization,
    | so this middleware IS the authorization boundary.
    |
    | Regression-locked by tests/Feature/Security/AdminAuthorizationMatrixTest
    | (R32).
    */
    'routes' => [
        'enabled' => true,
        'prefix' => env('AI_FINOPS_ROUTE_PREFIX', 'api/admin/ai-finops'),
        'middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
        ],
        'auth_middleware' => [
            'finops.authorize',
        ],
    ],
];
