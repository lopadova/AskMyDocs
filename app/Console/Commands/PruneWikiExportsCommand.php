<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbWikiExportRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * v8.38/W4b — purge expired async wiki export bundles (ADR 0032 §12).
 *
 * Deliberately its OWN sweep, not `kb:prune-staging-batches`: the two
 * retention windows govern different artifacts with different lifetimes
 * and must not be collapsed into one knob that would either purge an
 * in-flight upload early or keep a stale export bundle around past its
 * stated retention. Sweeps by `expires_at` (not `created_at` + a status
 * list, like staging) — a `queued`/`processing` row has no `expires_at`
 * yet and is never touched by this command regardless of age; only a
 * `completed` (or already `expired`) row with a past `expires_at` is stale.
 *
 * Cross-tenant CLI sweep: deliberately NOT tenant-scoped, same rationale as
 * `PruneStagingBatchesCommand` — a maintenance sweep is the one place a
 * tenant scope is intentionally absent (R30 reviewer note).
 *
 * R3: chunkById(100) for memory safety. R4: `deleteDirectory`/`delete`
 * return values checked before the row is removed.
 */
class PruneWikiExportsCommand extends Command
{
    protected $signature = 'kb:prune-wiki-exports {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Purge expired async portable-wiki export bundles past their retention window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $deleted = 0;
        $failed = 0;

        KbWikiExportRequest::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->where('status', '!=', KbWikiExportRequest::STATUS_EXPIRED)
            ->chunkById(100, function ($exports) use ($dryRun, $now, &$deleted, &$failed): void {
                foreach ($exports as $export) {
                    if ($dryRun) {
                        $this->line("[dry-run] would delete export {$export->id} (tenant={$export->tenant_id}, expired ".$export->expires_at->diffForHumans($now).').');
                        $deleted++;

                        continue;
                    }

                    if ($export->storage_disk !== null && $export->storage_path !== null) {
                        $disk = Storage::disk($export->storage_disk);
                        // R4 — a false return means the bundle lingered; mark
                        // the row `expired` (so it stops appearing as
                        // downloadable / stops being re-swept every run) but
                        // leave it for operator follow-up rather than
                        // silently losing track of the orphaned file.
                        if ($disk->exists($export->storage_path) && $disk->delete($export->storage_path) === false) {
                            $this->warn("Could not delete export bundle for {$export->id}; marking expired and leaving the row.");
                            $export->forceFill(['status' => KbWikiExportRequest::STATUS_EXPIRED])->save();
                            $failed++;

                            continue;
                        }
                    }

                    $export->delete();
                    $deleted++;
                }
            });

        $summary = $dryRun
            ? "Would delete {$deleted} expired export bundle(s)."
            : "Deleted {$deleted} expired export bundle(s)".($failed > 0 ? " ({$failed} left as expired after a delete failure)" : '').'.';
        $this->info($summary);

        return self::SUCCESS;
    }
}
