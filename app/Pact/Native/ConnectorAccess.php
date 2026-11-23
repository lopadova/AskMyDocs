<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Agent\AgentExecutionContext;
use App\ApiTools\ToolAccess;
use Illuminate\Database\Eloquent\Builder;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;

final class ConnectorAccess
{
    /** @return list<string> */
    public function mcpTools(AgentExecutionContext $context): array
    {
        return $this->eligibleMcpTools($context)->pluck('local_name')->all();
    }

    public function assertMcp(AgentExecutionContext $context, string $name): void
    {
        app(ToolAccess::class)->assertActor($context);
        if (! $this->eligibleMcpTools($context)->where('local_name', $name)->exists()) {
            throw new \DomainException('pact_connector_read_required');
        }
    }

    private function eligibleMcpTools(AgentExecutionContext $context): Builder
    {
        return McpConnectionTool::query()->forTenant($context->tenantId)
            ->where('enabled', true)->whereNull('removed_at')
            ->where('risk', 'read')->where('read_only', true)->where('idempotent', true)
            ->where('destructive', false)->where('confirmation_required', false);
    }
}
