<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbIngestBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge stale UI-upload batches and their staged files.
 *
 * A batch is stale once it is past `KB_STAGING_RETENTION_HOURS` AND in a state
 * where its staged copies are dead weight: still `staged` (abandoned before
 * commit), `cancelled`/`expired`, or already `completed` /
 * `completed_with_errors` (the bytes were moved to the kb disk on commit, so
 * the staging copy is redundant).
 *
 * Cross-tenant CLI sweep: deliberately NOT tenant-scoped — it runs under the
 * default tenant from the scheduler and partitions naturally by the
 * {tenant}/{batch} staging path + the FK cascade on items (R30 reviewer note:
 * a maintenance sweep is the one place a tenant scope is intentionally absent).
 *
 * R3: chunkById(100) for memory safety. R4: deleteDirectory return checked.
 */
class PruneStagingBatchesCommand extends Command
{
    protected $signature = 'kb:prune-staging-batches {--hours= : Override KB_STAGING_RETENTION_HOURS}';

    protected $description = 'Purge stale KB upload staging batches and their staged files past the retention window.';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('kb.staging.retention_hours', 24));

        if ($hours <= 0) {
            $this->warn('Retention is 0 or negative — skipping rotation.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours);
        $disk = Storage::disk((string) config('kb.staging.disk', 'kb-staging'));
        $deleted = 0;

        KbIngestBatch::query()
            ->whereIn('status', [
                KbIngestBatch::STATUS_STAGED,
                KbIngestBatch::STATUS_CANCELLED,
                KbIngestBatch::STATUS_EXPIRED,
                KbIngestBatch::STATUS_COMPLETED,
                KbIngestBatch::STATUS_COMPLETED_WITH_ERRORS,
            ])
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($batches) use ($disk, &$deleted): void {
                foreach ($batches as $batch) {
                    $dir = "{$batch->tenant_id}/{$batch->id}";
                    // R4 — a false return means the staged dir lingered; log and
                    // continue rather than abort the whole sweep.
                    if ($disk->exists($dir) && $disk->deleteDirectory($dir) === false) {
                        $this->warn("Could not delete staging dir for batch {$batch->id}; leaving row for next sweep.");

                        continue;
                    }

                    $batch->delete(); // items cascade via FK
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} stale upload batch(es) older than {$hours}h (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
