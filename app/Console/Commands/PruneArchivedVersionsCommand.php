<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v8.7/W5 — Cloud Time Machine retention.
 *
 * Archived document versions accumulate forever (every re-ingest keeps the
 * prior row + its chunks so the Time Machine can browse/restore them). This
 * command caps that history: per `(tenant, project_key, source_path)`
 * family it keeps the `--keep` most recent ARCHIVED versions and
 * hard-deletes the rest (chunks cascade via the FK). The live version and
 * soft-deleted rows are never touched.
 */
final class PruneArchivedVersionsCommand extends Command
{
    protected $signature = 'kb:prune-archived-versions
                            {--tenant= : Restrict to one tenant}
                            {--keep= : Override how many archived versions to retain per family}
                            {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Hard-delete old archived document versions beyond the retention cap';

    public function handle(TenantContext $tenants): int
    {
        $keep = max(0, (int) ($this->option('keep') ?? config('kb.versioning.keep_archived', 10)));
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No documents found. Nothing to prune.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $pruned = $this->pruneTenant($tenantId, $keep, $dryRun);
                $this->info("[{$tenantId}] archived_versions_pruned={$pruned}".($dryRun ? ' (dry-run)' : ''));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    private function pruneTenant(string $tenantId, int $keep, bool $dryRun): int
    {
        // Families with MORE than `keep` archived versions.
        $families = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', 'archived')
            ->select('project_key', 'source_path', DB::raw('count(*) as version_count'))
            ->groupBy('project_key', 'source_path')
            ->havingRaw('count(*) > ?', [$keep])
            ->get();

        $pruned = 0;
        foreach ($families as $family) {
            $surplusCount = max(0, (int) $family->version_count - $keep);
            if ($surplusCount === 0) {
                continue;
            }

            // Dry-run reports the full surplus from the grouped count without
            // touching the DB.
            if ($dryRun) {
                $pruned += $surplusCount;
                continue;
            }

            // Loop in batches until the cap is enforced, so a family with
            // more than `keep + batch` archived versions still converges in a
            // single run (a fixed `take(N)` could leave the cap violated until
            // future runs — Copilot review). Each pass skips the newest `keep`
            // and deletes the next-oldest batch; the kept set never moves.
            $batch = 500;
            while (true) {
                $surplusIds = KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->where('status', 'archived')
                    ->where('project_key', $family->project_key)
                    ->where('source_path', $family->source_path)
                    ->orderByDesc('indexed_at')
                    ->orderByDesc('id')
                    ->skip($keep)
                    ->take($batch)
                    ->pluck('id')
                    ->all();

                if ($surplusIds === []) {
                    break;
                }
                $pruned += count($surplusIds);

                // Hard delete (chunks cascade via FK ON DELETE CASCADE).
                KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->whereIn('id', $surplusIds)
                    ->forceDelete();
            }
        }

        return $pruned;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }

        // R30 — DISTINCT tenant_ids with eligible rows. Iterate ONLY the
        // tenants that have archived versions so we don't run empty sweeps
        // for every tenant in the system. Cross-tenant enumeration is
        // intentional here (maintenance CLI needs to discover all tenants);
        // withoutGlobalScopes() makes the bypass explicit and future-safe
        // so a later-added global tenant scope cannot silently narrow this
        // to the current TenantContext. Every subsequent query inside
        // pruneTenant() uses forTenant() for correct per-tenant isolation.
        return KnowledgeDocument::withoutGlobalScopes()
            ->where('status', 'archived')
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
