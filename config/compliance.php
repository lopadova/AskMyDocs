<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Token-level explainability
    |--------------------------------------------------------------------------
    |
    | Records the chunk-to-answer-token mapping for every assistant turn into
    | the chat_log_provenance table so auditors can trace any answer span
    | back to the chunk that grounded it (AI Act Art. 14). Best-effort; the
    | chat path is hot, so failures are logged but never thrown.
    |
    */

    'token_explainability' => [
        'enabled' => (bool) env('COMPLIANCE_TOKEN_EXPLAINABILITY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bias monitor — baseline parity
    |--------------------------------------------------------------------------
    |
    | The RagRefusalQualityMetric flags any cohort whose 1 - refusal_rate
    | drops more than 0.05 below this baseline. Tuned to match AI Act Art. 10
    | drift-detection conventions.
    |
    */

    'bias' => [
        'baseline_parity' => (float) env('COMPLIANCE_BIAS_BASELINE_PARITY', 0.95),
        'cohort_dimension_default' => env('COMPLIANCE_BIAS_DEFAULT_DIMENSION', 'project'),
        'window_days_default' => (int) env('COMPLIANCE_BIAS_WINDOW_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | DSAR
    |--------------------------------------------------------------------------
    |
    | GDPR Art. 15 / 17 / 16 — 30-day SLA. Operators are notified
    | `warn_days` days before SLA breach.
    |
    */

    'dsar' => [
        'sla_days' => (int) env('COMPLIANCE_DSAR_SLA_DAYS', 30),
        'warn_days' => (int) env('COMPLIANCE_DSAR_WARN_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin cross-mount
    |--------------------------------------------------------------------------
    |
    | URL prefix where the padosoft/laravel-ai-act-compliance-admin SPA is
    | mounted. The FE BrowserRouter `basename` must match.
    |
    */

    'admin' => [
        'mount_prefix' => env('COMPLIANCE_ADMIN_MOUNT_PREFIX', '/admin/ai-act-compliance'),
    ],

];
