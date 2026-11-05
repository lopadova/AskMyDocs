<?php

declare(strict_types=1);

namespace App\Mcp\Audit;

use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;

final class McpConnectorAuditRecorder
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $tool
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     */
    public function record(
        User $user,
        array $tool,
        array $arguments,
        array $context,
        string $status,
        mixed $payload,
        int $durationMs,
        ?\Throwable $exception = null,
    ): void {
        $provenance = is_array($tool['provenance'] ?? null) ? $tool['provenance'] : [];
        $artifactProvenance = data_get($payload, 'artifact.provenance');
        if (is_array($artifactProvenance)) {
            $provenance = array_replace($provenance, $artifactProvenance);
        }
        $localName = $this->string($provenance['tool_local_name'] ?? $tool['name'] ?? null);
        $remoteName = $this->string($provenance['tool_remote_name'] ?? null);

        McpToolCallAudit::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'user_id' => $user->getKey(),
            'actor' => 'user:'.$user->getKey(),
            'source' => 'mcp_connector',
            'mcp_server_id' => null,
            'mcp_server_name' => $this->string($provenance['server_name'] ?? null),
            'mcp_connection_id' => $this->string($provenance['connection_id'] ?? null),
            'invocation_id' => $this->string($provenance['invocation_id'] ?? null),
            'conversation_id' => $this->numericId($context['conversation_id'] ?? null),
            'message_id' => $this->numericId($context['message_id'] ?? null),
            'tool_name' => $localName ?? $remoteName ?? 'unknown',
            'tool_remote_name' => $remoteName,
            'tool_local_name' => $localName,
            'input_hash' => McpToolCallAudit::canonicalHash($arguments),
            'input_json_redacted' => [],
            'result_hash' => $exception === null ? $this->resultHash($payload) : null,
            'duration_ms' => max($durationMs, 0),
            'status' => $status,
            'error_class' => $exception !== null
                ? get_debug_type($exception)
                : ($status === 'error' ? 'McpRemoteToolError' : null),
            'error_json' => $exception === null ? null : ['message' => 'MCP connector invocation failed.'],
        ]);
    }

    private function resultHash(mixed $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return hash('sha256', $encoded === false ? '__unencodable_result__' : $encoded);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function numericId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
