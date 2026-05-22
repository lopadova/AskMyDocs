<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.0.2 / deep-review C — single source of truth for "every
 * tenant a host-wide User has data in".
 *
 * AskMyDocsUserDataExporter (Art. 15) and
 * AskMyDocsUserDataDeleter (Art. 17) both need the same answer:
 * the deduped UNION of (a) every tenant the user has a
 * `project_memberships` row in PLUS (b) every tenant the user has
 * actual data rows in (memberships can be revoked while data
 * persists — audit retention, conversation history) PLUS (c) the
 * active TenantContext (legacy users without memberships still
 * get their active-tenant data, AND a brand-new user who just
 * signed up has their active tenant covered).
 *
 * The data-derived sweep (Copilot iter-3 of PR #224) closes the
 * "membership revoked but data retained" gap: a user whose
 * `project_memberships` row in tenant-B was removed but whose
 * `conversations` / `chat_logs` / `connector_installations`
 * rows in tenant-B remain (legitimate under common retention
 * policies) would otherwise have those tenant-B rows silently
 * survive DSAR Art. 17 erasure.
 *
 * Centralising the resolution prevents drift — if a future
 * tenant-attribution source is added (e.g. a `user_tenants`
 * association table), only this class changes and both DSAR
 * paths stay aligned.
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

        // Data-derived sweep across the user-owned tenant-aware
        // tables. Keep this set in lockstep with the Deleter's
        // delete-per-tenant block — every table touched there must
        // contribute its tenant_id here so the resolver returns a
        // SUPERSET (or equal) of "tenants that will be wiped". A
        // table missing here = a tenant that survives erasure.
        $conversationTenants = Conversation::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $chatLogTenants = ChatLog::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $connectorTenants = ConnectorInstallation::query()
            ->where('created_by', $userId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $active = $this->tenantContext->current();

        return array_values(array_unique([
            ...$membershipTenants,
            ...$conversationTenants,
            ...$chatLogTenants,
            ...$connectorTenants,
            $active,
        ]));
    }
}
