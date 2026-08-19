<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;

return [
    'enabled' => (bool) env('MCP_CONNECTOR_ENABLED', false),
    'legacy_adapter_enabled' => (bool) env('MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED', false),

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
];
