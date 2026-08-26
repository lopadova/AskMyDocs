<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;

return [
    'enabled' => (bool) env('MCP_CONNECTOR_ENABLED', false),
    'runtime_mode' => env('MCP_CONNECTOR_RUNTIME_MODE', 'off'),
    'legacy_adapter_enabled' => (bool) env('MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED', false),

    'allowed_transports' => ['auto', 'streamable_http', 'legacy_sse', 'stdio_imported'],

    'http' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
        'max_redirects' => 3,
        'max_response_bytes' => 2_000_000,
        'max_catalog_pages' => 20,
        'internal_endpoint_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MCP_CONNECTOR_INTERNAL_ENDPOINT_ALLOWLIST', '')),
        ))),
    ],

    'ingest' => [
        'max_resources_per_sync' => (int) env('MCP_CONNECTOR_MAX_RESOURCES_PER_SYNC', 500),
        'max_resource_bytes' => (int) env('MCP_CONNECTOR_MAX_RESOURCE_BYTES', 10_000_000),
        'max_sync_bytes' => (int) env('MCP_CONNECTOR_MAX_SYNC_BYTES', 100_000_000),
    ],

    'routes' => [
        'middleware' => [
            'api',
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            'auth:sanctum',
            'tenant.authorize',
        ],
        'admin_ability' => 'manageConnectors',
    ],

    'oauth' => [
        'callback_path' => '/api/connectors/mcp/oauth/callback',
        'state_ttl_seconds' => (int) env('MCP_CONNECTOR_OAUTH_STATE_TTL', 600),
        'pkce' => true,
        'client_metadata_path' => '/.well-known/mcp-client.json',
        'client_metadata_url' => env('MCP_CONNECTOR_CLIENT_METADATA_URL'),
        'client_name' => env('APP_NAME', 'AskMyDocs'),
        'client_uri' => env('APP_URL'),
        'default_scopes' => [],
    ],

    'tool_policy' => [
        'default_enabled' => false,
        'confirmation_for_unknown_writes' => true,
    ],

    'pending_interaction_ttl_seconds' => 900,

    'tasks' => [
        'retention_seconds' => (int) env('MCP_CONNECTOR_TASK_RETENTION', 604_800),
        'default_poll_interval_ms' => (int) env('MCP_CONNECTOR_TASK_POLL_INTERVAL', 1000),
        'minimum_poll_interval_ms' => 250,
        'poll_lock_seconds' => 30,
    ],

    'apps' => [
        // Advanced host capabilities (ui/message, model context, downloads and
        // fullscreen) remain opt-in independently from the basic sandbox.
        'advanced_enabled' => (bool) env('MCP_CONNECTOR_APP_ADVANCED_ENABLED', false),
        // Must be a distinct, cookie-free origin pointing back to the static
        // `/mcp-apps/sandbox` route (for example `https://mcp-apps.example`).
        'sandbox_origin' => env('MCP_CONNECTOR_APP_SANDBOX_ORIGIN'),
        'sandbox_path' => env('MCP_CONNECTOR_APP_SANDBOX_PATH', '/mcp-apps/sandbox'),
        'host_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MCP_CONNECTOR_APP_HOST_ORIGINS', (string) env('APP_URL', ''))),
        ))),
        'allow_insecure_local' => (bool) env('MCP_CONNECTOR_APP_ALLOW_INSECURE_LOCAL', false),
        'allow_nested_frames' => (bool) env('MCP_CONNECTOR_APP_ALLOW_NESTED_FRAMES', false),
        'allowed_permissions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MCP_CONNECTOR_APP_PERMISSIONS', '')),
        ))),
        'accepted_mime_types' => ['text/html;profile=mcp-app', 'text/html+skybridge'],
        'max_html_bytes' => (int) env('MCP_CONNECTOR_APP_MAX_HTML_BYTES', 1_000_000),
        'max_tool_result_bytes' => (int) env('MCP_CONNECTOR_APP_MAX_RESULT_BYTES', 524_288),
        'max_model_context_bytes' => (int) env('MCP_CONNECTOR_APP_MAX_CONTEXT_BYTES', 65_536),
        'max_download_items' => (int) env('MCP_CONNECTOR_APP_MAX_DOWNLOAD_ITEMS', 5),
        'max_download_bytes' => (int) env('MCP_CONNECTOR_APP_MAX_DOWNLOAD_BYTES', 10_000_000),
        'retention_seconds' => (int) env('MCP_CONNECTOR_APP_RETENTION', 86_400),
    ],

    'llm_text_limit' => 24_000,
];
