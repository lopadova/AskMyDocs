<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\KbCanonicalAudit;
use App\Models\McpToolCallAudit;
use App\Support\TenantContext;
use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

class AskMyDocsUserDataExporter
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTenantResolver $tenantResolver,
    ) {}

    public function export(object $user): array
    {
        $userId = $this->resolveUserId($user);
        $userEmail = is_string($user->email ?? null) ? $user->email : null;

        // v8.0.2 / Copilot iter-5 of PR #224 — actor sets are now
        // sourced from the single-source-of-truth UserTenantResolver
        // so a future shape change (e.g. a new opaque actor format
        // the package starts emitting) lands in one place and stays
        // aligned across resolver / exporter / deleter.
        $auditActors = $this->tenantResolver->canonicalAuditActorsForUser($userId, $userEmail);
        $mcpActors = $this->tenantResolver->mcpActorsForUser($userId, $userEmail);

        // v8.0.2 / deep-review C — User is host-wide (no tenant_id).
        // Tenant enumeration is shared with the Deleter via
        // UserTenantResolver so export + erase stay symmetric.
        $tenantIds = $this->tenantResolver->tenantsForUser($userId, $userEmail);

        $aggregate = [
            'conversations' => [],
            'messages' => [],
            'chat_logs' => [],
            'chat_log_provenance' => [],
            'kb_canonical_audit' => [],
            'connector_installations' => [],
            'mcp_tool_call_audit' => [],
        ];

        foreach ($tenantIds as $tenantId) {
            $perTenant = $this->exportForTenant($userId, $tenantId, $auditActors, $mcpActors);
            foreach ($perTenant as $category => $rows) {
                $aggregate[$category] = array_merge($aggregate[$category], $rows);
            }
        }

        // v8.0.2 / deep-review C — surface the tenant set in the
        // top-level envelope so the DSAR consumer (operator,
        // auditor, or the user themselves) can verify coverage at a
        // glance without re-deriving from row contents.
        $aggregate['_dsar_meta'] = [
            'tenants_scanned' => array_values($tenantIds),
            'active_tenant' => $this->tenantContext->current(),
        ];

        return $aggregate;
    }

    /**
     * @param  list<string>  $auditActors
     * @param  list<string>  $mcpActors
     * @return array<string, array<int, mixed>>
     */
    private function exportForTenant(int $userId, string $tenantId, array $auditActors, array $mcpActors): array
    {
        $conversationIds = Conversation::query()
            ->forTenant($tenantId)
            ->select('id')
            ->where('user_id', $userId);

        $chatLogIds = ChatLog::query()
            ->forTenant($tenantId)
            ->select('id')
            ->where('user_id', $userId);

        return [
            'conversations' => Conversation::query()
                ->forTenant($tenantId)
                ->where('user_id', $userId)
                ->get()
                ->toArray(),
            'messages' => Message::query()
                ->forTenant($tenantId)
                ->whereIn('conversation_id', $conversationIds)
                ->get()
                ->toArray(),
            'chat_logs' => ChatLog::query()
                ->forTenant($tenantId)
                ->where('user_id', $userId)
                ->get()
                ->toArray(),
            'chat_log_provenance' => ChatLogProvenance::query()
                ->forTenant($tenantId)
                ->whereIn('chat_log_id', $chatLogIds)
                ->get()
                ->toArray(),
            'kb_canonical_audit' => KbCanonicalAudit::query()
                ->forTenant($tenantId)
                ->whereIn('actor', $auditActors)
                ->get()
                ->toArray(),
            'connector_installations' => ConnectorInstallation::query()
                ->forTenant($tenantId)
                ->where('created_by', $userId)
                ->get()
                ->toArray(),
            // v7.0/W6.3 — must match BOTH the legacy `user_id` join
            // (host-written rows) AND the package's opaque `actor`
            // string (package-written rows). Mirrors the deleter's
            // `resolveMcpAuditActors()` set. See deleter for the
            // rationale.
            'mcp_tool_call_audit' => McpToolCallAudit::query()
                ->forTenant($tenantId)
                ->where(function ($q) use ($userId, $mcpActors): void {
                    $q->where('user_id', $userId)
                        ->orWhereIn('actor', $mcpActors);
                })
                ->get()
                ->toArray(),
        ];
    }

    private function resolveUserId(object $user): int
    {
        $value = $user->id ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException('AskMyDocsUserDataExporter requires a user object with a positive integer id.');
    }

    // v8.0.2 / Copilot iter-5 of PR #224 — resolveAuditActors
    // and resolveMcpAuditActors removed; the canonical actor sets
    // live on UserTenantResolver (single source of truth, used by
    // exporter / deleter / resolver-internal tenant sweep).
}
