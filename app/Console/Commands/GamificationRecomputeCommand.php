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

                $userIds = KbContributionEvent::query()
                    ->forTenant($tenantId)
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->pluck('user_id');

                $awarded = 0;
                foreach ($userIds as $userId) {
                    $awarded += count($gamification->evaluate((int) $userId));
                }

                $this->info("[{$tenantId}] contributors={$userIds->count()} badges_awarded={$awarded}");
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

        return KbContributionEvent::query()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
