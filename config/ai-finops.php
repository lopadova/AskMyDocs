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
| entirely — every key we DON'T declare (features, audit,
| alerts, footprint, storage, integrations, block_status, hook, …) keeps
| the package default untouched.
|
| We override five areas: the master switches, the SECURITY-CRITICAL
| route stack, multi-tenant attribution, currency, and pricing. Everything else
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

    // Full pricing subtree: mergeConfigFrom replaces this top-level array.
    'pricing' => [
        'overrides_win' => true, // local Padosoft DB entries (manual) override feeds

        // Enabled sources, in default-precedence order (first = highest).
        'sources' => ['manual', 'litellm', 'openrouter'],

        // Tie / unknown-freshness winner order.
        'default_winner' => ['manual', 'litellm', 'openrouter'],

        'litellm' => [
            'enabled' => env('AI_FINOPS_PRICING_LITELLM', true),
            'url' => env('AI_FINOPS_PRICING_LITELLM_URL', 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'),
        ],

        // OpenRouter live models API. The public listing needs no key; a key
        // (never serialized) raises rate limits and unlocks per-endpoint prices
        // (Phase 2). OpenRouter passes provider list prices through WITHOUT an
        // inference markup; its ~5.5% credit top-up fee is modeled in `fees`.
        'openrouter' => [
            'enabled' => env('AI_FINOPS_PRICING_OPENROUTER', true),
            'url' => env('AI_FINOPS_PRICING_OPENROUTER_URL', 'https://openrouter.ai/api/v1/models'),
            'embeddings_url' => env('AI_FINOPS_PRICING_OPENROUTER_EMBEDDINGS_URL', 'https://openrouter.ai/api/v1/embeddings/models'),
            'key' => env('AI_FINOPS_PRICING_OPENROUTER_KEY'), // optional; exposed only as has_* boolean
            'allow_keyless' => true, // the public model list works without a key
            'use_endpoints' => false, // Phase 2: per-provider endpoint prices (3–10× spread)
        ],

        'sync_cron' => env('AI_FINOPS_PRICING_SYNC_CRON', '0 4 * * *'),

        // Which source is authoritative per provider (the "who bills you" rule).
        // Unmapped providers fall through to freshest-sync / default_winner.
        'provider_source_map' => [
            'openrouter' => 'openrouter',
            'regolo' => 'manual',
            // openai/anthropic/azure/bedrock/vertex/fal_ai/… default to litellm
        ],

        // Per-provider account-level overhead applied to ESTIMATES only (what-if,
        // forecast, preflight) — never to the raw metered ledger. Percent.
        'fees' => [
            // 'openrouter' => ['markup_pct' => 5.5],
        ],

        // Recover the provider's ACTUAL billed cost that laravel/ai drops during
        // normalization (it keeps tokens only). When enabled, a global Http response
        // middleware captures usage.cost from responses whose body matches the
        // OpenRouter shape (usage.cost present) — Laravel's Http middleware does not
        // expose the request URL, so body-shape matching is used instead of host
        // filtering. The `hosts` key is reserved for documentation / future use when
        // Laravel adds request context to response middleware.
        'actual_cost' => [
            'enabled' => env('AI_FINOPS_ACTUAL_COST', true),
            'hosts' => ['openrouter.ai'], // informational — body-shape matching is active
            'store_raw' => false, // also stash the captured usage/cost block in metadata
            'openrouter' => [
                'generation_lookup' => false,   // confirm via GET /generation?id= (+1 HTTP)
                'credit_to_currency' => 1.0,    // OpenRouter credits → base currency
            ],
        ],

        // Cost cascade fallback: when a response carries neither cost nor token usage,
        // estimate tokens then apply the tariff (flagged tokens_estimated). Heuristic by
        // default; auto-upgrades to exact counts if the optional yethee/tiktoken is installed.
        'token_estimation' => [
            'enabled' => true,
            'expected_output_ratio' => 1.0, // preflight: assume output ≈ input × ratio
        ],

        'discounts' => [
            'prompt_cache' => true,
            'batch_api' => true,
            'committed_use' => true,
        ],
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
    | `middleware` wraps EVERY package route — INCLUDING the package's
    | `health` probe. The package defaults `health` to no auth, but here it is
    | NOT publicly reachable: our stack puts it behind the full host admin
    | stack — cookies + session (so the Sanctum STATEFUL guard sees the SPA
    | cookie) + `auth:sanctum` + `tenant.authorize` (R30 tenant isolation). So
    | `health` requires a valid authenticated tenant session like every other
    | route; it is NOT an anonymous uptime probe. `auth_middleware` is applied
    | ON TOP, on the privileged (non-health) endpoints only: `finops.authorize`
    | is the method-aware gate — safe methods (GET/HEAD) require `viewAiFinOps`
    | (super-admin + admin), mutating methods (POST/PUT/PATCH/DELETE) require
    | `manageAiFinOps` (super-admin only). The package controllers do NO
    | internal authorization, so this middleware IS the authorization boundary.
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
