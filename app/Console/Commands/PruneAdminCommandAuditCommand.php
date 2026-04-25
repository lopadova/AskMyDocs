<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminCommandAudit;
use Illuminate\Console\Command;

/**
 * Phase H2 — rotate the admin_command_audit forensic table.
 *
 * Audit rows are immutable BUT not eternal — the default 1-year
 * retention lines up with most EU/US compliance windows for ops
 * records. Operators who need a longer retention should set
 * ADMIN_AUDIT_RETENTION_DAYS to a larger value via env.
 *
 * R3: chunkById(100) + push filter into SQL. A multi-year audit
 * table could easily be ≥100k rows on a busy cluster.
 */
class PruneAdminCommandAuditCommand extends Command
{
    protected $signature = 'admin-audit:prune {--days= : Override ADMIN_AUDIT_RETENTION_DAYS}';

    protected $description = 'Hard-delete admin_command_audit rows older than --days days.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('admin.command_runner.audit_retention_days', 365));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping rotation.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        AdminCommandAudit::query()
            ->where('started_at', '<', $cutoff)
            ->chunkById(100, function ($rows) use (&$totalDeleted) {
                foreach ($rows as $row) {
                    $row->delete();
                    $totalDeleted++;
                }
            });

        $this->info("Deleted {$totalDeleted} admin_command_audit rows older than {$days} days (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
