<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\McpToolCallAudit;
use App\Support\TenantContext;
use InvalidArgumentException;

class AskMyDocsUserDataExporter
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function export(object $user): array
    {
        $userId = $this->resolveUserId($user);
        $tenantId = $this->tenantContext->current();

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
            'mcp_tool_call_audit' => McpToolCallAudit::query()
                ->forTenant($tenantId)
                ->where('user_id', $userId)
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
}
