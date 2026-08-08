<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AgentExecutionContext;
use App\Mcp\Client\McpToolAuthorizer;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Models\McpServer;
use App\Models\User;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry as ConnectorToolRegistry;

final readonly class AgentToolRegistry
{
    public function __construct(
        private ConnectorToolRegistry $connectors,
        private McpServerRegistry $mcpServers,
        private McpToolAuthorizer $mcpAuthorizer,
    ) {}

    /**
     * @param list<AgentToolDefinition> $clientTools
     * @return array<string,AgentToolDefinition>
     */
    public function forContext(
        AgentExecutionContext $context,
        ?User $user = null,
        array $clientTools = [],
    ): array {
        $tools = ['search_knowledge_base' => $this->knowledgeTool()];
        $this->mergeMcpTools($tools, $user);
        $this->mergeApiTools($tools, $context);

        foreach ($clientTools as $tool) {
            if ($tool instanceof AgentToolDefinition && ! isset($tools[$tool->name])) {
                $tools[$tool->name] = $tool;
            }
        }

        return $tools;
    }

    private function knowledgeTool(): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: 'search_knowledge_base',
            displayName: 'Knowledge base',
            description: 'Search tenant- and project-scoped indexed documents for evidence relevant to the user question.',
            kind: 'knowledge',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'A focused semantic search query.'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 0,
            physicalLikely: 0,
            physicalMaximum: 0,
            executorReference: 'knowledge',
        );
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function mergeApiTools(array &$tools, AgentExecutionContext $context): void
    {
        if (! (bool) config('connector-api.chat_tools.enabled', true)) {
            return;
        }

        $registered = $this->connectors->activeToolsForTenant($context->tenantId, $context->projectKey);
        $routeIds = array_values(array_filter(array_map(
            static fn (array $tool): int => (int) ($tool['route_id'] ?? 0),
            $registered,
        )));
        $routes = ApiRoute::query()
            ->forTenant($context->tenantId)
            ->whereIn('id', $routeIds)
            ->with(['listRelations.detailRoute'])
            ->get()
            ->keyBy('id');

        foreach ($registered as $registeredTool) {
            $route = $routes->get((int) ($registeredTool['route_id'] ?? 0));
            $definition = $registeredTool['definition'] ?? [];
            $name = is_array($definition) ? (string) ($definition['name'] ?? '') : '';
            if (! $route instanceof ApiRoute || $name === '' || isset($tools[$name])) {
                continue;
            }

            $method = strtoupper((string) $route->http_method->value);
            $readOnly = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $pagination = is_array($route->pagination) ? $route->pagination : null;
            $physicalMaximum = $pagination === null ? 1 : max(1, (int) config('agent.limits.physical_hard', 100));
            $outboundRelations = $route->listRelations->map(static fn ($relation): array => [
                'detail_route_id' => $relation->detail_route_id,
                'detail_tool' => $relation->detailRoute?->slug,
                'field_map' => $relation->field_map,
            ])->values()->all();

            $tools[$name] = new AgentToolDefinition(
                name: $name,
                displayName: (string) $route->name,
                description: (string) ($definition['description'] ?? $route->description ?? $route->name),
                kind: 'api',
                inputSchema: is_array($definition['input_schema'] ?? null)
                    ? $definition['input_schema']
                    : ['type' => 'object', 'properties' => []],
                readOnly: $readOnly,
                idempotent: $readOnly,
                physicalMinimum: 1,
                physicalLikely: 1,
                physicalMaximum: $physicalMaximum,
                executorReference: $route->id,
                metadata: [
                    'endpoint_type' => $route->endpoint_type->value,
                    'items_path' => $route->items_path,
                    'pagination' => $pagination,
                    'relations' => $outboundRelations,
                ],
            );
        }
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function mergeMcpTools(array &$tools, ?User $user): void
    {
        if (! $user instanceof User || ! (bool) config('mcp.enabled', false)) {
            return;
        }

        foreach ($this->mcpServers->activeServersForTenant() as $server) {
            if (! $server instanceof McpServer) {
                continue;
            }
            $enabled = $server->enabled_tools_json;
            if (! is_array($enabled) || $enabled === []) {
                continue;
            }
            $allowAll = $enabled === ['*'];
            foreach ($this->mcpDefinitions($server) as $definition) {
                $name = (string) ($definition['name'] ?? '');
                if ($name === '' || isset($tools[$name])
                    || (! $allowAll && ! in_array($name, $enabled, true))
                    || ! $this->mcpAuthorizer->canInvoke($user, $server, $name)) {
                    continue;
                }
                $schema = $definition['inputSchema'] ?? $definition['input_schema'] ?? [];
                $tools[$name] = new AgentToolDefinition(
                    name: $name,
                    displayName: $name,
                    description: (string) ($definition['description'] ?? $name),
                    kind: 'mcp',
                    inputSchema: is_array($schema) ? $schema : ['type' => 'object', 'properties' => []],
                    readOnly: (bool) ($definition['readOnlyHint'] ?? false),
                    idempotent: (bool) ($definition['idempotentHint'] ?? false),
                    physicalMinimum: 1,
                    physicalLikely: 1,
                    physicalMaximum: 1,
                    executorReference: $server->id,
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function mcpDefinitions(McpServer $server): array
    {
        $handshake = $server->handshake_response_json;
        foreach (['tools', 'capabilities.tools', 'tool.list'] as $path) {
            $tools = is_array($handshake) ? data_get($handshake, $path) : null;
            if (is_array($tools)) {
                return array_values(array_filter($tools, 'is_array'));
            }
        }

        return [];
    }
}
