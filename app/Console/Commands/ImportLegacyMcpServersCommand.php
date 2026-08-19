<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mcp\Migration\LegacyMcpServerImporter;
use App\Models\McpServer;
use Illuminate\Console\Command;

final class ImportLegacyMcpServersCommand extends Command
{
    protected $signature = 'mcp-connectors:import-legacy {--tenant=* : Import only these tenant ids}';

    protected $description = 'Idempotently import legacy mcp_servers into the MCP connector domain.';

    public function handle(LegacyMcpServerImporter $importer): int
    {
        $requested = array_values(array_filter((array) $this->option('tenant'), 'is_string'));
        $tenants = $requested !== []
            ? $requested
            : McpServer::withoutGlobalScopes()->distinct()->orderBy('tenant_id')->pluck('tenant_id')->all();

        foreach ($tenants as $tenant) {
            if (! is_string($tenant) || $tenant === '') {
                continue;
            }
            $counts = $importer->importTenant($tenant);
            $this->components->info(sprintf(
                '%s: %d server, %d nuove connessioni, %d tool riconciliati',
                $tenant,
                $counts['servers'],
                $counts['connections'],
                $counts['tools'],
            ));
        }

        return self::SUCCESS;
    }
}
