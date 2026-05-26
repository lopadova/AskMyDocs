<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminInsightsSnapshot;
use App\Models\KnowledgeDocument;
use App\Services\Admin\AiInsightsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase I — compute the daily AI insights snapshot.
 *
 * Executes the six AiInsightsService functions, times each, and writes
 * ONE row keyed on calendar day. Partial-failure strategy: any single
 * function that throws is logged + its column is null'd; the other
 * columns are still populated. The snapshot is NEVER partially
 * persisted — it's upserted once at the end.
 *
 * `--force` replaces an existing row for `snapshot_date`. Without
 * `--force` the command no-ops when a row already exists (idempotent
 * scheduler reruns).
 */
class InsightsComputeCommand extends Command
{
    protected $signature = 'insights:compute
        {--date=today : Target snapshot_date (YYYY-MM-DD or "today")}
        {--tenant= : Restrict the compute to a single tenant}
        {--force : Replace an existing row for the target date}';

    protected $description = 'Compute the daily AI insights snapshot (promotions / orphans / tags / gaps / stale / quality), one row PER TENANT.';

    public function handle(AiInsightsService $insights, TenantContext $tenants): int
    {
        $targetDate = $this->resolveDate();
        if ($targetDate === null) {
            $this->error('Invalid --date value. Use YYYY-MM-DD or "today".');

            return self::FAILURE;
        }

        // R30/CRITICAL-3 — compute ONE snapshot per tenant. Previously the
        // command ran AiInsightsService once with no tenant context, writing
        // a single snapshot whose CONTENT aggregated every tenant's data
        // (cross-tenant leak) under tenant_id='default'. Now we iterate the
        // tenants and set TenantContext so each tenant's snapshot only
        // contains its own data (AiInsightsService scopes by the active
        // tenant). Mirrors KbHealthRecomputeCommand.
        $previousTenant = $tenants->current();
        $dateString = $targetDate->toDateString();

        try {
            foreach ($this->resolveTenantIds() as $tenantId) {
                $tenants->set($tenantId);

                $existing = AdminInsightsSnapshot::query()
                    ->forTenant($tenantId)
                    ->whereDate('snapshot_date', $dateString)
                    ->first();
                if ($existing !== null && ! $this->option('force')) {
                    $this->warn("[{$tenantId}] snapshot for {$dateString} already exists. Use --force to replace.");

                    continue;
                }

                $startedAt = microtime(true);
                $payloads = [
                    'suggest_promotions' => $this->runInsight('suggest_promotions', fn () => $insights->suggestPromotions()),
                    'orphan_docs' => $this->runInsight('orphan_docs', fn () => $insights->detectOrphans()),
                    'suggested_tags' => $this->runInsight('suggested_tags', fn () => $insights->suggestTagsBatch()),
                    'coverage_gaps' => $this->runInsight('coverage_gaps', fn () => $insights->coverageGaps()),
                    'stale_docs' => $this->runInsight('stale_docs', fn () => $insights->detectStaleDocs()),
                    'quality_report' => $this->runInsight('quality_report', fn () => $insights->qualityReport()),
                ];
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $attributes = array_merge($payloads, [
                    'computed_at' => Carbon::now(),
                    'computed_duration_ms' => $durationMs,
                ]);

                // Upsert scoped to the tenant + date. `existing` was fetched
                // forTenant above, so we never collide with another tenant's
                // same-date row (the composite unique is (tenant_id, snapshot_date)).
                if ($existing !== null) {
                    $existing->update($attributes);
                } else {
                    // BelongsToTenant auto-fills tenant_id from the active
                    // TenantContext (set to $tenantId above) on create.
                    AdminInsightsSnapshot::create(array_merge(
                        ['snapshot_date' => $dateString],
                        $attributes,
                    ));
                }

                $this->info("[{$tenantId}] insights snapshot for {$dateString} written in {$durationMs} ms.");
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * Tenants to compute snapshots for. Explicit --tenant wins; otherwise
     * every tenant that owns at least one knowledge document. Falls back to
     * the active tenant (typically 'default') on a fresh install with no
     * documents, so single-tenant deployments still get a daily snapshot.
     *
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = trim((string) ($this->option('tenant') ?? ''));
        if ($explicit !== '') {
            return [$explicit];
        }

        // Tenant-enumeration query: intentionally unscoped (its whole job is
        // to DISCOVER the tenant set). The per-tenant compute + snapshot
        // upsert below ARE forTenant-scoped, so TenantReadScopeTest passes
        // this file on the forTenant marker (no allowlist entry needed).
        $tenantIds = KnowledgeDocument::query()
            ->withTrashed()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();

        return $tenantIds === [] ? [app(TenantContext::class)->current()] : $tenantIds;
    }

    /**
     * Invoke one insight function, catch anything it throws (LLM
     * timeouts, network failures, provider quota), and return null for
     * that column. Returning null is the contract with the migration:
     * every payload column is independently nullable so a single
     * failure does not sink the snapshot.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    private function runInsight(string $name, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            // WARNING-level: the snapshot still completes, but operators
            // need to know which column dropped so they can investigate.
            Log::warning("InsightsComputeCommand: {$name} failed, column will be null.", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->warn("{$name}: failed — column left null ({$e->getMessage()})");

            return null;
        }
    }

    private function resolveDate(): ?Carbon
    {
        $raw = (string) $this->option('date');
        $raw = trim($raw);
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
