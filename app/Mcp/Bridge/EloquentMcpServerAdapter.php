<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Models\McpServer;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * v7.0/W1.B — translation between AskMyDocs's Eloquent `mcp_servers`
 * row and the `padosoft/askmydocs-mcp-pack` server contract.
 *
 * Held by {@see EloquentMcpServerRegistry}; not constructed directly
 * by application code. The host stays the single source of truth for
 * server CRUD (admin SPA + Sanctum-protected API); this adapter only
 * re-exposes the underlying row in the package's contract shape.
 */
final class EloquentMcpServerAdapter implements McpServerContract
{
    public function __construct(public readonly McpServer $server) {}

    public function id(): string
    {
        return (string) $this->server->id;
    }

    public function name(): string
    {
        return (string) $this->server->name;
    }

    public function transport(): string
    {
        return (string) $this->server->transport;
    }

    public function tenantId(): ?string
    {
        $tenantId = $this->server->tenant_id;
        return $tenantId === null ? null : (string) $tenantId;
    }

    public function transportConfig(): array
    {
        // AskMyDocs's Node sidecar exposes REST endpoints
        // (`/handshake`, `/invoke-tool`) — NOT a JSON-RPC `/rpc` —
        // and authenticates via `Authorization: Bearer <token>`
        // (see mcp-client/src/auth.ts). The package's stock
        // HttpJsonRpcTransport would POST a JSON-RPC envelope at
        // `/rpc`, which the sidecar does not understand. The host
        // therefore binds {@see SidecarMcpTransport} via
        // McpClient::useTransportResolver() in AppServiceProvider,
        // and this method only carries operational metadata that
        // surfaces in the admin SPA + diagnostics.
        return [
            'base_url' => rtrim((string) config('mcp.sidecar.base_url', 'http://127.0.0.1:3535'), '/'),
            'timeout_ms' => (int) config('mcp.sidecar.timeout_ms', 5_000),
            'health_path' => (string) config('mcp.sidecar.health_endpoint', '/healthz'),
        ];
    }

    public function allowedTools(): array
    {
        $list = $this->server->enabled_tools_json;
        if (! is_array($list)) {
            return [];
        }
        // The host uses ['*'] to mean "all tools the server
        // advertises". The package contract uses [] for the same
        // semantics; translate.
        if ($list === ['*']) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }

    public function isEnabled(): bool
    {
        return $this->server->status === McpServer::STATUS_ACTIVE;
    }
}
