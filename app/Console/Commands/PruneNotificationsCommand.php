<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationEvent;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.0/W1.5 — rotate the `notification_events` table.
 *
 * `notification_events` accumulates one row per (user × dispatched
 * event), including the per-channel `channel_dispatch_log` payload.
 * On a busy tenant the table can reach hundreds of thousands of rows
 * in a few months, so the host MUST run this cron daily to keep the
 * working set bounded.
 *
 * Retention applies to ALL rows (read / unread / dismissed) older
 * than the cutoff. The bell + panel already let users mark or
 * dismiss within the retention window; anything older than 90 days
 * by default has lost operational value (forensic delivery is the
 * job of audit logs, not user-facing notification rows). Operators
 * who need a longer window override via `NOTIFICATIONS_RETENTION_DAYS`.
 *
 * Set `NOTIFICATIONS_RETENTION_DAYS=0` to disable rotation entirely
 * (the command short-circuits with a warning).
 *
 * R30 — `notification_events` is a tenant-aware table, so the
 * command iterates DISTINCT tenant_ids and prunes per tenant rather
 * than firing a single global DELETE. Per-tenant iteration:
 *   - keeps R30's tenant boundary explicit at the SQL level (every
 *     DELETE carries a `WHERE tenant_id = ?` predicate);
 *   - lets operators read per-tenant deletion counts in the output,
 *     which makes operational forensics (e.g. "tenant X exploded
 *     last week, prune deleted 50k rows") tractable without parsing
 *     the database directly;
 *   - dovetails with W4's Tier-2 per-tenant scheduler overrides
 *     (`tenant_scheduler_overrides`) — the per-tenant loop is the
 *     natural integration point when individual tenants want to
 *     run prune off-schedule or skip it entirely.
 *
 * R3: `chunkById(100)` with `delete()` per row keeps memory bounded
 * on multi-100k tables. The query pushes the cutoff into SQL so the
 * filter never materialises in PHP.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune
                            {--days= : Override NOTIFICATIONS_RETENTION_DAYS}
                            {--tenant= : Restrict to a single tenant_id (default: every tenant with eligible rows)}';

    protected $description = 'Hard-delete notification_events rows older than --days days, per tenant.';

    public function handle(TenantContext $context): int
    {
        $days = (int) ($this->option('days') ?? config('askmydocs.notifications.retention_days', 90));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping notification rotation.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $cutoffIso = $cutoff->toIso8601String();
        $tenantIds = $this->resolveTenantIds($cutoff);

        if ($tenantIds === []) {
            $this->info("No tenants have notification_events older than the cutoff ({$cutoffIso}). Nothing to do.");

            return self::SUCCESS;
        }

        $previousTenant = $context->current();
        $totalDeleted = 0;

        try {
            foreach ($tenantIds as $tenantId) {
                $context->set($tenantId);
                $deletedForTenant = 0;

                NotificationEvent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('created_at', '<', $cutoff)
                    ->chunkById(100, function ($rows) use (&$deletedForTenant, $tenantId) {
                        $ids = $rows->pluck('id')->all();
                        if ($ids === []) {
                            return;
                        }
                        // Copilot iter-2 #1 — R30 strict reading. The
                        // outer SELECT carries the tenant predicate so
                        // every loaded row IS owned by $tenantId, BUT
                        // `$row->delete()` would issue
                        // `DELETE WHERE id = ?` with no tenant column
                        // in the SQL trace. Re-stating the predicate
                        // on the bulk DELETE keeps the boundary
                        // explicit at the SQL layer (audit-friendly)
                        // and collapses the chunk's N statements into
                        // one — same memory budget, fewer round-trips.
                        $deletedForTenant += NotificationEvent::query()
                            ->where('tenant_id', $tenantId)
                            ->whereIn('id', $ids)
                            ->delete();
                    });

                $this->info("[{$tenantId}] Deleted {$deletedForTenant} notification_events rows older than {$days} days.");
                $totalDeleted += $deletedForTenant;
            }
        } finally {
            $context->set($previousTenant);
        }

        $this->info("Total deleted: {$totalDeleted} row(s) across ".count($tenantIds).' tenant(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(\DateTimeInterface $cutoff): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }

        return NotificationEvent::query()
            ->where('created_at', '<', $cutoff)
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
