<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminCommandNonce;
use Illuminate\Console\Command;

/**
 * Phase H2 — purge expired/used confirm-token nonces.
 *
 * Nonces have a 5-minute TTL and are single-use. Rows are useful for
 * forensic correlation only while they're referenced by a live or
 * recently-failed /run attempt. One-day retention of expired/used
 * rows is plenty for debugging without growing the table forever.
 *
 * R3: chunkById(100) for memory safety on busy clusters.
 */
class PruneAdminCommandNoncesCommand extends Command
{
    protected $signature = 'admin-nonces:prune {--days= : Override ADMIN_NONCE_RETENTION_DAYS}';

    protected $description = 'Purge admin_command_nonces rows past the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('admin.command_runner.nonce_retention_days', 1));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping rotation.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        // We prune EITHER expired rows past the cutoff OR used rows
        // past the cutoff — a used but recent row is still evidence
        // for the corresponding audit entry so we leave it alone.
        AdminCommandNonce::query()
            ->where(function ($q) use ($cutoff) {
                $q->where('expires_at', '<', $cutoff)
                    ->orWhere(function ($qq) use ($cutoff) {
                        $qq->whereNotNull('used_at')->where('used_at', '<', $cutoff);
                    });
            })
            ->chunkById(100, function ($rows) use (&$totalDeleted) {
                foreach ($rows as $row) {
                    $row->delete();
                    $totalDeleted++;
                }
            });

        $this->info("Deleted {$totalDeleted} admin_command_nonces rows older than {$days} days (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
