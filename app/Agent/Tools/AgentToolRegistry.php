<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AgentExecutionContext;
use App\Ai\Tools\Sources\McpConnectorChatToolSource;
use App\Mcp\Client\McpToolAuthorizer;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Mcp\Runtime\McpRuntimeGate;
use App\Models\McpServer;
use App\Models\User;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;

final readonly class AgentToolRegistry
{
    public function __construct(
        private McpServerRegistry $mcpServers,
        private McpToolAuthorizer $mcpAuthorizer,
        private McpConnectorChatToolSource $connectorMcp,
        private McpRuntimeGate $mcpRuntime,
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
        $this->mergeMcpTools($tools, $context, $user);
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

        $scopes = $context->projectKey === null || $context->projectKey === ''
            ? ['']
            : ['', $context->projectKey];
        $routes = ApiRoute::query()
            ->forTenant($context->tenantId)
            ->whereHas('connector', fn ($query) => $query->where('is_active', true))
            ->where('status', RouteStatus::Active->value)
            ->whereIn('mode', [RouteMode::Tool->value, RouteMode::Both->value])
            ->whereIn('project_key', $scopes)
            ->with(['connector:id,name,description,project_key', 'listRelations.detailRoute'])
            ->get()
            ->sortBy('id');

        foreach ($routes as $route) {
            $definition = is_array($route->tool_definition) ? $route->tool_definition : [
                'name' => $route->slug,
                'description' => $route->description ?? $route->name,
                'input_schema' => is_array($route->input_schema)
                    ? $route->input_schema
                    : ['type' => 'object', 'properties' => [], 'required' => []],
            ];
            $name = is_array($definition) ? (string) ($definition['name'] ?? '') : '';
            if ($name === '' || isset($tools[$name])) {
                continue;
            }

            $method = strtoupper((string) $route->http_method->value);
            $readOnly = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $pagination = is_array($route->pagination) ? $route->pagination : null;
            $physicalMaximum = $pagination === null ? 1 : max(1, (int) config('agent.limits.physical_hard', 100));
            $outboundRelations = $route->listRelations->map(static function ($relation): array {
                $detail = $relation->detailRoute;
                $definition = $detail instanceof ApiRoute && is_array($detail->tool_definition)
                    ? $detail->tool_definition
                    : [];

                return [
                    'detail_route_id' => $relation->detail_route_id,
                    'detail_tool' => is_string($definition['name'] ?? null)
                        ? $definition['name']
                        : $detail?->slug,
                    'field_map' => $relation->field_map,
                ];
            })->values()->all();

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
                    'source_key' => 'api:'.$route->api_connector_id,
                    'source_name' => (string) ($route->connector?->name ?? $route->name),
                    'source_description' => $route->connector?->description,
                    'source_project_key' => $route->connector?->project_key,
                    'endpoint_type' => $route->endpoint_type->value,
                    'items_path' => $route->items_path,
                    'output_schema' => is_array($route->output_schema) ? $route->output_schema : null,
                    'pagination' => $pagination,
                    'relations' => $outboundRelations,
                ],
            );
        }
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function mergeMcpTools(
        array &$tools,
        AgentExecutionContext $context,
        ?User $user,
    ): void
    {
        if (! $user instanceof User) {
            return;
        }

        if ($this->mcpRuntime->usesConnector($context->tenantId)) {
            $this->mergeConnectorMcpTools($tools, $context, $user);

            return;
        }

        if (! (bool) config('mcp.enabled', false)) {
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
                $annotations = is_array($definition['annotations'] ?? null) ? $definition['annotations'] : [];
                $tools[$name] = new AgentToolDefinition(
                    name: $name,
                    displayName: $name,
                    description: (string) ($definition['description'] ?? $name),
                    kind: 'mcp',
                    inputSchema: is_array($schema) ? $schema : ['type' => 'object', 'properties' => []],
                    readOnly: (bool) ($annotations['readOnlyHint'] ?? $definition['readOnlyHint'] ?? false),
                    idempotent: (bool) ($annotations['idempotentHint'] ?? $definition['idempotentHint'] ?? false),
                    physicalMinimum: 1,
                    physicalLikely: 1,
                    physicalMaximum: 1,
                    executorReference: $server->id,
                    metadata: [
                        'source_key' => 'mcp:legacy:'.$server->id,
                        'source_name' => (string) $server->name,
                        'mcp_runtime' => 'legacy',
                        'server_id' => $server->id,
                        'server_name' => (string) $server->name,
                        'output_schema' => is_array($definition['outputSchema'] ?? null)
                            ? $definition['outputSchema']
                            : null,
                        'agent_capability_hint' => data_get($definition, '_meta.askmydocs/agent-capability'),
                    ],
                );
            }
        }
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function mergeConnectorMcpTools(
        array &$tools,
        AgentExecutionContext $context,
        User $user,
    ): void
    {
        foreach ($this->connectorMcp->catalog($user, $context->projectKey) as $definition) {
            $name = is_string($definition['name'] ?? null) ? $definition['name'] : '';
            if ($name === '' || isset($tools[$name])) {
                continue;
            }

            $annotations = is_array($definition['annotations'] ?? null)
                ? $definition['annotations']
                : [];
            $provenance = is_array($definition['provenance'] ?? null)
                ? $definition['provenance']
                : [];
            $inputSchema = is_array($definition['inputSchema'] ?? null)
                ? $definition['inputSchema']
                : ['type' => 'object', 'properties' => []];
            $inputSchema['type'] ??= 'object';
            $inputSchema['properties'] ??= [];
            $remoteName = is_string($provenance['tool_remote_name'] ?? null)
                ? $provenance['tool_remote_name']
                : $name;
            $risk = is_string($definition['risk'] ?? null) ? $definition['risk'] : 'unknown';
            $readOnly = (bool) ($annotations['readOnlyHint'] ?? ($risk === 'read'));
            $idempotent = (bool) ($annotations['idempotentHint'] ?? $readOnly);

            $tools[$name] = new AgentToolDefinition(
                name: $name,
                displayName: $remoteName,
                description: is_string($definition['description'] ?? null)
                    ? $definition['description']
                    : $remoteName,
                kind: 'mcp',
                inputSchema: $inputSchema,
                readOnly: $readOnly,
                idempotent: $idempotent,
                physicalMinimum: 1,
                physicalLikely: 1,
                physicalMaximum: 1,
                executorReference: $name,
                metadata: [
                    'source_key' => 'mcp:'.(string) ($provenance['connection_id'] ?? ''),
                    'source_name' => (string) ($provenance['server_name'] ?? $remoteName),
                    'mcp_runtime' => 'connector',
                    'risk' => $risk,
                    'confirmation_required' => (bool) ($definition['confirmationRequired'] ?? false),
                    'output_schema' => is_array($definition['outputSchema'] ?? null)
                        ? $definition['outputSchema']
                        : null,
                    'agent_capability_hint' => is_array($definition['agentCapability'] ?? null)
                        ? $definition['agentCapability']
                        : data_get($definition, '_meta.askmydocs/agent-capability'),
                    'provenance' => $provenance,
                    'meta' => is_array($definition['_meta'] ?? null) ? $definition['_meta'] : null,
                ],
            );
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
