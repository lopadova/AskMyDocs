<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbEngagementSnapshot;
use App\Models\KnowledgeDocument;
use App\Services\Engagement\EngagementMetricsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * v8.15/W1 — compute the daily engagement snapshot, one row PER TENANT.
 *
 * Mirrors {@see InsightsComputeCommand} / {@see KbHealthRecomputeCommand}: it
 * iterates the tenant set, sets {@see TenantContext} so {@see EngagementMetricsService}
 * scopes every aggregation to that tenant (R30), and upserts one snapshot per
 * (tenant, date). `--force` replaces an existing row; otherwise reruns no-op.
 */
class EngagementComputeCommand extends Command
{
    protected $signature = 'engagement:compute
        {--date=today : Target snapshot_date (YYYY-MM-DD or "today")}
        {--tenant= : Restrict the compute to a single tenant}
        {--days=7 : Rolling window (days) for activity metrics}
        {--force : Replace an existing row for the target date}';

    protected $description = 'Compute the daily KB engagement snapshot (contributors / activity / coverage / health / leaderboard), one row PER TENANT.';

    public function handle(EngagementMetricsService $metrics, TenantContext $tenants): int
    {
        $targetDate = $this->resolveDate();
        if ($targetDate === null) {
            $this->error('Invalid --date value. Use YYYY-MM-DD or "today".');

            return self::FAILURE;
        }

        $windowDays = max(1, (int) $this->option('days'));
        $dateString = $targetDate->toDateString();
        $previousTenant = $tenants->current();

        try {
            foreach ($this->resolveTenantIds() as $tenantId) {
                $tenants->set($tenantId);

                $existing = KbEngagementSnapshot::query()
                    ->forTenant($tenantId)
                    ->whereDate('snapshot_date', $dateString)
                    ->first();
                if ($existing !== null && ! $this->option('force')) {
                    $this->warn("[{$tenantId}] engagement snapshot for {$dateString} already exists. Use --force to replace.");

                    continue;
                }

                $startedAt = microtime(true);
                $payload = $metrics->snapshotMetrics($windowDays);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $attributes = [
                    'metrics' => $payload,
                    'computed_at' => Carbon::now(),
                    'computed_duration_ms' => $durationMs,
                ];

                if ($existing !== null) {
                    $existing->update($attributes);
                } else {
                    KbEngagementSnapshot::create(array_merge(['snapshot_date' => $dateString], $attributes));
                }

                $this->info("[{$tenantId}] engagement snapshot for {$dateString} written in {$durationMs} ms.");
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * Tenants to compute for. Explicit --tenant wins; otherwise every tenant
     * owning at least one document; falls back to the active tenant on a fresh
     * install. The discovery query is intentionally unscoped (its job is to find
     * the tenant set); the per-tenant compute is forTenant-scoped.
     *
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = trim((string) ($this->option('tenant') ?? ''));
        if ($explicit !== '') {
            return [$explicit];
        }

        $tenantIds = KnowledgeDocument::query()
            ->withTrashed()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();

        return $tenantIds === [] ? [app(TenantContext::class)->current()] : $tenantIds;
    }

    private function resolveDate(): ?Carbon
    {
        $raw = trim((string) $this->option('date'));
        if ($raw === '' || $raw === 'today') {
            return Carbon::today();
        }
        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
