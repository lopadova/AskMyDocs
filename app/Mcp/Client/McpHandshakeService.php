<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Mcp\Adapters\McpServerAdapter;
use App\Models\McpServer;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

/**
 * v7.0/W6.3.B — handshake via the package's native MCP transports.
 *
 * v5.0/W1 routed `POST /handshake` through the Node sidecar; v7.0
 * retires the sidecar and drives the JSON-RPC `initialize` +
 * `tools/list` round trip directly via
 * {@see McpClient::forServer()}.
 *
 * The persisted shape on `mcp_servers.handshake_response_json` is
 * preserved verbatim so the admin SPA's `McpServer-details` view
 * and the inline `McpToolCallingService::extractToolsFromServer()`
 * keep reading the same `{capabilities, tools, ...}` payload they
 * used to. Hosts that previously distinguished "fresh" vs "cached"
 * via the sidecar's `cached: true` flag should switch to the
 * package's `McpHandshakeService::peek()` if that distinction
 * matters operationally.
 */
final class McpHandshakeService
{
    public function refresh(McpServer $server): array
    {
        $client = McpClient::forServer(new McpServerAdapter($server));

        // Time the full round-trip so the admin FE can display the
        // `Round-trip: N ms` indicator. Captured around BOTH calls so
        // a slow `tools/list` shows up; the inline implementation
        // measured the same way.
        $started = microtime(true);
        try {
            $initialize = $client->initialize();
            $tools = $client->listTools();
        } catch (McpTransportException $exception) {
            // Re-throw as a plain RuntimeException so the admin
            // controller's existing catch-block keeps producing the
            // same `502 Bad Gateway` response shape. The transport-
            // specific exception type is an implementation detail of
            // the package; the host's HTTP surface should not leak
            // it.
            throw new \RuntimeException(
                "MCP handshake failed for server {$server->id}: {$exception->getMessage()}",
                previous: $exception,
            );
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        // Flatten the MCP JSON-RPC `initialize` response (camelCase
        // per spec) into the snake_case top-level fields the admin
        // FE has read since v5.0 (`HandshakeStatus.tsx` →
        // `response.protocol_version`, `.server_info`, `.tools`,
        // `.duration_ms`). The legacy sidecar always emitted these
        // top-level keys; we restore the same surface so the admin
        // dashboard doesn't render `Protocol: unknown` after the
        // cutover.
        $response = [
            'status' => 'ok',
            'protocol_version' => isset($initialize['protocolVersion']) && is_string($initialize['protocolVersion'])
                ? $initialize['protocolVersion']
                : null,
            'server_info' => $this->normaliseServerInfo($initialize['serverInfo'] ?? null),
            'capabilities' => is_array($initialize['capabilities'] ?? null)
                ? $initialize['capabilities']
                : [],
            'tools' => $tools,
            'duration_ms' => $durationMs,
        ];

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

    /**
     * @param  mixed  $raw
     * @return array{name: ?string, version: ?string}
     */
    private function normaliseServerInfo(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['name' => null, 'version' => null];
        }
        return [
            'name' => isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : null,
            'version' => isset($raw['version']) && is_string($raw['version']) ? $raw['version'] : null,
        ];
    }
}
