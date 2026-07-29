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
            // A legacy `default` membership is still a real company
            // association. Only implementation-only system namespaces are
            // excluded from the operational membership check.
            ->whereNotIn('tenant_id', SystemTenantRegistry::systemSlugs())
            ->exists();
    }
}
