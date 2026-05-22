<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\ChatLog;
use App\Models\McpToolCallAudit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

class AskMyDocsUserDataDeleter
{
    public function __construct(
        // v8.0.2 / Copilot iter-2 — TenantContext was used directly
        // before the C refactor; UserTenantResolver now encapsulates
        // the active-tenant lookup, so the deleter has no direct
        // dependency on TenantContext. Keeping the DI surface
        // minimal makes it obvious that the deleter walks WHATEVER
        // tenant set the resolver returns — not the active tenant
        // specifically.
        private readonly UserTenantResolver $tenantResolver,
    ) {}

    public function delete(object $user): void
    {
        $userId = $this->resolveUserId($user);
        $mcpActors = $this->resolveMcpAuditActors($user, $userId);

        // v8.0.2 / deep-review C — DSAR erasure (Art. 17) MUST cover
        // every tenant the user has data in. User is host-wide (no
        // tenant_id), so the active TenantContext misses any other
        // tenant the user has membership in.
        //
        // Wrap the per-tenant deletes in a SINGLE outer transaction
        // so the erasure is either fully complete or fully rolled
        // back — a half-deleted user violates Art. 17 just as
        // surely as a no-op delete. Tenant set comes from the
        // shared UserTenantResolver — what the Exporter saw, this
        // wipes.
        $tenantIds = $this->tenantResolver->tenantsForUser($userId);

        DB::transaction(function () use ($tenantIds, $userId, $mcpActors): void {
            foreach ($tenantIds as $tenantId) {
                $this->deleteForTenant($tenantId, $userId, $mcpActors);
            }
        });
    }

    /**
     * @param  list<string>  $mcpActors
     */
    private function deleteForTenant(string $tenantId, int $userId, array $mcpActors): void
    {
        // v7.0/W6.3 — the package writes audit rows with
        // `user_id=null` and an opaque `actor` string (e.g.
        // `"user:42"` or the user's email). DSAR delete MUST cover
        // both the legacy `user_id` join AND the package's actor
        // convention, otherwise package-written rows survive the
        // erasure request and silently breach the GDPR Art. 17
        // contract.
        McpToolCallAudit::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($userId, $mcpActors): void {
                $q->where('user_id', $userId)
                    ->orWhereIn('actor', $mcpActors);
            })
            ->delete();

        ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->where('created_by', $userId)
            ->delete();

        ChatLog::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId)
            ->delete();

        Conversation::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId)
            ->delete();
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

        throw new InvalidArgumentException('AskMyDocsUserDataDeleter requires a user object with a positive integer id.');
    }

    /**
     * Build the set of `mcp_tool_call_audit.actor` strings that may
     * identify this user. The package casts whatever the host hands
     * it via `$context['actor']` to a string; common host wirings:
     *
     *   - `(string) $userId`         (bare numeric id, matches the
     *                                 package README's bridge example)
     *   - `"user:{$userId}"`         (prefixed id, used by the host's
     *                                 integration tests)
     *   - the user's email           (legacy host audit convention,
     *                                 still in use by `kb_canonical_audit`)
     *   - `"user:{$email}"`          (prefixed-email variant, defensive)
     *
     * We over-include rather than under-include — a false-positive
     * delete here would only erase a row a different user-as-actor
     * shape happens to match (vanishingly small for digit / email
     * inputs), while a false-negative is a GDPR breach.
     *
     * @return list<string>
     */
    private function resolveMcpAuditActors(object $user, int $userId): array
    {
        $actors = [
            (string) $userId,
            'user:'.$userId,
        ];

        $email = $user->email ?? null;
        if (is_string($email) && $email !== '') {
            $actors[] = $email;
            $actors[] = 'user:'.$email;
        }

        return array_values(array_unique($actors));
    }
}
