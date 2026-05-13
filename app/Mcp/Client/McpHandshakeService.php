<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Models\McpServer;

/**
 * v5.0/W1 — handshake wrapper for MCP servers.
 */
final class McpHandshakeService
{
    public function __construct(
        private readonly McpClientBridge $bridge,
    ) {}

    public function refresh(McpServer $server): array
    {
        $payload = [
            'server_id' => $server->id,
            'name' => $server->name,
            'transport' => $server->transport,
            'endpoint' => $server->endpoint,
        ];

        $response = $this->bridge->handshake($payload);

        $saved = $server->forceFill([
            'status' => McpServer::STATUS_ACTIVE,
            'last_handshake_at' => now(),
            'handshake_response_json' => $response,
        ])->save();

        if (! $saved) {
            throw new \RuntimeException("Failed to persist handshake result for MCP server {$server->id}.");
        }

        return $response;
    }
}
