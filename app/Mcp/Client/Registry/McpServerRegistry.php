<?php

declare(strict_types=1);

namespace App\Mcp\Client\Registry;

use App\Models\McpServer;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v5.0/W1 scaffold — tenant-scoped registry view over
 * `mcp_servers`.
 *
 * Tracks server definitions and enabled tool sets. The full
 * per-server permission resolution joins this to McpToolAuthorizer
 * in later W2/W4 slices.
 */
final class McpServerRegistry
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function activeServersForTenant(): Collection
    {
        return McpServer::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('status', McpServer::STATUS_ACTIVE)
            ->get();
    }

    public function enabledToolsForServer(int $serverId): array
    {
        $server = $this->forTenant($serverId)->first();
        if ($server === null) {
            return [];
        }

        $tools = $server->enabled_tools_json;
        return is_array($tools) ? $tools : [];
    }

    public function forTenant(?int $serverId = null): Collection
    {
        $query = McpServer::query()
            ->where('tenant_id', $this->tenantContext->current());

        if ($serverId !== null) {
            $query->where('id', $serverId);
        }

        return $query->get();
    }

    public function hasServer(int $serverId): bool
    {
        return $this->findServer($serverId) !== null;
    }

    public function activeToolsByTenant(): array
    {
        $toolMap = [];
        foreach ($this->activeServersForTenant() as $server) {
            $tools = $server->enabled_tools_json;
            if (! is_array($tools)) {
                continue;
            }

            $toolMap[$server->id] = $tools;
        }

        return $toolMap;
    }

    public function findServer(int $serverId): ?McpServer
    {
        return $this->forTenant($serverId)->first();
    }

    public function resolveForTenant(int $serverId): McpServer
    {
        $server = $this->findServer($serverId);
        if ($server === null) {
            throw new NotFoundHttpException('MCP server not found.');
        }

        return $server;
    }
}
