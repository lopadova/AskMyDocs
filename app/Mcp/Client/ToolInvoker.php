<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Mcp\Adapters\McpServerAdapter;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

/**
 * v7.0/W6.3.B — tool invocation via the package's native MCP
 * transports (HTTP / SSE / stdio). The v5.0/W1 incarnation routed
 * every call through a Node sidecar on `127.0.0.1:3535`; that
 * sidecar is retired in W6.3.B and the host now speaks JSON-RPC
 * directly through `padosoft/askmydocs-mcp-pack`'s
 * {@see McpClient::forServer()}.
 *
 * The audit-row writes stay verbatim — the schema, redaction, and
 * tenant scoping are unchanged. The only architectural shift is the
 * transport layer underneath: no extra process, no extra hop, one
 * fewer thing to monitor in production.
 */
final class ToolInvoker
{
    public function invoke(
        User $user,
        McpServer $server,
        string $toolName,
        array $toolInput,
        array $context = [],
    ): array {
        $start = microtime(true);
        $status = McpToolCallAudit::STATUS_OK;
        $result = [];
        $errorPayload = null;

        try {
            // Native transport via the package — `forServer()` reads
            // the adapter's `transportConfig()` to pick HTTP / SSE /
            // stdio, then `callTool()` does the `initialize` +
            // `tools/call` JSON-RPC round trip.
            $client = McpClient::forServer(new McpServerAdapter($server));
            $rawResult = $client->callTool($toolName, $toolInput);
            $result = is_array($rawResult) ? $rawResult : ['content' => $rawResult];
        } catch (McpTransportException $exception) {
            // Native transport failures classify into two buckets:
            //
            //   - real timeouts (cURL "Operation timed out", stdio
            //     read timeout, SSE keep-alive miss) → `timeout`
            //     keeps the legacy dashboard filter + alerting rules
            //     wired up for the same operator-visible failure
            //     class they triaged through the sidecar era.
            //   - everything else the transport layer surfaces
            //     (refused connection, malformed JSON-RPC envelope,
            //     unexpected upstream HTTP status, protocol error) →
            //     `transport_error`, the dedicated value the W6.3
            //     audit-schema widening added. Operators looking at
            //     a non-timeout transport failure now get a distinct
            //     pill in the admin audit view.
            $status = self::classifyTransportException($exception);
            $errorPayload = [
                'message' => 'MCP tool invocation failed.',
                'error' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            $status = McpToolCallAudit::STATUS_ERROR;
            $errorPayload = [
                'message' => 'MCP tool invocation failed.',
                'error' => $message,
            ];
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $hashInput = $status === McpToolCallAudit::STATUS_OK
            ? $result
            : $errorPayload;
        $hash = hash('sha256', json_encode($hashInput, JSON_UNESCAPED_UNICODE) ?: '');

        McpToolCallAudit::query()->create([
            'tenant_id' => $server->tenant_id,
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'conversation_id' => $context['conversation_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'tool_name' => $toolName,
            'input_json_redacted' => $toolInput,
            'result_hash' => $hash,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_json' => $errorPayload,
        ]);

        if ($status !== McpToolCallAudit::STATUS_OK) {
            throw new \RuntimeException($errorPayload['message'] ?? 'Tool invocation failed.');
        }

        return $result;
    }

    /**
     * Map an `McpTransportException` to the most accurate audit
     * status. Timeouts get the legacy `STATUS_TIMEOUT` so existing
     * dashboards keep firing; everything else gets the W6.3 widened
     * `STATUS_TRANSPORT_ERROR`. Pattern-match the message body case-
     * insensitively against the well-known timeout markers that cURL
     * / stream wrappers emit.
     */
    private static function classifyTransportException(McpTransportException $exception): string
    {
        $message = strtolower($exception->getMessage() ?? '');
        $timeoutMarkers = [
            'timed out',
            'operation timeout',
            'operation timed out',
            'connection timed out',
            'read timeout',
            'idle timeout',
        ];
        foreach ($timeoutMarkers as $marker) {
            if (str_contains($message, $marker)) {
                return McpToolCallAudit::STATUS_TIMEOUT;
            }
        }
        return McpToolCallAudit::STATUS_TRANSPORT_ERROR;
    }
}
