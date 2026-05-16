<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Models\McpServer;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;

/**
 * v7.0/W6.3 — host adapter for the package's
 * {@see McpServerRegistryContract} backed by the
 * `App\Models\McpServer` Eloquent table.
 *
 * Returns only ACTIVE rows so the orchestrator never tries to
 * handshake against a pending / disabled / errored upstream. The
 * `find()` lookup is tenant-aware via the caller's tenant hint —
 * the caller (host controller) passes the active tenant from its
 * Sanctum/RBAC middleware. Cross-tenant id collisions surface the
 * row owned by the matching tenant.
 */
final class EloquentMcpServerRegistry implements McpServerRegistryContract
{
    /**
     * @return array<int, McpServerContract>
     */
    public function forTenant(?string $tenantId): array
    {
        $query = McpServer::query()
            ->where('status', McpServer::STATUS_ACTIVE);

        // R30: when the host caller resolved an active tenant, scope
        // strictly. A `null` tenant means "platform-global" — the
        // package contract supports it for system-context flows like
        // queue workers that don't carry a user.
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->orderBy('name')
            ->get()
            ->map(static fn(McpServer $s): McpServerContract => new McpServerAdapter($s))
            ->all();
    }

    public function find(string $id): ?McpServerContract
    {
        // The package's contract scopes ids per tenant. We accept
        // both numeric (the host's autoincrement) and string ids
        // here so package callers that already cast to string for
        // their own cache keys round-trip cleanly.
        if (! ctype_digit($id)) {
            return null;
        }
        $server = McpServer::query()
            ->where('id', (int) $id)
            ->where('status', McpServer::STATUS_ACTIVE)
            ->first();
        return $server === null ? null : new McpServerAdapter($server);
    }
}
