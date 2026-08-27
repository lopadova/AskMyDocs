<?php

declare(strict_types=1);

namespace App\Mcp\Debug;

use App\Agent\Tools\AgentToolDefinition;
use App\Support\SensitivePayloadRedactor;

/** Builds the local/stage-only MCP request/response payload shown in Activity. */
final readonly class McpActivityDebugPayload
{
    private const ENABLED_ENVIRONMENTS = ['local', 'stage', 'staging'];

    public function __construct(private SensitivePayloadRedactor $redactor) {}

    public function enabled(): bool
    {
        return in_array((string) config('app.env'), self::ENABLED_ENVIRONMENTS, true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    public function capture(
        AgentToolDefinition $tool,
        array $arguments,
        ?array $response,
        int $durationMs,
        string $status,
        ?\Throwable $exception = null,
    ): ?array {
        if (! $this->enabled() || $tool->kind !== 'mcp') {
            return null;
        }

        $provenance = is_array($tool->metadata['provenance'] ?? null)
            ? $tool->metadata['provenance']
            : [];
        $runtime = is_string($tool->metadata['mcp_runtime'] ?? null)
            ? $tool->metadata['mcp_runtime']
            : 'unknown';

        return $this->sanitize([
            'protocol' => 'MCP',
            'method' => 'tools/call',
            'runtime' => $runtime,
            'server_name' => $this->string($provenance['server_name'] ?? null),
            'server_id' => $provenance['server_id'] ?? $tool->metadata['server_id'] ?? null,
            'connection_id' => $this->string($provenance['connection_id'] ?? null),
            'tool_local_name' => $this->string($provenance['tool_local_name'] ?? null) ?? $tool->name,
            'tool_remote_name' => $this->string($provenance['tool_remote_name'] ?? null) ?? $tool->displayName,
            'status' => $status,
            'duration_ms' => max(0, $durationMs),
            'parameters' => $arguments,
            'response' => $response,
            'error' => $exception === null ? null : [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ]);
    }

    /**
     * Defence in depth for any caller that publishes an mcp_debug event directly.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->redactor->redact($payload);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
