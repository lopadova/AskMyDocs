<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Bridge\EloquentMcpServerAdapter;
use App\Models\McpServer;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

/**
 * v7.0/W1.B — handshake wrapper that drives the package's JSON-RPC
 * transport AND keeps the host's `mcp_servers` row metadata in sync
 * (status, last_handshake_at, handshake_response_json).
 *
 * Replaces the v5.0 inline `App\Mcp\Client\McpHandshakeService` that
 * was tightly coupled to the deleted `McpClientBridge`. The package's
 * own `McpHandshakeService` is a transport-level cache wrapper — it
 * does NOT persist a DB row, by design (per-tenant Eloquent persistence
 * is host-specific). This thin wrapper bridges both worlds.
 */
final class HostHandshakeService
{
    /**
     * @return array{capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>}
     */
    public function refresh(McpServer $server): array
    {
        $client = McpClient::forServer(new EloquentMcpServerAdapter($server));

        $capabilities = $client->initialize();
        $tools = $client->listTools();

        $payload = [
            'capabilities' => $capabilities,
            'tools' => $tools,
        ];

        $saved = $server->forceFill([
            'status' => McpServer::STATUS_ACTIVE,
            'last_handshake_at' => now(),
            'handshake_response_json' => $payload,
        ])->save();

        if (! $saved) {
            throw new \RuntimeException("Failed to persist handshake result for MCP server {$server->id}.");
        }

        return $payload;
    }
}
