<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sources;

use App\Agent\Tools\ApiToolRequestContext;
use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Models\User;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

final readonly class ApiConnectorChatToolSource implements ChatToolSourceContract
{
    public function __construct(
        private TenantContext $tenants,
        private ApiToolRegistry $registry,
        private ApiToolExecutor $executor,
        private ApiToolRequestContext $requestContext,
    ) {}

    public function key(): string
    {
        return 'api';
    }

    public function catalog(User $user, ?string $projectKey = null): array
    {
        if (! config('connector-api.chat_tools.enabled', false)) {
            return [];
        }

        $context = $this->presetContext($user, $projectKey);
        $presets = array_map(static fn ($tool) => [
            'name'=>$tool->name, 'description'=>$tool->description, 'inputSchema'=>$tool->inputSchema,
            'source_runtime'=>'api_tool', 'preset_id'=>(int) substr((string) $tool->executorReference,9),
            'provenance'=>['source'=>'api_tool','tool_remote_name'=>$tool->name],
        ], array_values(app(\App\ApiTools\ToolCatalog::class)->forContext($context)));
        return [...$presets, ...array_map(static function (array $tool): array {
            $definition = is_array($tool['definition'] ?? null) ? $tool['definition'] : [];

            return [
                'name' => (string) ($tool['name'] ?? ''),
                'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
                'inputSchema' => is_array($definition['input_schema'] ?? null) ? $definition['input_schema'] : [],
                'route_id' => $tool['route_id'] ?? null,
                'provenance' => [
                    'source' => 'api',
                    'route_id' => $tool['route_id'] ?? null,
                    'tool_remote_name' => $tool['name'] ?? null,
                ],
            ];
        }, $this->registry->activeToolsForTenant($this->tenants->current(), $projectKey))];
    }

    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
    {
        $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
        $projectKey = is_string($context['project_key'] ?? null) ? $context['project_key'] : null;
        if (($tool['source_runtime'] ?? null) === 'api_tool') {
            $ctx=$this->presetContext($user,$projectKey);
            $preset=\App\ApiTools\Models\ApiTool::query()->forTenant($ctx->tenantId)->findOrFail($tool['preset_id'] ?? 0);
            $pending=app(\App\ApiTools\ToolRuntime::class)->start($preset,$arguments,$ctx,continuation:['_idempotency_key'=>$context['_api_tool_idempotency_key'] ?? null,'conversation_id'=>$context['conversation_id'] ?? null,'request_limit'=>max(0,(int) ($context['_api_tool_chat_budget']['physical_limit'] ?? config('api-tools.max_physical_requests',100))-(int) ($context['_api_tool_chat_budget']['physical_used'] ?? 0))]);
            return new ChatToolInvocationResult(status:'task_accepted',payload:['run_id'=>$pending->id],metadata:['source'=>'api_tool','run_id'=>$pending->id]);
        }
        $route = $this->registry->routeForTool($this->tenants->current(), $name, $projectKey);
        if ($route === null) {
            throw new \RuntimeException('API connector tool is no longer available.');
        }
        $prepared = $this->requestContext->apply($route, $arguments, $context);
        $measured = app(\App\ApiTools\ToolHttpBudget::class)->measure(fn () => $this->executor->execute(
            $prepared['route'], $prepared['arguments'], $prepared['context'],
        ), max(0,(int) ($context['_api_tool_chat_budget']['physical_limit'] ?? PHP_INT_MAX)-(int) ($context['_api_tool_chat_budget']['physical_used'] ?? 0)));
        $payload = $measured['body'];
        $error = is_string($payload['error'] ?? null) ? $payload['error'] : null;

        return new ChatToolInvocationResult(
            status: $error === null ? 'completed' : 'error',
            payload: $payload,
            metadata: [
                'source' => $this->key(),
                'physical_request_count' => $measured['physical_requests'],
                'route_id' => $route->id,
                'tool_remote_name' => $name,
            ],
            error: $error,
        );
    }
    private function presetContext(User $user, ?string $projectKey): \App\Agent\AgentExecutionContext
    {
        return new \App\Agent\AgentExecutionContext((string) \Illuminate\Support\Str::uuid(),$this->tenants->current(),$projectKey,'chat','user',(string) $user->id,\App\Support\SupportedLocale::normalize($user->locale ?? null),(string) config('app.timezone','UTC'));
    }
}
