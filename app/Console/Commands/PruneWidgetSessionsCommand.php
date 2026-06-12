<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WidgetSession;
use App\Models\WidgetSessionToken;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * M5.10 — Hard-delete widget sessions (and their cascade-deleted steps)
 * older than a configurable retention period.
 *
 * Config key: `widget.session_retention_days` (default 90).
 * Override via --days; set to 0 to disable.
 *
 * R31: widget_sessions is tenant-aware, so the command iterates DISTINCT
 * tenant_ids and prunes per tenant.
 *
 * R3: `chunkById(100)` keeps memory bounded on large tables. Each chunk
 * collects IDs then issues a single bulk DELETE with the tenant predicate
 * re-stated (audit-friendly, matches the pattern in PruneNotificationsCommand).
 * Steps are cascade-deleted at the DB level (FK on widget_session_steps),
 * so only widget_sessions need explicit deletion.
 */
class PruneWidgetSessionsCommand extends Command
{
    protected $signature = 'widget:prune-sessions
                            {--days= : Override widget.session_retention_days}
                            {--tenant= : Restrict to a single tenant_id (default: every tenant with eligible rows)}';

    protected $description = 'Hard-delete widget sessions (and steps) older than --days days, per tenant.';

    public function handle(TenantContext $context): int
    {
        $days = (int) ($this->option('days') ?? config('widget.session_retention_days', 90));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping widget session rotation.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $cutoffIso = $cutoff->toIso8601String();

        // #29 — prune dei session token SCADUTI a prescindere dalle sessioni: i
        // token senza widget_session_id non cascadano da nessuna sessione e, non
        // pruniti, crescerebbero illimitatamente (uno per boot del widget). Una
        // volta scaduti (TTL ~30min) sono inutilizzabili. Pass chunked (R3).
        $expiredTokens = $this->pruneExpiredTokens();
        if ($expiredTokens > 0) {
            $this->info("Deleted {$expiredTokens} expired widget session token(s).");
        }

        $tenantIds = $this->resolveTenantIds($cutoff);

        if ($tenantIds === []) {
            $this->info("No tenants have widget_sessions older than the cutoff ({$cutoffIso}). Nothing to do.");

            return self::SUCCESS;
        }

        $previousTenant = $context->current();
        $totalDeleted = 0;

        try {
            foreach ($tenantIds as $tenantId) {
                $context->set($tenantId);
                $deletedForTenant = 0;

                WidgetSession::query()
                    ->where('tenant_id', $tenantId)
                    ->where('created_at', '<', $cutoff)
                    ->chunkById(100, function ($rows) use (&$deletedForTenant, $tenantId) {
                        $ids = $rows->pluck('id')->all();
                        if ($ids === []) {
                            return;
                        }
                        // Re-state tenant_id on the bulk DELETE for audit-friendliness
                        // and to collapse N statements into one (same pattern as
                        // PruneNotificationsCommand). Steps are cascade-deleted
                        // by the FK constraint on widget_session_steps.
                        $deletedForTenant += WidgetSession::query()
                            ->where('tenant_id', $tenantId)
                            ->whereIn('id', $ids)
                            ->delete();
                    });

                $this->info("[{$tenantId}] Deleted {$deletedForTenant} widget_sessions rows older than {$days} days.");
                $totalDeleted += $deletedForTenant;
            }
        } finally {
            $context->set($previousTenant);
        }

        $this->info("Total deleted: {$totalDeleted} row(s) across ".count($tenantIds).' tenant(s).');

        return self::SUCCESS;
    }

    /**
     * #29 — Elimina i session token scaduti (chunked, R3). Cross-tenant per
     * design: i token sono short-lived e una volta scaduti sono inutilizzabili.
     */
    private function pruneExpiredTokens(): int
    {
        $deleted = 0;

        WidgetSessionToken::query()
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($rows) use (&$deleted): void {
                $ids = $rows->pluck('id')->all();
                if ($ids !== []) {
                    $deleted += WidgetSessionToken::query()->whereIn('id', $ids)->delete();
                }
            });

        return $deleted;
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

        return WidgetSession::query()
            ->where('created_at', '<', $cutoff)
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}