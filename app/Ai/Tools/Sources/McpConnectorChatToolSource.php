<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sources;

use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Models\User;
use Padosoft\AskMyDocsConnectorMcp\Services\McpChatCatalogService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;

final readonly class McpConnectorChatToolSource implements ChatToolSourceContract
{
    public function __construct(
        private McpChatCatalogService $catalog,
        private McpToolExecutor $executor,
    ) {}

    public function key(): string
    {
        return 'mcp';
    }

    public function catalog(User $user, ?string $projectKey = null): array
    {
        return config('connector-mcp.enabled', false)
            ? $this->catalog->forActor($user, $projectKey)
            : [];
    }

    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
    {
        $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
        $conversationId = (string) ($context['conversation_id'] ?? '');
        $projectKey = is_string($context['project_key'] ?? null) ? $context['project_key'] : null;
        if ($conversationId === '') {
            throw new \InvalidArgumentException('MCP tool calls require a conversation id.');
        }
        $outcome = $this->executor->invoke($name, $arguments, $user, $conversationId, $projectKey);
        $payload = $outcome->toArray();
        $metadata = is_array(data_get($payload, 'artifact.provenance'))
            ? data_get($payload, 'artifact.provenance')
            : [];
        $metadata += [
            'source' => $this->key(),
            'pending_interaction_id' => $outcome->pendingInteractionId,
            'prompt' => $outcome->prompt,
        ];

        return new ChatToolInvocationResult(
            status: $outcome->status,
            payload: $payload,
            metadata: array_filter($metadata, static fn (mixed $value): bool => $value !== null),
            error: $outcome->status === 'error' ? 'MCP tool returned an error.' : null,
        );
    }
}
