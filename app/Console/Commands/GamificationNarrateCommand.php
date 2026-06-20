<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Engagement\GamificationInsightsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.18/W4 — compute + narrate the AI gamification insights, one set of rows PER
 * TENANT (user / project / tenant scopes), for a period (default current ISO week).
 *
 * Mirrors {@see EngagementComputeCommand}: iterates the tenant set, sets
 * {@see TenantContext} so every aggregation + the persisted snapshot are scoped
 * to that tenant (R30), and delegates all logic to
 * {@see GamificationInsightsService} (R44 — one core, thin command). No-op per
 * tenant when `kb.gamification.enabled` is off (R43).
 */
class GamificationNarrateCommand extends Command
{
    protected $signature = 'gamification:narrate
        {--tenant= : Restrict the run to a single tenant}
        {--period= : Period label (e.g. 2026-W25); defaults to the current ISO week}';

    protected $description = 'Compute curation-quality metrics + AI coaching narratives for users / projects / the tenant, persisted per tenant.';

    public function handle(GamificationInsightsService $insights, TenantContext $tenants): int
    {
        if (! $insights->enabled()) {
            $this->warn('Gamification is disabled (kb.gamification.enabled=false); nothing to narrate.');

            return self::SUCCESS;
        }

        $period = trim((string) ($this->option('period') ?? '')) ?: null;
        $previousTenant = $tenants->current();

        try {
            foreach ($this->resolveTenantIds() as $tenantId) {
                $tenants->set($tenantId);
                $result = $insights->recomputeForTenant($period);
                $this->info(sprintf(
                    '[%s] gamification insights for %s: %d user, %d project, %d tenant.',
                    $tenantId,
                    $result['period'],
                    $result['users'],
                    $result['projects'],
                    $result['tenant'],
                ));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * Tenants to narrate for. Explicit --tenant wins; otherwise every tenant
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
}
