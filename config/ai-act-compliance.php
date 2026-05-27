<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Act Compliance — HOST overrides
|--------------------------------------------------------------------------
|
| Only the keys defined here override the package defaults
| (Padosoft\AiActCompliance). `mergeConfigFrom` does a shallow array_merge,
| so any top-level key we declare REPLACES the package's version of that key
| entirely — every other key (enabled, alerting, bias, consent, dsar, …)
| keeps the package default.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Routes — SECURITY-CRITICAL middleware override
    |--------------------------------------------------------------------------
    |
    | The package ships `routes.middleware => ['api']` — i.e. NO authentication
    | and NO authorization. Those routes expose DSAR (personal-data requests),
    | incidents, bias captures, the risk register, consent records, compliance
    | attestations and the per-tenant registry. Shipping them with the package
    | default leaves every one of those endpoints reachable UNAUTHENTICATED.
    |
    | The host MUST gate them with the same stack the rest of the admin API
    | uses, plus the package tenant-context bridge and the `viewAiActCompliance`
    | gate (super-admin / admin / dpo). Write endpoints are additionally
    | authorized inside the package controllers via `manageAiActCompliance`.
    |
    | Regression-locked by tests/Feature/Security/AdminAuthorizationMatrixTest
    | (R32).
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => env('AI_ACT_COMPLIANCE_ROUTE_PREFIX', 'api/admin/ai-act-compliance'),
        'middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
            'ai-act.tenant-context',
            'can:viewAiActCompliance',
        ],
    ],
];
