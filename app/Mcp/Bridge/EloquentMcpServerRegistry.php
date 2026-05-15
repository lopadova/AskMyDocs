<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Models\McpServer;
use App\Support\TenantContext;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;

/**
 * v7.0/W1.B — Eloquent-backed implementation of the package's
 * {@see McpServerRegistryContract}, replacing the inline
 * `App\Mcp\Client\Registry\McpServerRegistry` shipped in v5.0/W1.
 *
 * R30 — every read against `mcp_servers` is tenant-scoped. The package
 * contract uses `?string $tenantId` with `null` meaning "platform-global";
 * AskMyDocs has NO platform-global server concept (every row carries a
 * tenant_id, defaulting to the `'default'` sentinel), so a null
 * argument is interpreted as "scope to the host's active TenantContext".
 * `find()` always carries the active tenant filter — no cross-tenant
 * lookup by id is possible from this adapter.
 */
final class EloquentMcpServerRegistry implements McpServerRegistryContract
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function forTenant(?string $tenantId): array
    {
        $effectiveTenant = $tenantId ?? $this->tenantContext->current();

        return McpServer::query()
            ->where('status', McpServer::STATUS_ACTIVE)
            ->where('tenant_id', $effectiveTenant)
            ->get()
            ->map(static fn (McpServer $row): McpServerContract => new EloquentMcpServerAdapter($row))
            ->all();
    }

    public function find(string $id): ?McpServerContract
    {
        $server = McpServer::query()
            ->where('id', $id)
            ->where('tenant_id', $this->tenantContext->current())
            ->where('status', McpServer::STATUS_ACTIVE)
            ->first();

        return $server === null ? null : new EloquentMcpServerAdapter($server);
    }
}
