<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\User;
use App\Support\TenantContext;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService as PackageToolCallingService;
use Padosoft\AskMyDocsMcpPack\Support\HostMessage;

/**
 * v7.0/W1.B — host-side wrapper around the package's
 * {@see PackageToolCallingService}.
 *
 * Preserves the signature `App\Mcp\Client\McpToolCallingService` had
 * since v5.0 (systemPrompt + messages + options + user + context →
 * {@see AiResponse}) so the existing controllers don't change their
 * shape. Internally the call is dispatched through the package
 * orchestrator and the response is mapped back to `AiResponse`.
 *
 * The wrapper is intentionally THIN — every piece of orchestration
 * logic (multi-turn loop, tool-call audit, RBAC, kill-switch) lives in
 * the package now. Anything that looks like business logic here is a
 * bug.
 */
final class HostToolCallingService
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly PackageToolCallingService $packageService,
        private readonly McpHostBridgeContract $hostBridge,
        private readonly McpServerRegistryContract $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    public function canHandleToolCalling(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if (! (bool) config('mcp-pack.tool_calling.enabled', false)) {
            return false;
        }
        if (! $this->hostBridge->supportsToolCalling()) {
            return false;
        }
        // Avoid wasting an LLM round-trip when no tools are visible to
        // the current tenant. The registry returns enabled servers
        // only; an empty list means the catalog will collapse before
        // the orchestrator reaches the model anyway.
        return $this->registry->forTenant($this->tenantContext->current()) !== [];
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @param  array<string,mixed>                      $options
     * @param  array<string,mixed>                      $context
     */
    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $options = [],
        ?User $user = null,
        array $context = [],
    ): AiResponse {
        // When the orchestrator path is not available (provider
        // tool-incapable, no servers, kill-switch off, no user), keep
        // the legacy `AiManager::chatWithHistory()` behaviour exactly.
        if (! $this->canHandleToolCalling($user)) {
            return $this->ai->chatWithHistory($systemPrompt, $messages, $options);
        }

        $packageMessages = array_merge(
            [HostMessage::system($systemPrompt)],
            $messages,
        );

        $packageResponse = $this->packageService->chatWithTools(
            messages: $packageMessages,
            tenantId: $this->tenantContext->current(),
            actor: $user instanceof User ? (string) $user->id : null,
            extras: $options,
            context: $context,
        );

        $providerName = $packageResponse->provider ?? $this->ai->provider()->name();
        $modelName = $packageResponse->model ?? '';
        $usage = $packageResponse->usage ?? [];

        return new AiResponse(
            content: $packageResponse->content ?? '',
            provider: (string) $providerName,
            model: (string) $modelName,
            promptTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            completionTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            totalTokens: isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            finishReason: $packageResponse->finishReason,
            toolCalls: $packageResponse->toolCalls,
        );
    }
}
