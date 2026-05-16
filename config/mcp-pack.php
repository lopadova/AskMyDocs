<?php

declare(strict_types=1);

/**
 * v7.0/W6.3 — host overrides for `padosoft/askmydocs-mcp-pack`.
 *
 * The package ships its own defaults in `vendor/padosoft/.../config/
 * mcp-pack.php`; this file is published into the host so we can
 * point the package at host classes (`audit_model`) and host env
 * vars (`AI_AGENTIC_ENABLED`, …) without forking the package.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Multi-turn tool-calling
    |--------------------------------------------------------------------------
    |
    | Keep the kill-switch tied to the host's existing
    | `AI_AGENTIC_ENABLED` env var so operators don't need to flip
    | two flags when staging the v7 cutover.
    */
    'tool_calling' => [
        'enabled' => env('AI_AGENTIC_ENABLED', false),
        // Cast to int — env() returns strings, and downstream consumers
        // pass these straight into `max()` / loop counters. Matches the
        // strict-int treatment in `config/mcp.php`.
        'max_iterations' => (int) env('MCP_PACK_TOOL_CALLING_MAX_ITERATIONS', 3),
        'default_tool_choice' => env('MCP_PACK_TOOL_CHOICE', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Handshake cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'handshake' => [
        'ttl_seconds' => (int) env('MCP_PACK_HANDSHAKE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit model — point the package at the host's coexistence model
    |--------------------------------------------------------------------------
    |
    | Set in v7.0/W6.2: the host's `App\Models\McpToolCallAudit`
    | populates BOTH the package's hash columns (`input_hash` +
    | `actor`) AND the host's richer operator-forensics columns
    | (`input_json_redacted`, `user_id`, `error_json`). The package
    | writer goes through this class so the orchestrator's audit
    | row preserves both shapes.
    */
    'audit_model' => env(
        'MCP_PACK_AUDIT_MODEL',
        \App\Models\McpToolCallAudit::class,
    ),

    /*
    |--------------------------------------------------------------------------
    | v1.2.0 — Server-side surface (DISABLED on this host)
    |--------------------------------------------------------------------------
    |
    | AskMyDocs uses the package as a CLIENT only — the host already
    | exposes its KB tools via `app/Mcp/Servers/KnowledgeBaseServer.php`
    | + `laravel/mcp` first-party. Keep the package's server-side
    | HTTP route disabled to avoid two endpoints competing.
    */
    'server_side' => [
        'http' => [
            'enabled' => env('MCP_PACK_SERVER_HTTP_ENABLED', false),
            'prefix' => env('MCP_PACK_SERVER_HTTP_PREFIX', 'mcp'),
            'middleware' => array_values(array_filter(
                array_map('trim', explode(',', (string) env('MCP_PACK_SERVER_HTTP_MIDDLEWARE', 'api'))),
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | v1.3.0 — Resilience (circuit breaker + adaptive retry)
    |--------------------------------------------------------------------------
    |
    | OPT-IN. Default OFF so v6.x consumers see no behavioural change
    | until they explicitly enable the layer. Tune the thresholds
    | once the host has live MCP traffic to baseline against.
    */
    'resilience' => [
        'circuit_breaker' => [
            'enabled' => env('MCP_PACK_CB_ENABLED', false),
            'failure_threshold' => (int) env('MCP_PACK_CB_FAILURE_THRESHOLD', 5),
            'recovery_seconds' => (int) env('MCP_PACK_CB_RECOVERY_SECONDS', 30),
        ],
        'retry' => [
            'enabled' => env('MCP_PACK_RETRY_ENABLED', false),
            'max_attempts' => (int) env('MCP_PACK_RETRY_MAX_ATTEMPTS', 3),
            'bucket_size' => (int) env('MCP_PACK_RETRY_BUCKET_SIZE', 20),
            'bucket_window_seconds' => (int) env('MCP_PACK_RETRY_BUCKET_WINDOW_SECONDS', 60),
            'base_backoff_ms' => (int) env('MCP_PACK_RETRY_BASE_BACKOFF_MS', 200),
            'max_backoff_ms' => (int) env('MCP_PACK_RETRY_MAX_BACKOFF_MS', 5000),
        ],
        'cache_store' => env('MCP_PACK_RESILIENCE_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | v1.4.0 — Admin REST backend (DISABLED on this host)
    |--------------------------------------------------------------------------
    |
    | AskMyDocs has its own admin API surface
    | (`app/Http/Controllers/Api/Admin/McpServersAdminController.php`,
    | etc.) backed by the host's `mcp_servers` + `mcp_tool_call_audit`
    | tables. The package's admin REST routes are kept disabled so
    | the host's existing routes stay authoritative; the package
    | routes can be flipped on later if AskMyDocs wants to start
    | consuming the standalone `padosoft/askmydocs-mcp-pack-admin`
    | SPA from this host.
    */
    'admin' => [
        'enabled' => env('MCP_PACK_ADMIN_ENABLED', false),
        'prefix' => env('MCP_PACK_ADMIN_PREFIX', 'api/admin/mcp-pack'),
        'middleware' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('MCP_PACK_ADMIN_MIDDLEWARE', 'api'))),
        )),
    ],

];
