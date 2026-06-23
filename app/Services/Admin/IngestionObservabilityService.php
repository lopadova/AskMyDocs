<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\ConnectorSyncRun;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * v8.21 (Ciclo 2) — the SINGLE core for ingestion/sync observability, shared by
 * the HTTP controller, the MCP `KbIngestionStatusTool` and the
 * `ingestion:status` command (R44, one core).
 *
 * Two read surfaces:
 *   - {@see queueDepths()} — pending-job counts for the three logical queues
 *     (connectors / kb-ingest / default), so an operator sees backlog at a
 *     glance. Driver-tolerant: a queue connection without a usable `size()`
 *     (e.g. the `sync` driver in tests) reports `null`, never throws.
 *   - {@see syncRunsForInstallation()} — recent per-account sync history from
 *     `connector_sync_runs`, tenant-scoped (R30).
 *
 * Per-document ingestion status (derived from the package `flow_runs` table) is
 * a deliberate follow-up: `flow_runs` is not tenant-aware, so exposing it safely
 * needs a tenant-scoping design (R30) tracked separately — see ADR 0018.
 */
final class IngestionObservabilityService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<array{name: string, role: string, depth: int|null}>
     */
    public function queueDepths(): array
    {
        $connectors = (string) config('connectors.sync_job_queue', 'connectors');
        $kbIngest = (string) config('kb.ingest.queue', 'kb-ingest');

        // De-dupe by queue name (a deployment may point two roles at one queue)
        // while keeping a stable, documented order.
        $queues = [
            ['name' => $connectors, 'role' => 'connector-sync'],
            ['name' => $kbIngest, 'role' => 'kb-ingest'],
            ['name' => 'default', 'role' => 'default'],
        ];

        $seen = [];
        $out = [];
        foreach ($queues as $q) {
            if (isset($seen[$q['name']])) {
                continue;
            }
            $seen[$q['name']] = true;
            $out[] = [
                'name' => $q['name'],
                'role' => $q['role'],
                'depth' => $this->depthOf($q['name']),
            ];
        }

        return $out;
    }

    private function depthOf(string $queue): ?int
    {
        try {
            return Queue::size($queue);
        } catch (Throwable) {
            // Drivers like `sync` / `null` don't implement a meaningful size().
            return null;
        }
    }

    /**
     * Recent sync runs for ONE installation, newest first. Validates the
     * installation belongs to the active tenant (404 otherwise) so the endpoint
     * can't be used to probe other tenants' installation ids.
     *
     * @return list<array<string,mixed>>
     */
    public function syncRunsForInstallation(int $installationId, int $limit = 20): array
    {
        $tenantId = $this->tenants->current();

        $exists = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $tenantId)
            ->exists();
        if (! $exists) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        return ConnectorSyncRun::query()
            ->where('tenant_id', $tenantId)
            ->where('connector_installation_id', $installationId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (ConnectorSyncRun $r) => $this->runArray($r))
            ->all();
    }

    /**
     * Recent sync runs across ALL of the active tenant's installations, newest
     * first (tenant-scoped, R30) — for the MCP tool + CLI status surfaces.
     *
     * @return list<array<string,mixed>>
     */
    public function recentRuns(int $limit = 20): array
    {
        return ConnectorSyncRun::query()
            ->where('tenant_id', $this->tenants->current())
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (ConnectorSyncRun $r) => $this->runArray($r))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function runArray(ConnectorSyncRun $r): array
    {
        return [
            'id' => $r->id,
            'connector_name' => $r->connector_name,
            'label' => $r->label,
            'queue' => $r->queue,
            'status' => $r->status,
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'duration_ms' => $r->duration_ms,
            'items_discovered' => $r->items_discovered,
            'items_failed' => $r->items_failed,
            'error' => $r->error_json,
        ];
    }
}
