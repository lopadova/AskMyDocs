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

        return array_map(static function (array $tool): array {
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
        }, $this->registry->activeToolsForTenant($this->tenants->current(), $projectKey));
    }

    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
    {
        $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
        $projectKey = is_string($context['project_key'] ?? null) ? $context['project_key'] : null;
        $route = $this->registry->routeForTool($this->tenants->current(), $name, $projectKey);
        if ($route === null) {
            throw new \RuntimeException('API connector tool is no longer available.');
        }
        $prepared = $this->requestContext->apply($route, $arguments, $context);
        $payload = $this->executor->execute(
            $prepared['route'],
            $prepared['arguments'],
            $prepared['context'],
        );
        $error = is_string($payload['error'] ?? null) ? $payload['error'] : null;

        return new ChatToolInvocationResult(
            status: $error === null ? 'completed' : 'error',
            payload: $payload,
            metadata: [
                'source' => $this->key(),
                'route_id' => $route->id,
                'tool_remote_name' => $name,
            ],
            error: $error,
        );
    }
}
