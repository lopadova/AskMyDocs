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
 * needs a tenant-scoping design (R30) tracked separately for a later Ciclo 2 PR.
 */
final class IngestionObservabilityService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ?\App\Services\Kb\Embedding\ReembedStaleService $staleEmbeddings = null,
    ) {}

    /**
     * v8.42/W2 (ADR 0035 §D2) — how many of this tenant's chunk vectors were
     * produced under a different recipe than the active one (additive key on
     * the ingestion-status surfaces, R27). Read-only: aggregate SQL only, no
     * provider call. Returns null — never throws — when the embeddings
     * provider cannot be resolved (e.g. no key configured), so a status
     * endpoint never 500s because of this block (R14: null = "unknown").
     *
     * @return array<string,mixed>|null
     */
    public function staleEmbeddings(): ?array
    {
        $service = $this->staleEmbeddings ?? app(\App\Services\Kb\Embedding\ReembedStaleService::class);

        try {
            return $service->summary($this->tenants->current());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * v8.45 / ADR 0035 D7 — the code ingestion block (additive key on the
     * ingestion-status surfaces, R27; tenant-scoped, R30): is the feature on,
     * which chunker driver is configured and actually produced the chunks, and
     * how many documents had credentials redacted (a COUNT only — SEC-LOG-001).
     * Null — never an exception — when it cannot be computed (R14: unknown).
     *
     * @return array<string,mixed>|null
     */
    public function codeIngestion(): ?array
    {
        try {
            $tenant = $this->tenants->current();
            $enabled = (bool) config('kb.code.ingestion_enabled', false);
            $resolver = app(\App\Services\Kb\Code\BreakPointDriverResolver::class);

            $documents = \App\Models\KnowledgeDocument::query()->forTenant($tenant)->where('source_type', 'code')->count();
            $byChunker = [];
            foreach (['tree_sitter', 'heuristic'] as $driver) {
                $byChunker[$driver] = \App\Models\KnowledgeChunk::query()->forTenant($tenant)->where('metadata->chunker', $driver)->count();
            }
            $redactedDocs = \App\Models\KnowledgeChunk::query()->forTenant($tenant)->whereNotNull('metadata->secrets_redacted')->distinct()->count('knowledge_document_id');
            $secrets = 0;
            foreach (\App\Models\KnowledgeChunk::query()->forTenant($tenant)->whereNotNull('metadata->secrets_redacted')->limit(5000)->get(['metadata']) as $chunk) {
                $secrets += (int) ($chunk->metadata['secrets_redacted'] ?? 0);
            }

            return [
                'enabled' => $enabled,
                'chunker_driver' => $resolver->mode(),
                'documents' => $documents,
                'chunks_by_chunker' => $byChunker,
                'documents_with_secrets_redacted' => $redactedDocs,
                'secrets_redacted' => $secrets,
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{name: string, role: string, depth: int|null}>
     */
    public function queueDepths(): array
    {
        $roles = [
            ['role' => 'connector-sync', 'name' => (string) config('connectors.sync_job_queue', 'connectors')],
            ['role' => 'kb-ingest', 'name' => (string) config('kb.ingest.queue', 'kb-ingest')],
            ['role' => 'default', 'name' => $this->defaultQueueName()],
        ];

        // ALWAYS return all three logical roles (the documented contract), even
        // when an operator points two of them at the same physical queue — only
        // the depth lookup is de-duped so a shared queue isn't sized twice.
        $depthCache = [];
        $out = [];
        foreach ($roles as $r) {
            if (! array_key_exists($r['name'], $depthCache)) {
                $depthCache[$r['name']] = $this->depthOf($r['name']);
            }
            $out[] = [
                'name' => $r['name'],
                'role' => $r['role'],
                'depth' => $depthCache[$r['name']],
            ];
        }

        return $out;
    }

    /**
     * The physical queue name the `default` role maps to — derived from the
     * active queue connection's configured queue (e.g. REDIS_QUEUE / DB_QUEUE),
     * not hard-coded to the literal string 'default'.
     */
    private function defaultQueueName(): string
    {
        $connection = (string) config('queue.default', 'sync');

        return (string) config("queue.connections.{$connection}.queue", 'default');
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
