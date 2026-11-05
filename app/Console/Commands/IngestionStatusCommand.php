<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\IngestionObservabilityService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.21 (Ciclo 2) — PHP read surface (R44) for ingestion/sync observability.
 *
 * The Artisan sibling of the HTTP `ingestion/queue` endpoint and the MCP
 * `KbIngestionStatusTool`, over the SAME core
 * {@see IngestionObservabilityService}. Tenant-scoped (R30) via `--tenant`.
 */
final class IngestionStatusCommand extends Command
{
    protected $signature = 'ingestion:status
                            {--tenant=default : Tenant to report on}
                            {--limit=20 : Max recent sync runs to show}';

    protected $description = 'Show connector-sync queue depths + recent sync runs for a tenant.';

    public function handle(IngestionObservabilityService $service, TenantContext $tenants): int
    {
        $tenant = (string) $this->option('tenant');
        $limit = (int) $this->option('limit');

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $queues = $service->queueDepths();
            $runs = $service->recentRuns($limit);
            $stale = $service->staleEmbeddings();
            $code = $service->codeIngestion();
        } finally {
            $tenants->set($previous);
        }

        $this->info("Queue depths (tenant: {$tenant})");
        $this->table(
            ['Queue', 'Role', 'Depth'],
            array_map(
                static fn (array $q) => [$q['name'], $q['role'], $q['depth'] ?? 'n/a'],
                $queues,
            ),
        );

        if ($stale !== null) {
            $this->line(sprintf(
                'Stale embeddings: %d chunk(s) in %d document(s) differ from the active recipe %s (run `kb:reembed-stale --dry-run`).',
                $stale['stale_chunks'],
                $stale['stale_documents'],
                $stale['active_fingerprint'],
            ));
        }

        if ($code !== null && ($code['enabled'] || $code['documents'] > 0)) {
            $this->line(sprintf(
                'Code ingestion: %s · %d code document(s) · chunks tree_sitter=%d heuristic=%d · %d document(s) had credentials redacted (%d value(s)).',
                $code['enabled'] ? 'ON' : 'OFF',
                $code['documents'],
                $code['chunks_by_chunker']['tree_sitter'],
                $code['chunks_by_chunker']['heuristic'],
                $code['documents_with_secrets_redacted'],
                $code['secrets_redacted'],
            ));
        }

        if ($runs === []) {
            $this->info('No connector sync runs recorded yet.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Recent sync runs');
        $this->table(
            ['Connector', 'Label', 'Status', 'Discovered', 'Failed', 'Duration (ms)', 'Started'],
            array_map(
                static fn (array $r) => [
                    $r['connector_name'],
                    $r['label'],
                    $r['status'],
                    $r['items_discovered'],
                    $r['items_failed'],
                    $r['duration_ms'] ?? 'n/a',
                    $r['started_at'] ?? 'n/a',
                ],
                $runs,
            ),
        );

        return self::SUCCESS;
    }
}
