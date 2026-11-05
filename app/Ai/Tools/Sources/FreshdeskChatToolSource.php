<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sources;

use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Models\User;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

final readonly class FreshdeskChatToolSource implements ChatToolSourceContract
{
    public function __construct(private FreshdeskTools $tools) {}

    public function key(): string
    {
        return 'freshdesk';
    }

    public function catalog(User $user, ?string $projectKey = null): array
    {
        if (app(TenantContext::class)->current() !== app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->current()) {
            return [];
        }

        return $projectKey !== null && ($user->canReadAllProjects() || in_array($projectKey, $user->allowedProjects(), true)) ? $this->tools->catalog($projectKey) : [];
    }

    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
    {
        $project = $context['project_key'] ?? null;
        $name = (string) ($tool['name'] ?? '');
        if (($context['channel'] ?? null) === 'widget'
            || isset($context['tenant_id']) && $context['tenant_id'] !== app(TenantContext::class)->current()
            || ! is_string($project) || ! collect($this->catalog($user, $project))->contains('name', $name)) {
            throw new \RuntimeException('Freshdesk tool is not authorized in this project.');
        }
        $result = $this->tools->execute($name, $arguments, $project);

        return new ChatToolInvocationResult(
            status: isset($result['error']) ? 'error' : 'completed', payload: $result['data'] ?? ['error' => $result['error'] ?? ''],
            metadata: ['source' => 'freshdesk', 'physical_request_count' => $result['physical_request_count'], 'provenance' => $result['provenance']], error: $result['error'] ?? null,
        );
    }
}
