<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ProjectMembership;
use App\Support\TenantContext;

/**
 * v8.0.2 / deep-review C — single source of truth for "every
 * tenant a host-wide User has data in".
 *
 * AskMyDocsUserDataExporter (Art. 15) and
 * AskMyDocsUserDataDeleter (Art. 17) both need the same answer:
 * the deduped UNION of (a) every tenant the user has a
 * `project_memberships` row in PLUS (b) the active TenantContext
 * (legacy users without memberships still get their active-tenant
 * data, AND a brand-new user who just signed up has their active
 * tenant covered).
 *
 * Centralising the resolution prevents drift — if a future
 * tenant-attribution source is added (e.g. ownership rows on
 * `conversations`, or a `user_tenants` association), only this
 * class changes and both DSAR paths stay aligned.
 */
final class UserTenantResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return list<string>
     */
    public function tenantsForUser(int $userId): array
    {
        $membershipTenants = ProjectMembership::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $active = $this->tenantContext->current();

        return array_values(array_unique([...$membershipTenants, $active]));
    }
}
