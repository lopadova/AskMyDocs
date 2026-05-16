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
 * **Unconfigured-tools guard**: the package's
 * `McpServerContract::allowedTools()` treats an empty array as
 * "all tools the server advertises" (wildcard). The host stores
 * `enabled_tools_json = ['*']` as its OWN explicit wildcard, but a
 * row with `null` or `[]` `enabled_tools_json` is "operator hasn't
 * picked tools yet" — surfacing it to the orchestrator would
 * silently widen permission vs the legacy `McpServer` authorizer
 * (which denied when the list was missing). The registry filters
 * those rows out so only servers with an EXPLICIT tools choice
 * (`['*']` or a concrete list) reach the package.
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
            ->filter(static fn(McpServer $s): bool => self::hasConfiguredTools($s))
            ->map(static fn(McpServer $s): McpServerContract => new McpServerAdapter($s))
            ->values()
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
        if ($server === null || ! self::hasConfiguredTools($server)) {
            return null;
        }
        return new McpServerAdapter($server);
    }

    /**
     * True when the operator has made an EXPLICIT, USABLE tools
     * choice for this server. Three shapes qualify; everything else
     * is treated as "unconfigured / invalid" and excluded from the
     * orchestrator's view (see class docblock for the widening
     * argument):
     *
     *   - exactly `['*']`        — host's explicit wildcard sentinel
     *   - a list containing at least one non-empty string
     *
     * The non-empty-string check matters because
     * `McpServerAdapter::allowedTools()` filters out non-string and
     * empty-string entries, so a garbage row like
     * `enabled_tools_json = [null]` / `[0]` / `['']` would otherwise
     * surface as `allowedTools() === []` to the package — the
     * package's "all tools" sentinel, silently widening permission.
     * A naive `is_array($t) && $t !== []` guard catches `null` / `[]`
     * but lets the garbage shapes through; this guard catches both.
     */
    private static function hasConfiguredTools(McpServer $server): bool
    {
        $tools = $server->enabled_tools_json;
        if (! is_array($tools) || $tools === []) {
            return false;
        }
        // Host wildcard sentinel — operator explicitly opted into
        // every tool the server advertises.
        if ($tools === ['*']) {
            return true;
        }
        // At least one entry must be a non-empty string for the row
        // to survive the adapter's downstream filter and produce a
        // meaningful allow-list.
        foreach ($tools as $t) {
            if (is_string($t) && trim($t) !== '') {
                return true;
            }
        }
        return false;
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
