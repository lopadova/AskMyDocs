<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\SystemTenantRegistry;

/**
 * One source of truth for the "must create a company" auth state.
 *
 * This is intentionally a cross-tenant membership existence check: the
 * question is whether the identity belongs to any operational company, not
 * whether it belongs to the request's currently resolved tenant.
 */
final class CompanyOnboardingEligibility
{
    public function required(User $user): bool
    {
        if ($user->can(PlatformAccess::PLATFORM_ADMIN_PERMISSION)) {
            return false;
        }

        return ! ProjectMembership::query()
            ->where('user_id', $user->id)
            // Reserved implementation namespaces are never companies. A stale
            // `default` membership must not suppress the resumable onboarding
            // flow or turn the legacy fallback into operational access.
            ->whereNotIn('tenant_id', SystemTenantRegistry::reservedSlugs())
            ->exists();
    }
}
