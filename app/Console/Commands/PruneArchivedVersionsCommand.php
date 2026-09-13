<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Versioning\ConversionArtifactStore;
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

    public function handle(TenantContext $tenants, ConversionArtifactStore $artifacts): int
    {
        $keep = max(0, (int) ($this->option('keep') ?? config('kb.versioning.keep_archived', 10)));
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No archived versions to prune; running the artifact sweeps.');
        }

        $previousTenant = $tenants->current();

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $pruned = $this->pruneTenant($tenantId, $keep, $dryRun, $artifacts);
                $this->info("[{$tenantId}] archived_versions_pruned={$pruned}".($dryRun ? ' (dry-run)' : ''));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        // v8.36 / ADR 0030 §3 — the artifact root is shared by every tenant
        // (namespaced by segment), so its sweeps run once, whatever tenants
        // had archived rows: temp leftovers of a dead writer, then artifacts
        // no row references any more (trashed rows included, R2).
        $this->sweepArtifacts($artifacts, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $metadata  the pruned row's metadata
     */
    private function purgeUnreferencedOcrRun(string $sourcePath, string $run, array $metadata, string $disk): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $run) !== 1) {
            return;
        }
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');

        // The deleter's gate decides (ADR 0030 §8): across tenants and
        // soft-deleted rows, the run directory is shared by every version born
        // from the same bytes — one definition of "referenced" for the hard
        // delete and for the prune.
        if (app(DocumentDeleter::class)->documentReferencingOcrRun($sourcePath, $run) !== null) {
            return;
        }
        try {
            app(OcrFigureStore::class)->purgeRun($disk, $sourcePath, $prefix, $run);
        } catch (\Throwable $e) {
            $this->warn("Could not purge OCR run {$run} beside {$sourcePath}: {$e->getMessage()}");
        }
    }

    private function sweepArtifacts(ConversionArtifactStore $artifacts, bool $dryRun): void
    {
        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = (string) config('kb.sources.path_prefix', '');
        $maxAge = max(0, (int) config('kb.conversion_artifacts.tmp_max_age_seconds', 3600));

        $temps = $artifacts->sweepTemps($disk, $prefix, $maxAge, $dryRun);
        $orphans = 0;
        foreach (array_chunk($artifacts->listArtifacts($disk, $prefix), 500) as $batch) {
            // Authoritative check first: a path is an orphan only when NO row —
            // live, archived or soft-deleted — points at it.
            $referenced = KnowledgeDocument::withoutGlobalScopes()
                ->whereIn('markdown_path', $batch)
                ->pluck('markdown_path')
                ->flip();
            foreach ($batch as $path) {
                if ($referenced->has($path)) {
                    continue;
                }
                if ($dryRun || $artifacts->delete($disk, $path)) {
                    $orphans++;
                }
            }
        }
        $this->info("artifact_temps_swept={$temps} artifact_orphans_removed={$orphans}".($dryRun ? ' (dry-run)' : ''));
    }

    private function pruneTenant(string $tenantId, int $keep, bool $dryRun, ConversionArtifactStore $artifacts): int
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
                $surplus = KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->where('status', 'archived')
                    ->where('project_key', $family->project_key)
                    ->where('source_path', $family->source_path)
                    ->orderByDesc('indexed_at')
                    ->orderByDesc('id')
                    ->skip($keep)
                    ->take($batch)
                    ->get(['id', 'markdown_path', 'metadata']);
                if ($surplus->isEmpty()) {
                    break;
                }
                $surplusIds = $surplus->pluck('id')->all();
                $pruned += count($surplusIds);
                // Hard delete (chunks cascade via FK ON DELETE CASCADE).
                KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->whereIn('id', $surplusIds)
                    ->forceDelete();
                // v8.36 / ADR 0030 §8 — the artifact goes with the row it
                // belongs to (R4: the store logs a refused delete, never silent),
                // and so does the row's recorded OCR run — but only when no
                // remaining row of ANY tenant sharing the source key still
                // references that run (the prune bypasses DocumentDeleter, so
                // it carries this cleanup itself; the `.ocr/` tree as a whole
                // still goes with the last referencing row of the source).
                $runsToCheck = [];
                foreach ($surplus as $row) {
                    $metadata = is_array($row->metadata) ? $row->metadata : [];
                    $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
                    if (is_string($row->markdown_path) && $row->markdown_path !== '') {
                        $artifacts->delete($disk, $row->markdown_path);
                    }
                    $run = $metadata['converter']['ocr']['run'] ?? null;
                    if (is_string($run)) {
                        // One family scan per distinct run, not per pruned row (R3).
                        $runsToCheck[$disk.'|'.$run] = [$disk, $run, $metadata];
                    }
                }
                foreach ($runsToCheck as [$disk, $run, $metadata]) {
                    $this->purgeUnreferencedOcrRun((string) $family->source_path, $run, $metadata, $disk);
                }
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
