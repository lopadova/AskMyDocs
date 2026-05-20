<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use App\Models\TenantSchedulerOverride;

final class TenantSchedulerResolver
{
    public function cron(string $slotName, string $tenantId, string $fallback): string
    {
        $override = $this->override($slotName, $tenantId);

        return $override?->cron ?: $fallback;
    }

    public function enabled(string $slotName, string $tenantId, bool $fallback): bool
    {
        $override = $this->override($slotName, $tenantId);
        if ($override === null) {
            return $fallback;
        }

        return (bool) $override->enabled;
    }

    public function timezone(string $slotName, string $tenantId, string $fallback = 'UTC'): string
    {
        $override = $this->override($slotName, $tenantId);

        return $override?->timezone ?: $fallback;
    }

    private function override(string $slotName, string $tenantId): ?TenantSchedulerOverride
    {
        return TenantSchedulerOverride::query()
            ->forTenant($tenantId)
            ->where('slot_name', $slotName)
            ->first();
    }
}

