<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

final class ToolAccess
{
    public function assertActor(AgentExecutionContext $context): User
    {
        $user = $context->actorId ? User::query()->find($context->actorId) : null;
        $tenantStatus = DB::table('tenants')->where('slug', $context->tenantId)->value('status');
        if ($context->channel !== 'chat' || in_array($tenantStatus, ['suspended', 'archived'], true) || ! $user || ! $user->is_active || $context->tenantId !== app(TenantContext::class)->current()
            || ! DB::table('project_memberships')->where('user_id', $user->id)->where('tenant_id', $context->tenantId)->exists()
            || (! $user->canReadAllProjects() && ($context->projectKey === null || ! in_array($context->projectKey, $user->allowedProjects(), true)))) {
            throw new \DomainException('api_tool_authorization_revoked');
        }
        return $user;
    }

    public function routes(string $tenantId, string $projectKey): Collection
    {
        return ApiRoute::query()->forTenant($tenantId)
            ->whereIn('project_key', $projectKey === '' ? [''] : ['', $projectKey])
            ->where('status', 'active')->whereIn('mode', ['tool','both'])->whereIn('http_method', ['GET','HEAD'])
            ->whereHas('connector', fn ($q) => $q->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('project_key', $projectKey === '' ? [''] : ['', $projectKey]))
            ->with(['parameters', 'connector'])->get();
    }

    public function route(int $id, AgentExecutionContext $context): ApiRoute
    {
        $this->assertActor($context);
        return $this->routes($context->tenantId, $context->projectKey ?? '')->firstWhere('id', $id)
            ?? throw new \DomainException('api_tool_dependency_unavailable');
    }
}
