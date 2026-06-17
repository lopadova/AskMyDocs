<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbContributionEvent;
use App\Services\Engagement\GamificationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.15/W5 — award gamification badges across all contributors, per tenant.
 *
 * No-op when gamification is disabled (KB_GAMIFICATION_ENABLED=false, R43).
 * Sets TenantContext per tenant so GamificationService scopes its reads/writes
 * (R30). Idempotent (insertOrIgnore on the unique badge constraint).
 */
final class GamificationRecomputeCommand extends Command
{
    protected $signature = 'gamification:recompute {--tenant= : Restrict to a single tenant}';

    protected $description = 'Award gamification badges for all contributors (per tenant). No-op when gamification is disabled.';

    public function handle(GamificationService $gamification, TenantContext $tenants): int
    {
        if (! $gamification->enabled()) {
            $this->info('Gamification disabled (KB_GAMIFICATION_ENABLED=false). Nothing to do.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();

        try {
            foreach ($this->resolveTenantIds() as $tenantId) {
                $tenants->set($tenantId);

                // R3: stream distinct contributors lazily via a single
                // server-side cursor — no id list held in memory and no
                // OFFSET/LIMIT pagination that degrades on large tables.
                $contributors = 0;
                $awarded = 0;

                $rows = KbContributionEvent::query()
                    ->forTenant($tenantId)
                    ->whereNotNull('user_id')
                    ->select('user_id')
                    ->distinct()
                    ->orderBy('user_id')
                    ->cursor();

                foreach ($rows as $row) {
                    $contributors++;
                    $awarded += count($gamification->evaluate((int) $row->user_id));
                }

                $this->info("[{$tenantId}] contributors={$contributors} badges_awarded={$awarded}");
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = trim((string) ($this->option('tenant') ?? ''));
        if ($explicit !== '') {
            return [$explicit];
        }

        // Intentionally cross-tenant: discover every tenant that has any
        // contribution activity so the scheduled run sweeps them all. Selects
        // only tenant_id (the per-tenant scoping happens inside the loop above
        // via forTenant()).
        return KbContributionEvent::query()
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
