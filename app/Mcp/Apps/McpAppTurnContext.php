<?php

declare(strict_types=1);

namespace App\Mcp\Apps;

use App\Mcp\Runtime\McpRuntimeGate;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsConnectorMcp\Services\McpAppInstanceService;

final readonly class McpAppTurnContext
{
    public function __construct(
        private McpAppInstanceService $apps,
        private McpRuntimeGate $runtime,
    ) {}

    public function resolve(?string $appId, Model $actor, Conversation $conversation): ?string
    {
        if (
            ! is_string($appId)
            || $appId === ''
            || ! $this->runtime->usesConnector()
            || ! $this->apps->advancedEnabled()
        ) {
            return null;
        }

        $instance = $this->apps->authorized($appId, $actor, (string) $conversation->getKey());

        return $this->apps->promptContext($instance);
    }
}
