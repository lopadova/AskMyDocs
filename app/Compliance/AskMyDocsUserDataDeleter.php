<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\ChatLog;
use App\Models\McpToolCallAudit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

class AskMyDocsUserDataDeleter
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function delete(object $user): void
    {
        $userId = $this->resolveUserId($user);
        $mcpActors = $this->resolveMcpAuditActors($user, $userId);
        $tenantId = $this->tenantContext->current();

        DB::transaction(function () use ($tenantId, $userId, $mcpActors): void {
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
        });
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
