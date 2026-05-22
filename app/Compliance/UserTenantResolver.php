<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\KbCanonicalAudit;
use App\Models\McpToolCallAudit;
use App\Models\ProjectMembership;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.0.2 / deep-review C — single source of truth for "every
 * tenant a host-wide User has data in" + "every actor shape the
 * user maps to in audit tables".
 *
 * AskMyDocsUserDataExporter (Art. 15) and
 * AskMyDocsUserDataDeleter (Art. 17) both need:
 *   - the deduped UNION of tenants the user has footprint in
 *     (memberships + data-derived + active TenantContext);
 *   - the actor sets used to match audit rows that carry
 *     opaque actor strings instead of user_id FKs.
 *
 * Centralising both prevents drift — if a future tenant-attribution
 * source or audit actor shape is added, only this class changes
 * and both DSAR paths stay aligned.
 */
final class UserTenantResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Every tenant the user has data in:
     *   (a) project_memberships rows
     *   (b) data-derived sweep across user-owned tenant-aware
     *       tables (conversations + chat_logs +
     *       connector_installations + mcp_tool_call_audit +
     *       kb_canonical_audit)
     *   (c) active TenantContext (fallback for new users / no
     *       data yet AND legacy seeded users)
     *
     * Keep the data-derived block in lockstep with the Exporter's
     * AND Deleter's per-tenant query blocks: every tenant-aware
     * table touched there must contribute its tenant_id here so
     * the resolver returns a SUPERSET of "tenants that will be
     * scanned/wiped". A table missing here = a tenant that
     * survives erasure or escapes the export envelope.
     *
     * @param  string|null  $userEmail  Optional — passed by callers
     *   that have the user object (Exporter/Deleter receive an
     *   `object` and can extract `email`). Used for the
     *   mcp_tool_call_audit + kb_canonical_audit actor matching.
     *   Passing null only weakens the email-shaped actor match;
     *   digit-shaped actors still match.
     * @return list<string>
     */
    public function tenantsForUser(int $userId, ?string $userEmail = null): array
    {
        $membershipTenants = ProjectMembership::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

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

        $mcpActors = $this->mcpActorsForUser($userId, $userEmail);
        $mcpAuditTenants = McpToolCallAudit::query()
            ->where(function ($q) use ($userId, $mcpActors): void {
                $q->where('user_id', $userId)
                    ->orWhereIn('actor', $mcpActors);
            })
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        // v8.0.2 / Copilot iter-5 of PR #224 — kb_canonical_audit
        // is exported by the DSAR Art. 15 path (NOT deleted —
        // forensic trail by design). A user whose only footprint
        // in tenant-d is kb_canonical_audit rows (e.g. they
        // promoted a doc there as actor + lost membership later)
        // would otherwise miss tenant-d from the export envelope.
        $canonicalActors = $this->canonicalAuditActorsForUser($userId, $userEmail);
        $canonicalAuditTenants = KbCanonicalAudit::query()
            ->whereIn('actor', $canonicalActors)
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
            ...$canonicalAuditTenants,
            $active,
        ]));
    }

    /**
     * Actor strings that may identify this user in
     * `mcp_tool_call_audit.actor`. The package casts whatever the
     * host hands it via `$context['actor']` to a string; common
     * shapes are: bare id, `"user:{id}"`, email, `"user:{email}"`.
     *
     * Single source of truth for the matcher: both
     * AskMyDocsUserDataExporter and AskMyDocsUserDataDeleter call
     * this method directly so the set cannot drift across the
     * three locations that previously each held a copy.
     *
     * @return list<string>
     */
    public function mcpActorsForUser(int $userId, ?string $userEmail): array
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

    /**
     * Actor strings that may identify this user in
     * `kb_canonical_audit.actor`. Narrower than the mcp set —
     * canonical audit is host-written, the host's convention is
     * the bare numeric id OR the user's email. No `user:` prefix
     * is in use today.
     *
     * @return list<string>
     */
    public function canonicalAuditActorsForUser(int $userId, ?string $userEmail): array
    {
        $actors = [(string) $userId];

        if (is_string($userEmail) && $userEmail !== '') {
            $actors[] = $userEmail;
        }

        return array_values(array_unique($actors));
    }
}
