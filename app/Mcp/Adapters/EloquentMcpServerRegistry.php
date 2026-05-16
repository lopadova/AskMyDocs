<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Models\McpServer;
use App\Support\TenantContext;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;

/**
 * v7.0/W6.3 — host adapter for the package's
 * {@see McpServerRegistryContract} backed by the
 * `App\Models\McpServer` Eloquent table.
 *
 * Returns only ACTIVE rows so the orchestrator never tries to
 * handshake against a pending / disabled / errored upstream.
 *
 * **R30 tenant boundary**: `mcp_servers` is tenant-scoped on the
 * host, so a bare `forTenant(null)` would be cross-tenant data
 * leakage. When the contract caller passes `null` (system contexts
 * like queue workers that pre-date the package's tenant routing),
 * the adapter resolves the active tenant from the host's
 * `TenantContext` singleton instead — matching the inline
 * `App\Mcp\Client\Registry\McpServerRegistry` semantics this
 * adapter replaces. The same tenant-resolved scope applies to
 * `find()` so duplicate ids across tenants surface the row owned
 * by the active tenant, not whichever the query happened to hit
 * first.
 */
final class EloquentMcpServerRegistry implements McpServerRegistryContract
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<int, McpServerContract>
     */
    public function forTenant(?string $tenantId): array
    {
        $tenant = $this->resolveTenant($tenantId);

        // Use `forTenant()` (from the `BelongsToTenant` trait) for
        // tenant scoping so the filter goes through the same scope
        // every other host query uses — table-qualified column name
        // survives future joins without ambiguous-column errors.
        return McpServer::query()
            ->forTenant($tenant)
            ->where('status', McpServer::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->map(static fn(McpServer $s): McpServerContract => new McpServerAdapter($s))
            ->all();
    }

    public function find(string $id): ?McpServerContract
    {
        // The package contract scopes ids per tenant. Accept both
        // numeric (the host's autoincrement) and string ids so
        // package callers that already cast to string for their
        // own cache keys round-trip cleanly. Non-numeric input
        // returns null without an SQL error.
        if (! ctype_digit($id)) {
            return null;
        }
        $server = McpServer::query()
            ->forTenant($this->tenantContext->current())
            ->where('id', (int) $id)
            ->where('status', McpServer::STATUS_ACTIVE)
            ->first();
        return $server === null ? null : new McpServerAdapter($server);
    }

    private function resolveTenant(?string $tenantId): string
    {
        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }
        // Fall back to the host's TenantContext so a null hint
        // never widens the query to every tenant's rows.
        return $this->tenantContext->current();
    }
}
