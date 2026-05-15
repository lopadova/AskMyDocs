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
        // AskMyDocs talks to upstream MCP servers exclusively via the
        // Node sidecar's HTTP front (see McpClientBridge in v5.0/v6.x).
        // We expose ONE config shape — http endpoint of the sidecar's
        // invoke-tool route — so the package's HttpJsonRpcTransport
        // can drive a JSON-RPC request through the same channel.
        $base = rtrim((string) config('mcp.sidecar.base_url', 'http://127.0.0.1:3535'), '/');
        $headers = [
            'X-MCP-Server-Id' => (string) $this->server->id,
            'X-MCP-Tenant' => (string) ($this->server->tenant_id ?? ''),
        ];

        return [
            'endpoint' => $base . '/rpc',
            'headers' => $headers,
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
