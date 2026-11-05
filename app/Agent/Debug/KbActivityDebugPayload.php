<?php

declare(strict_types=1);

namespace App\Agent\Debug;

use App\Agent\Tools\AgentToolDefinition;
use App\Mcp\Debug\McpActivityDebugPayload;

/**
 * Local/stage-only knowledge-search request/response payload shown in
 * Activity — the search_knowledge_base / list_knowledge_documents
 * counterpart of {@see McpActivityDebugPayload}, which gates on
 * `kind === 'mcp'` and so never captures anything for a 'knowledge' or
 * 'catalog' tool call. Composes McpActivityDebugPayload for the shared
 * enabled()/sanitize() gating and size-bounding/redaction logic rather
 * than duplicating it — same local/stage-only posture, same PII redaction,
 * same byte/depth envelope.
 */
final readonly class KbActivityDebugPayload
{
    private const KINDS = ['knowledge', 'catalog'];

    public function __construct(private McpActivityDebugPayload $shared) {}

    public function enabled(): bool
    {
        return $this->shared->enabled();
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
        if (! $this->enabled() || ! in_array($tool->kind, self::KINDS, true)) {
            return null;
        }

        return $this->shared->sanitize([
            'surface' => 'knowledge_base',
            'tool_name' => $tool->name,
            'tool_display_name' => $tool->displayName,
            'status' => $status,
            'duration_ms' => max(0, $durationMs),
            'query' => is_string($arguments['query'] ?? null) ? $arguments['query'] : null,
            'response' => $response,
            'error' => $exception === null ? null : [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ]);
    }
}
