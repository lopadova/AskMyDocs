<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Evidence Risk Review — HOST overrides
|--------------------------------------------------------------------------
|
| Only the keys defined here override the package defaults
| (Padosoft\EvidenceRiskReview). The package SP's `mergeConfigFrom` does a
| shallow array_merge that only fills MISSING keys, so any top-level key the
| host declares here REPLACES the package version entirely — every other key
| (mcp, budget, tiers, tier_hints, profiles, default_profile) keeps the
| package default.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP API — SECURITY-CRITICAL middleware override (R32)
    |--------------------------------------------------------------------------
    |
    | The package ships `api.enabled => false` and `api.middleware => []` — i.e.
    | when an operator turns the API on, the review endpoints (submit / list /
    | show / profiles / taxonomy) would be reachable with NO authentication and
    | NO authorization. The host re-declares the API as an opt-in admin surface
    | and gates it with the same authenticated admin stack the rest of
    | /api/admin/* uses, so the review log (which can contain tenant-scoped
    | artifact text) is never exposed.
    |
    | DEFAULT-OFF (R43): `EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` defaults to false,
    | so a fresh deploy ships the admin surface (HTTP API + native FE) DORMANT —
    | the package routes are never registered, the FE cross-mount shows a clean
    | "unavailable" landing (never a 500), and the PHP/MCP surfaces the core
    | package registers stay available regardless. Set the flag to true to light
    | up the admin review-log dashboards. Both flag states are regression-locked
    | (tests/Feature/Evidence/EvidenceRiskReviewAdminFlagTest — OFF degrades to a
    | clean 404; the bulk suite + AdminAuthorizationMatrixTest run with it ON).
    |
    | Tenant scoping itself is enforced by the bound `TenantResolver`
    | (App\Providers\AppServiceProvider binds it to the host TenantContext), so
    | the read paths are forced to the active tenant (R30). `tenant.authorize`
    | establishes that tenant; `can:viewEvidenceRiskReview` is the read gate.
    |
    | Regression-locked by tests/Feature/Security/AdminAuthorizationMatrixTest
    | (R32).
    |
    */
    'api' => [
        'enabled' => env('EVIDENCE_RISK_REVIEW_ADMIN_ENABLED', false),
        'prefix' => env('EVIDENCE_RISK_REVIEW_API_PREFIX', 'api/admin/evidence-risk-review'),
        'middleware' => [
            // tenant.resolve runs first so TenantContext carries the active
            // tenant (from X-Tenant-Id / user / default) before the bound
            // TenantResolver stamps + scopes the review log (R30). In
            // production ResolveTenant is also globally prepended; declaring it
            // here makes the package group tenant-aware regardless of the
            // global stack and mirrors prod under Testbench.
            'tenant.resolve',
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
            'can:viewEvidenceRiskReview',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Review log persistence
    |--------------------------------------------------------------------------
    |
    | `database` so the admin review-log list + detail have data to read. The
    | table (evidence_risk_review_logs) ships as a host migration carrying the
    | v1.1.0 tenant_id + max_verdict columns. Every persisted review is stamped
    | with the active tenant by the bound resolver (R30).
    |
    */
    'review_log' => [
        'store' => env('EVIDENCE_RISK_REVIEW_LOG_STORE', 'database'),
        'connection' => env('EVIDENCE_RISK_REVIEW_LOG_CONNECTION'),
        'table' => env('EVIDENCE_RISK_REVIEW_LOG_TABLE', 'evidence_risk_review_logs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM semantic review pass — default-OFF (R43)
    |--------------------------------------------------------------------------
    |
    | When enabled, the package calls the host-bound EvidenceReviewerLlmContract
    | (App\Evidence\AiManagerEvidenceReviewer over AiManager). Default-OFF: the
    | deterministic sweep runs with zero token cost until an operator opts in.
    |
    */
    'llm' => [
        'enabled' => env('EVIDENCE_RISK_REVIEW_LLM_ENABLED', false),
    ],
];
