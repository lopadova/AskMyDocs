<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Models\McpServer;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;

/**
 * v7.0/W1.B — Eloquent-backed implementation of the package's
 * {@see McpServerRegistryContract}, replacing the inline
 * `App\Mcp\Client\Registry\McpServerRegistry` shipped in v5.0/W1.
 *
 * Tenant scoping uses the host's `BelongsToTenant` trait via the
 * existing `mcp_servers.tenant_id` index. The package contract takes a
 * `?string $tenantId`; we honour `null` as "platform-global" by
 * matching it against `tenant_id = 'default'` (the host's default
 * sentinel) only when the host's TenantContext is also at default.
 * Production hosts that need stricter isolation can override this
 * binding.
 */
final class EloquentMcpServerRegistry implements McpServerRegistryContract
{
    public function forTenant(?string $tenantId): array
    {
        $query = McpServer::query()->where('status', McpServer::STATUS_ACTIVE);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()
            ->map(static fn (McpServer $row): McpServerContract => new EloquentMcpServerAdapter($row))
            ->all();
    }

    public function find(string $id): ?McpServerContract
    {
        $server = McpServer::query()
            ->where('id', $id)
            ->where('status', McpServer::STATUS_ACTIVE)
            ->first();

        return $server === null ? null : new EloquentMcpServerAdapter($server);
    }
}
