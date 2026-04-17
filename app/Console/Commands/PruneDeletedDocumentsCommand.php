<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentDeleter;
use Illuminate\Console\Command;

/**
 * Scheduled command that hard-deletes soft-deleted documents (and their
 * original files on the KB disk) when their deleted_at is older than
 * KB_SOFT_DELETE_RETENTION_DAYS. Skipped when retention is 0 or negative.
 */
class PruneDeletedDocumentsCommand extends Command
{
    protected $signature = 'kb:prune-deleted {--days= : Override KB_SOFT_DELETE_RETENTION_DAYS}';

    protected $description = 'Hard-delete soft-deleted knowledge documents (and their original files) older than N days.';

    public function handle(DocumentDeleter $deleter): int
    {
        $days = (int) ($this->option('days') ?? config('kb.deletion.retention_days', 30));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = $deleter->pruneSoftDeleted($cutoff);

        $this->info("Pruned {$deleted} soft-deleted document(s) older than {$days} days (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
