<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;

return [
    'enabled' => (bool) env('MCP_CONNECTOR_ENABLED', false),
    'runtime_mode' => env('MCP_CONNECTOR_RUNTIME_MODE', 'off'),
    'legacy_adapter_enabled' => (bool) env('MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED', false),

    'http' => [
        'internal_endpoint_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MCP_CONNECTOR_INTERNAL_ENDPOINT_ALLOWLIST', '')),
        ))),
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
        'client_metadata_path' => '/.well-known/mcp-client.json',
        'client_metadata_url' => env('MCP_CONNECTOR_CLIENT_METADATA_URL'),
        'client_name' => env('APP_NAME', 'AskMyDocs'),
        'client_uri' => env('APP_URL'),
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
    ],
];
