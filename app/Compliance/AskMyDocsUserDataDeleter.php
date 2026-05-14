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
        $tenantId = $this->tenantContext->current();

        DB::transaction(function () use ($tenantId, $userId): void {
            McpToolCallAudit::query()
                ->forTenant($tenantId)
                ->where('user_id', $userId)
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
}
