<?php

declare(strict_types=1);

namespace App\Mcp\Debug;

use App\Agent\Tools\AgentToolDefinition;
use App\Support\SensitivePayloadRedactor;

/** Builds the local/stage-only MCP request/response payload shown in Activity. */
final readonly class McpActivityDebugPayload
{
    private const ENABLED_ENVIRONMENTS = ['local', 'stage', 'staging'];

    private const MAX_BYTES = 65_536;

    private const MAX_DEPTH = 8;

    private const MAX_ITEMS = 100;

    private const MAX_STRING_BYTES = 8_192;

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
        $redacted = $this->redactor->redact($payload);
        $encoded = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $originalBytes = is_string($encoded) ? strlen($encoded) : 0;
        // Keep structural JSON overhead outside the value budget so the
        // persisted event remains below the hard envelope in pathological
        // deeply nested payloads as well.
        $remaining = self::MAX_BYTES - 16_384;
        $truncated = false;
        $bounded = $this->bounded($redacted, $remaining, 0, $truncated);
        $bounded = is_array($bounded) ? $bounded : [];
        $bounded['_debug_meta'] = [
            'truncated' => $truncated || $originalBytes > self::MAX_BYTES,
            'original_bytes' => $originalBytes,
            'max_bytes' => self::MAX_BYTES,
        ];

        $final = json_encode($bounded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($final) || strlen($final) > self::MAX_BYTES) {
            return [
                'summary' => '[TRUNCATED: debug payload exceeded the hard envelope]',
                '_debug_meta' => [
                    'truncated' => true,
                    'original_bytes' => $originalBytes,
                    'max_bytes' => self::MAX_BYTES,
                ],
            ];
        }

        return $bounded;
    }

    private function bounded(mixed $value, int &$remaining, int $depth, bool &$truncated): mixed
    {
        if ($remaining <= 0) {
            $truncated = true;

            return '[TRUNCATED: byte limit]';
        }
        if (is_string($value)) {
            $limit = min(self::MAX_STRING_BYTES, max(0, $remaining));
            if (strlen($value) > $limit) {
                $truncated = true;
                $value = substr($value, 0, max(0, $limit - 24)).'… [truncated]';
            }
            $remaining -= strlen($value);

            return $value;
        }
        if (! is_array($value)) {
            $remaining -= 16;

            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            $truncated = true;
            $remaining -= 28;

            return '[TRUNCATED: max depth]';
        }

        $bounded = [];
        foreach ($value as $key => $nested) {
            if (count($bounded) >= self::MAX_ITEMS || $remaining <= 0) {
                $truncated = true;
                $bounded['_truncated_items'] = max(0, count($value) - count($bounded));
                break;
            }
            $safeKey = $this->boundedKey($key, $truncated);
            $remaining -= is_string($safeKey) ? strlen($safeKey) : 8;
            $bounded[$safeKey] = $this->bounded($nested, $remaining, $depth + 1, $truncated);
        }

        return $bounded;
    }

    private function boundedKey(int|string $key, bool &$truncated): int|string
    {
        if (! is_string($key) || strlen($key) <= 200) {
            return $key;
        }
        $truncated = true;

        return substr($key, 0, 160).'…'.substr(hash('sha256', $key), 0, 16);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
