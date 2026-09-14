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
use Illuminate\Support\Facades\Storage;

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
        $failed = 0;

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $result = $this->pruneTenant($tenantId, $keep, $dryRun, $artifacts);
                $failed += $result['artifacts_failed'] + $result['ocr_failed'];
                $this->info(sprintf(
                    '[%s] archived_versions_pruned=%d artifacts_removed=%d artifacts_absent=%d artifacts_failed=%d ocr_runs_purged=%d ocr_runs_kept=%d ocr_failed=%d%s',
                    $tenantId,
                    $result['pruned'],
                    $result['artifacts_removed'],
                    $result['artifacts_absent'],
                    $result['artifacts_failed'],
                    $result['ocr_purged'],
                    $result['ocr_kept'],
                    $result['ocr_failed'],
                    $dryRun ? ' (dry-run)' : '',
                ));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        // v8.36 / ADR 0030 §3 — the artifact root is shared by every tenant
        // (namespaced by segment), so its sweeps run once, whatever tenants
        // had archived rows: temp leftovers of a dead writer, then artifacts
        // no row references any more (trashed rows included, R2).
        $failed += $this->sweepArtifacts($artifacts, $dryRun);

        // R14 — a refused delete is a failed cleanup, never a clean exit:
        // the rows are gone, the bytes are not, and the operator must know.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{purged: bool, kept: bool, failed: bool}
     */
    private function purgeUnreferencedOcrRun(string $disk, string $prefix, string $sourcePath, string $run): array
    {
        $outcome = ['purged' => false, 'kept' => false, 'failed' => false];
        if (preg_match('/^[a-f0-9]{64}$/', $run) !== 1) {
            return $outcome;
        }

        // The deleter's gate decides (ADR 0030 §8): across tenants and
        // soft-deleted rows, the run directory is shared by every version born
        // from the same bytes IN THIS STORAGE NAMESPACE (disk + prefix +
        // source path) — one definition of "referenced" for the hard delete
        // and for the prune.
        if (app(DocumentDeleter::class)->documentReferencingOcrRun($disk, $prefix, $sourcePath, $run) !== null) {
            return $outcome;
        }
        try {
            if (app(OcrFigureStore::class)->purgeRun($disk, $sourcePath, $prefix, $run)) {
                $outcome['purged'] = true;

                return $outcome;
            }
            // Nothing there, or a run inside the in-flight grace / reserved
            // by a converter: kept, the orphan sweep takes it once aged.
            $outcome['kept'] = true;
        } catch (\Throwable $e) {
            $outcome['failed'] = true;
            $this->error("Could not purge OCR run {$run} beside {$sourcePath} on disk [{$disk}]: {$e->getMessage()}");
        }

        return $outcome;
    }

    /**
     * Sweeps EVERY artifact namespace the corpus records — the configured
     * `(disk, prefix)` and every `(metadata.disk, metadata.prefix)` a row
     * with an artifact pointer persisted, as long as the disk is configured
     * — so a deployment with a second artifact disk (a project or connector
     * disk) does not leak temps and orphans there forever. The summary line
     * aggregates the namespaces; one line per namespace precedes it when
     * there is more than one.
     *
     * @return int failures (temps or orphans the disk refused, or entries refused by the containment check)
     */
    private function sweepArtifacts(ConversionArtifactStore $artifacts, bool $dryRun): int
    {
        $skipped = 0;
        $namespaces = $this->artifactNamespaces($skipped);
        $totals = ['temps' => 0, 'temps_failed' => 0, 'orphans' => 0, 'orphans_failed' => 0];
        foreach ($namespaces as [$disk, $prefix]) {
            $outcome = $this->sweepArtifactNamespace($artifacts, $disk, $prefix, $dryRun);
            if (count($namespaces) > 1) {
                $this->line(sprintf('  [%s%s] temps_swept=%d temps_failed=%d orphans_removed=%d orphans_failed=%d', $disk, $prefix === '' ? '' : ':'.$prefix, $outcome['temps'], $outcome['temps_failed'], $outcome['orphans'], $outcome['orphans_failed']));
            }
            foreach ($outcome as $k => $v) {
                $totals[$k] += $v;
            }
        }
        // `artifact_namespaces_skipped` is additive and printed only when a
        // recorded namespace could not be swept (a disk this deployment cannot
        // resolve): a permanent leak that must be observable in the summary
        // the scheduler logs, not only in a warning line.
        $this->info(sprintf(
            'artifact_temps_swept=%d artifact_temps_failed=%d artifact_orphans_removed=%d artifact_orphans_failed=%d%s%s',
            $totals['temps'],
            $totals['temps_failed'],
            $totals['orphans'],
            $totals['orphans_failed'],
            $skipped > 0 ? " artifact_namespaces_skipped={$skipped}" : '',
            $dryRun ? ' (dry-run)' : '',
        ));

        return $totals['temps_failed'] + $totals['orphans_failed'];
    }

    /**
     * The configured namespace first, then every distinct `(disk, prefix)`
     * recorded on a row that points at an artifact — read in SQL (JSON
     * selectors, portable), never by decoding every row — and whose disk this
     * deployment can resolve (an unknown or unconstructible disk cannot be
     * swept: reported per namespace and counted in `$skipped`).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function artifactNamespaces(int &$skipped): array
    {
        $configuredDisk = (string) config('kb.sources.disk', 'kb');
        $configuredPrefix = (string) config('kb.sources.path_prefix', '');
        $namespaces = [$configuredDisk.'|'.$configuredPrefix => [$configuredDisk, $configuredPrefix]];
        $recorded = KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->whereNotNull('markdown_path')
            ->whereNotNull('metadata->disk')
            ->select(['metadata->disk as artifact_disk', 'metadata->prefix as artifact_prefix'])
            ->distinct()
            ->get();
        foreach ($recorded as $row) {
            $disk = (string) $row->artifact_disk;
            // A JSON `null` prefix is read as the configured one: the JSON
            // selector cannot tell an absent key (configured prefix, as the
            // deleter resolves it) from an explicit null (`''` there); ingest
            // never writes an explicit null, so the two agree in practice.
            $prefix = (string) ($row->artifact_prefix ?? $configuredPrefix);
            if ($disk === '' || isset($namespaces[$disk.'|'.$prefix])) {
                continue;
            }
            try {
                Storage::disk($disk); // resolves configured AND runtime-registered disks; throws for an unknown one
            } catch (\Throwable $e) {
                // Unknown disk (InvalidArgumentException) or an adapter that
                // cannot be constructed here: either way nothing can be swept
                // on it, and the run must not abort after the row prune (R14).
                $this->warn("  ! rows record artifacts on disk [{$disk}], which cannot be resolved here ({$e->getMessage()}): not swept");
                $skipped++;

                continue;
            }
            $namespaces[$disk.'|'.$prefix] = [$disk, $prefix];
        }

        return array_values($namespaces);
    }

    /**
     * @return array{temps: int, temps_failed: int, orphans: int, orphans_failed: int}
     */
    private function sweepArtifactNamespace(ConversionArtifactStore $artifacts, string $disk, string $prefix, bool $dryRun): array
    {
        $maxAge = max(0, (int) config('kb.conversion_artifacts.tmp_max_age_seconds', 3600));

        try {
            $temps = $artifacts->sweepTemps($disk, $prefix, $maxAge, $dryRun);
        } catch (\Throwable $e) {
            // A configured prefix that cannot form an artifact root (R14:
            // reported as a failed sweep, never an unhandled crash).
            $this->error("  ! could not sweep the artifact root on disk [{$disk}]: {$e->getMessage()}");

            return ['temps' => 0, 'temps_failed' => 1, 'orphans' => 0, 'orphans_failed' => 1];
        }
        $orphans = 0;
        $orphansFailed = 0;
        $deleter = app(DocumentDeleter::class);
        try {
            // The listing is lazy (R3): batches of 500 paths are judged and
            // released as the tree is walked, never the whole corpus at once.
            // A refused walk (a symbolic link under the root) ends the sweep
            // where it stands: earlier batches stay swept, the failure is
            // counted and reported, the exit is non-zero (partial, reported).
            $batch = [];
            foreach ($artifacts->listArtifacts($disk, $prefix) as $path) {
                $batch[] = $path;
                if (count($batch) < 500) {
                    continue;
                }
                $this->sweepArtifactBatch($artifacts, $deleter, $disk, $batch, $dryRun, $orphans, $orphansFailed);
                $batch = [];
            }
            if ($batch !== []) {
                $this->sweepArtifactBatch($artifacts, $deleter, $disk, $batch, $dryRun, $orphans, $orphansFailed);
            }
        } catch (\Throwable $e) {
            // The walk is lazy, so the enumeration and the batches share this
            // guard: the class says which one gave up (an adapter refusing a
            // symlink, a DB error in a batch, a disk `throw` on delete).
            $this->error('  ! artifact orphan sweep aborted on disk ['.$disk.'] ('.$e::class."): {$e->getMessage()}");
            $orphansFailed++;
        }

        return ['temps' => $temps['removed'], 'temps_failed' => $temps['failed'], 'orphans' => $orphans, 'orphans_failed' => $orphansFailed];
    }

    /**
     * @param  list<string>  $batch
     */
    private function sweepArtifactBatch(ConversionArtifactStore $artifacts, DocumentDeleter $deleter, string $disk, array $batch, bool $dryRun, int &$orphans, int &$orphansFailed): void
    {
        // Authoritative check first: a path is an orphan only when NO row —
        // live, archived or soft-deleted — points at it ON THIS DISK.
        // Artifact disks are independent storage objects: a row whose
        // recorded disk is another one references another file with the
        // same path, and must neither keep this orphan alive nor be
        // ignored; a row that never recorded its disk references the path
        // wherever the sweep looks (fail closed, as for source files).
        $referenced = [];
        $rows = KnowledgeDocument::withoutGlobalScopes()
            ->whereIn('markdown_path', $batch)
            ->select(['id', 'markdown_path', 'metadata'])
            ->cursor();
        foreach ($rows as $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            if ($deleter->documentRecordsStorageNamespace($row) && (string) $metadata['disk'] !== $disk) {
                continue;
            }
            $referenced[(string) $row->markdown_path] = true;
        }
        foreach ($batch as $path) {
            if (isset($referenced[$path])) {
                continue;
            }
            if ($dryRun) {
                $orphans++;
                continue;
            }
            $removal = $artifacts->remove($disk, $path);
            if ($removal === ConversionArtifactStore::FAILED) {
                $orphansFailed++;
                $this->error("  ! could not remove orphan artifact [{$disk}] {$path}");
                continue;
            }
            $orphans++;
        }
    }

    /**
     * @return array{pruned: int, artifacts_removed: int, artifacts_absent: int, artifacts_failed: int, ocr_purged: int, ocr_kept: int, ocr_failed: int}
     */
    private function pruneTenant(string $tenantId, int $keep, bool $dryRun, ConversionArtifactStore $artifacts): array
    {
        $result = ['pruned' => 0, 'artifacts_removed' => 0, 'artifacts_absent' => 0, 'artifacts_failed' => 0, 'ocr_purged' => 0, 'ocr_kept' => 0, 'ocr_failed' => 0];
        $deleter = app(DocumentDeleter::class);
        // Families with MORE than `keep` archived versions.
        $families = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', 'archived')
            ->select('project_key', 'source_path', DB::raw('count(*) as version_count'))
            ->groupBy('project_key', 'source_path')
            ->havingRaw('count(*) > ?', [$keep])
            // Streamed (R3): families are HYDRATED one at a time (the pgsql
            // driver still buffers the grouped result set client-side, so
            // this bounds model memory, not the result set). A cursor is a
            // single query, so the groups that vanish as their surplus is
            // pruned never shift a page the way an offset-based chunk would;
            // deleting from the same table inside the loop is safe because
            // the grouped result is computed before the first row is read.
            ->cursor();

        foreach ($families as $family) {
            $surplusCount = max(0, (int) $family->version_count - $keep);
            if ($surplusCount === 0) {
                continue;
            }

            // Dry-run reports the full surplus from the grouped count without
            // touching the DB.
            if ($dryRun) {
                $result['pruned'] += $surplusCount;
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
                    ->get();
                if ($surplus->isEmpty()) {
                    break;
                }
                $result['pruned'] += $surplus->count();
                // Hard delete through the deleter's row path — chunks, the
                // graph nodes an archived canonical version may still own,
                // and the deprecation audit row, in one transaction per row
                // — the same cascade every other hard delete takes; the
                // source file is shared with the live version and is never
                // touched here.
                $runsToCheck = [];
                foreach ($surplus as $row) {
                    $deleter->deleteRowsOnly($row, removeArtifact: false);
                    // v8.36 / ADR 0030 §8 — the artifact goes with the row it
                    // belongs to; a refused delete is counted and reported
                    // (R14), an already-missing file is simply absent.
                    $metadata = is_array($row->metadata) ? $row->metadata : [];
                    $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
                    $prefix = array_key_exists('prefix', $metadata)
                        ? (string) $metadata['prefix']
                        : (string) config('kb.sources.path_prefix', '');
                    if (is_string($row->markdown_path) && $row->markdown_path !== '') {
                        $removal = $artifacts->remove($disk, $row->markdown_path);
                        $result['artifacts_'.$removal]++;
                        if ($removal === ConversionArtifactStore::FAILED) {
                            $this->error("  ! could not remove the artifact of pruned version #{$row->id} [{$disk}] {$row->markdown_path}");
                        }
                    }
                    // …and so does the row's recorded OCR run — but only when
                    // no remaining row of ANY tenant sharing the same run
                    // directory (disk + prefix + source path) still references
                    // it; the `.ocr/` tree as a whole still goes with the last
                    // referencing row of the source.
                    $run = $metadata['converter']['ocr']['run'] ?? null;
                    if (is_string($run)) {
                        // One family scan per distinct run DIRECTORY, not per pruned row (R3).
                        $runsToCheck[$disk.'|'.$prefix.'|'.$run] = [$disk, $prefix, $run];
                    }
                }
                foreach ($runsToCheck as [$disk, $prefix, $run]) {
                    $outcome = $this->purgeUnreferencedOcrRun($disk, $prefix, (string) $family->source_path, $run);
                    $result['ocr_purged'] += $outcome['purged'] ? 1 : 0;
                    $result['ocr_kept'] += $outcome['kept'] ? 1 : 0;
                    $result['ocr_failed'] += $outcome['failed'] ? 1 : 0;
                }
            }
        }

        return $result;
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
