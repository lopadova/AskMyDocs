<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\McpToolCallAudit;
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
     * @param  string|null  $userEmail  Optional — passed by callers
     *   that have the user object (Exporter/Deleter receive an
     *   `object` and can extract `email`). The mcp_tool_call_audit
     *   sweep uses the email to match the package's actor
     *   convention. Passing null only weakens the email-shaped
     *   actor match; digit-shaped actors still match.
     * @return list<string>
     */
    public function tenantsForUser(int $userId, ?string $userEmail = null): array
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

        // v8.0.2 / Copilot iter-4 of PR #224 — mcp_tool_call_audit
        // is a tenant-attributable surface the Deleter wipes
        // per-tenant via `user_id = X OR actor IN (...)`. A user
        // whose ONLY tenant-C footprint is mcp audit rows (no
        // conversations, no chat logs, no connector installations,
        // no membership) would otherwise have those rows survive
        // Art. 17. Mirror the Deleter's matcher exactly so the
        // resolver returns a SUPERSET of "tenants that will be
        // wiped".
        $mcpActors = $this->mcpActorsForUser($userId, $userEmail);
        $mcpAuditTenants = McpToolCallAudit::query()
            ->where(function ($q) use ($userId, $mcpActors): void {
                $q->where('user_id', $userId)
                    ->orWhereIn('actor', $mcpActors);
            })
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $active = $this->tenantContext->current();

        return array_values(array_unique([
            ...$membershipTenants,
            ...$conversationTenants,
            ...$chatLogTenants,
            ...$connectorTenants,
            ...$mcpAuditTenants,
            $active,
        ]));
    }

    /**
     * Mirror of the Deleter's actor set (kept inline rather than
     * extracted again to avoid a second cross-class shared helper).
     * Email is optional — when null we still cover the digit-shaped
     * actors. Keep this in lockstep with
     * AskMyDocsUserDataDeleter::resolveMcpAuditActors().
     *
     * @return list<string>
     */
    private function mcpActorsForUser(int $userId, ?string $userEmail): array
    {
        $actors = [
            (string) $userId,
            'user:'.$userId,
        ];

        if (is_string($userEmail) && $userEmail !== '') {
            $actors[] = $userEmail;
            $actors[] = 'user:'.$userEmail;
        }

        return array_values(array_unique($actors));
    }
}
