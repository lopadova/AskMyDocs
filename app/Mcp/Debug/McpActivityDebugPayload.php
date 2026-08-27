<?php

declare(strict_types=1);

namespace App\Mcp\Debug;

use App\Agent\Tools\AgentToolDefinition;
use App\Services\Widget\WidgetPiiMasker;

/** Builds the local/stage-only MCP request/response payload shown in Activity. */
final readonly class McpActivityDebugPayload
{
    private const ENABLED_ENVIRONMENTS = ['local', 'stage', 'staging'];

    private const REDACTED = '[REDACTED]';

    public function __construct(private WidgetPiiMasker $masker) {}

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
        return $this->maskRecursive($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function maskRecursive(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            $name = (string) $key;
            if ($this->isSensitiveKey($name)) {
                $masked[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->maskRecursive($value);
            } elseif (is_string($value)) {
                $masked[$key] = $this->masker->maskString($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $normalized = strtolower(str_replace(['-', ' '], '_', $snakeCase));

        return preg_match(
            '/(?:^|_)(?:authorization|password|passwd|secret|token|api_key|apikey|cookie|credential|private_key|client_secret|access_token|refresh_token|bearer)(?:$|_)/',
            $normalized,
        ) === 1;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
