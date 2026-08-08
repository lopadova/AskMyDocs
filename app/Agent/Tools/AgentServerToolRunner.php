<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AgentExecutionContext;
use App\Agent\Budget\AgentBudgetTracker;
use App\Mcp\Client\McpToolAuthorizer;
use App\Mcp\Client\ToolInvoker;
use App\Models\AgentRun;
use App\Models\McpServer;
use App\Models\User;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;

/** Executes server-side API and MCP tools after a final scope revalidation. */
final readonly class AgentServerToolRunner
{
    public function __construct(
        private ApiToolCollector $api,
        private ToolInvoker $mcp,
        private McpToolAuthorizer $mcpAuthorizer,
    ) {}

    /**
     * @param array<string,mixed> $arguments
     * @param null|callable(array<string,mixed>):void $progress
     */
    public function execute(
        AgentToolDefinition $tool,
        array $arguments,
        AgentExecutionContext $context,
        AgentRun $run,
        AgentBudgetTracker $budget,
        ?callable $progress = null,
    ): AgentToolActionResult {
        return match ($tool->kind) {
            'api' => $this->executeApi($tool, $arguments, $context, $budget, $progress),
            'mcp' => $this->executeMcp($tool, $arguments, $context, $run, $budget),
            'client' => new AgentToolActionResult(
                ['error' => 'client_tool_requires_handoff'],
                0,
                false,
                'client_tool_requires_handoff',
            ),
            default => throw new \DomainException("unsupported_server_tool:{$tool->kind}"),
        };
    }

    /** @param array<string,mixed> $arguments @param null|callable(array<string,mixed>):void $progress */
    private function executeApi(
        AgentToolDefinition $tool,
        array $arguments,
        AgentExecutionContext $context,
        AgentBudgetTracker $budget,
        ?callable $progress,
    ): AgentToolActionResult {
        $scopes = $context->projectKey === null || $context->projectKey === ''
            ? ['']
            : ['', $context->projectKey];
        $route = ApiRoute::query()
            ->forTenant($context->tenantId)
            ->whereKey((int) $tool->executorReference)
            ->whereIn('project_key', $scopes)
            ->where('status', RouteStatus::Active->value)
            ->whereIn('mode', [RouteMode::Tool->value, RouteMode::Both->value])
            ->whereHas('connector', fn ($query) => $query->where('is_active', true))
            ->with('parameters')
            ->first();
        if (! $route instanceof ApiRoute) {
            throw new \DomainException('api_tool_scope_mismatch');
        }

        return AgentToolActionResult::fromApi(
            $this->api->collect($route, $arguments, $context, $budget, $progress),
        );
    }

    /** @param array<string,mixed> $arguments */
    private function executeMcp(
        AgentToolDefinition $tool,
        array $arguments,
        AgentExecutionContext $context,
        AgentRun $run,
        AgentBudgetTracker $budget,
    ): AgentToolActionResult {
        $capacity = $budget->canIssuePhysical();
        if (! $capacity->allowed()) {
            return new AgentToolActionResult(['error' => $capacity->reason], 0, false, $capacity->reason);
        }

        $user = $run->user;
        $server = McpServer::query()
            ->where('tenant_id', $context->tenantId)
            ->whereKey((int) $tool->executorReference)
            ->where('status', McpServer::STATUS_ACTIVE)
            ->first();
        if (! $user instanceof User || ! $server instanceof McpServer
            || ! $this->mcpAuthorizer->canInvoke($user, $server, $tool->name)) {
            throw new \DomainException('mcp_tool_scope_mismatch');
        }

        try {
            $result = $this->mcp->invoke($user, $server, $tool->name, $arguments, [
                'conversation_id' => $run->conversation_id,
                'locale' => $context->locale,
                'agent_run_id' => $run->id,
            ]);
            $budget->recordResult(1, $this->bytes($result), true);

            return new AgentToolActionResult($result, 1);
        } catch (\Throwable $exception) {
            $body = ['error' => $exception->getMessage()];
            $budget->recordResult(1, $this->bytes($body), false);

            return new AgentToolActionResult($body, 1, false, 'mcp_tool_error');
        }
    }

    /** @param array<string,mixed> $body */
    private function bytes(array $body): int
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? strlen($json) : 0;
    }
}
