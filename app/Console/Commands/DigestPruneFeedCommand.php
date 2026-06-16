<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EngagementDigestFeedEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * v8.15/W3 — retention sweep for the in-app digest feed.
 *
 * Hard-deletes `engagement_digest_feed` rows older than the retention window
 * (`kb.digest.feed_retention_days`, `--days` override; 0 disables). Same posture
 * as the other retention sweeps (kb:prune-deleted / chat-log:prune): an
 * instance-wide maintenance job, memory-safe via chunked deletes.
 */
final class DigestPruneFeedCommand extends Command
{
    protected $signature = 'digest:prune-feed
        {--days= : Override kb.digest.feed_retention_days (0 disables)}
        {--dry-run : Count rows without deleting}';

    protected $description = 'Hard-delete in-app digest feed entries older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('kb.digest.feed_retention_days', 120);

        if ($days <= 0) {
            $this->info('Digest feed retention disabled (days <= 0). Nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);

        $query = EngagementDigestFeedEntry::query()->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would prune {$query->count()} digest feed entr(ies) older than {$days} day(s).");

            return self::SUCCESS;
        }

        $deleted = 0;
        // Memory-safe chunked delete (R3): bound each DELETE to 1000 ids.
        do {
            $ids = (clone $query)->limit(1000)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $deleted += EngagementDigestFeedEntry::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === 1000);

        $this->info("Pruned {$deleted} digest feed entr(ies) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
