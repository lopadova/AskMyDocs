<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mcp\Migration\McpShadowComparisonService;
use App\Mcp\Runtime\McpRuntimeGate;
use App\Models\McpServer;
use Illuminate\Console\Command;

final class CompareMcpConnectorShadowCommand extends Command
{
    protected $signature = 'mcp-connectors:shadow {--tenant=* : Compare only these tenant ids} {--connection=* : Compare only these legacy server ids}';

    protected $description = 'Compare live MCP connector discovery with the legacy mcp_servers catalog for shadow tenants.';

    public function handle(McpRuntimeGate $runtime, McpShadowComparisonService $comparisons): int
    {
        $requestedTenants = array_values(array_filter((array) $this->option('tenant'), 'is_string'));
        $requestedServers = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
            (array) $this->option('connection'),
        )));
        $tenants = $requestedTenants !== []
            ? $requestedTenants
            : McpServer::withoutGlobalScopes()->distinct()->orderBy('tenant_id')->pluck('tenant_id')->all();

        foreach ($tenants as $tenantId) {
            if (! is_string($tenantId) || $tenantId === '' || ! $runtime->runsShadow($tenantId)) {
                continue;
            }
            McpServer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->when($requestedServers !== [], fn ($query) => $query->whereIn('id', $requestedServers))
                ->orderBy('id')
                ->each(function (McpServer $server) use ($comparisons): void {
                    $report = $comparisons->compare($server);
                    $this->line(sprintf(
                        '%s #%d: %s (%d blocker, %d warning)',
                        $server->tenant_id,
                        $server->getKey(),
                        $report->status,
                        count($report->blockers_json ?? []),
                        count($report->warnings_json ?? []),
                    ));
                });
        }

        return self::SUCCESS;
    }
}
