<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;

/**
 * v5.0/W1 — tool invocation orchestration.
 *
 * The public surface is intentionally small in W1: invoke a tool and
 * persist an audit row. Follow-up W5 adds provider-specific tool-schema
 * wiring and stricter redaction.
 */
final class ToolInvoker
{
    public function __construct(
        private readonly McpClientBridge $bridge,
    ) {}

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
            $result = $this->bridge->invokeTool([
                'server_id' => $server->id,
                'server_name' => $server->name,
                'tool_name' => $toolName,
                'input' => $toolInput,
            ]);
        } catch (ConnectionException $exception) {
            $status = McpToolCallAudit::STATUS_TIMEOUT;
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
}
