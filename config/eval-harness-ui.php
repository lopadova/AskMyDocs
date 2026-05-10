<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| padosoft/eval-harness-ui — host-app config (AskMyDocs)
|--------------------------------------------------------------------------
| Vendor defaults are in `vendor/padosoft/eval-harness-ui/config/
| eval-harness-ui.php`. AskMyDocs overrides the `route_middleware`
| array so the package routes always carry, in order:
|
|   web                            — session + CSRF
|   auth                           — must be a logged-in user
|   eval-harness-ui.non-prod       — host-app production guard (R14:
|                                    abort 404 when APP_ENV=production)
|   eval-harness-ui.tenant-header  — injects X-Eval-Harness-Tenant from
|                                    the request-scoped TenantContext
|                                    (R30 — every API call carries the
|                                    active tenant)
|   can:eval-harness.viewer        — Gate-backed RBAC (single read-only
|                                    Gate; admin / dpo / super-admin /
|                                    editor allowed; viewer denied)
|
| The package controller already 404s when `enabled=false` (see
| `Padosoft\EvalHarnessUi\Http\Controllers\EvalHarnessUiController`).
| The AskMyDocs `EvalHarnessUiNonProduction` middleware is the second
| fence: route MUST 404 when `APP_ENV=production` regardless of the
| `EVAL_HARNESS_UI_ENABLED` value, so the eval dashboard is never
| exposed in prod by accident.
|
| The middleware aliases `eval-harness-ui.non-prod` and
| `eval-harness-ui.tenant-header` are registered by
| `App\Providers\EvalHarnessUiIntegrationServiceProvider`.
*/

return [
    'enabled' => env('EVAL_HARNESS_UI_ENABLED', false),

    'prefix' => env('EVAL_HARNESS_UI_PREFIX', 'admin/eval-harness'),

    'route_middleware' => [
        'web',
        'auth',
        'eval-harness-ui.non-prod',
        'eval-harness-ui.tenant-header',
        'can:eval-harness.viewer',
    ],

    'api_base' => env('EVAL_HARNESS_API_BASE', '/admin/eval-harness/api'),

    'tenant_header' => env('EVAL_HARNESS_TENANT_HEADER', 'X-Eval-Harness-Tenant'),

    'locale' => env('EVAL_HARNESS_UI_LOCALE', env('APP_LOCALE', 'en')),

    'schema_version' => [
        'required' => true,
        'min_supported' => '1.0',
    ],

    'metric_labels' => [
        'exact-match.mean' => 'Exact match',
        'llm-judge.pass_rate' => 'Judge pass rate',
        'macro_f1' => 'Macro F1',
    ],

    'polling' => [
        'live_batches_seconds' => 3,
        'report_list_seconds' => 30,
        'trend_seconds' => 300,
    ],

    'assets' => [
        'command_palette_shortcut' => 'mod+k',
    ],
];
