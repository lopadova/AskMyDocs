<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.20 — PHP read surface (R44) for connector installations.
 *
 * The Artisan sibling of the HTTP `GET /api/admin/connectors` index and the
 * {@see \App\Mcp\Tools\ConnectorInstallationsTool} MCP tool — all three over the
 * SAME core {@see ConnectorInstallationService::summary()}. Tenant-scoped (R30)
 * via the `--tenant` option (default 'default').
 */
final class ConnectorsListCommand extends Command
{
    protected $signature = 'connectors:list
                            {--tenant=default : Tenant to list accounts for}
                            {--connector= : Restrict to a single connector key}
                            {--include-empty : Also show connectors with no connected accounts}';

    protected $description = 'List connector accounts (multi-account) and their sync status for a tenant.';

    public function handle(ConnectorInstallationService $service, TenantContext $tenants): int
    {
        $tenant = (string) $this->option('tenant');
        $only = $this->option('connector');
        $includeEmpty = (bool) $this->option('include-empty');

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $rows = [];
            foreach ($service->summary() as $entry) {
                if ($only !== null && $only !== '' && $entry['key'] !== $only) {
                    continue;
                }
                if ($entry['installations'] === [] && ! $includeEmpty) {
                    continue;
                }
                if ($entry['installations'] === []) {
                    $rows[] = [$entry['key'], '—', '—', '—', '—'];

                    continue;
                }
                foreach ($entry['installations'] as $i) {
                    $rows[] = [
                        $entry['key'],
                        $i['label'],
                        $i['project_key'] ?? '(tenant default)',
                        $i['status'],
                        $i['last_sync_at'] ?? 'never',
                    ];
                }
            }
        } finally {
            $tenants->set($previous);
        }

        if ($rows === []) {
            $this->info("No connector accounts for tenant [{$tenant}].");

            return self::SUCCESS;
        }

        $this->table(['Connector', 'Label', 'Project', 'Status', 'Last sync'], $rows);

        return self::SUCCESS;
    }
}
