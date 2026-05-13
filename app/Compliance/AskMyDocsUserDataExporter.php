<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ChatLog;
use App\Models\KnowledgeDocument;
use App\Models\McpToolCallAudit;
use Padosoft\AiActCompliance\DSAR\Contracts\UserDataExporter;

class AskMyDocsUserDataExporter implements UserDataExporter
{
    public function export(object $user): array
    {
        $userId = (string) ($user->id ?? '');

        return [
            'conversations' => Conversation::query()->where('user_id', $userId)->get()->toArray(),
            'messages' => Message::query()->where('user_id', $userId)->get()->toArray(),
            'chat_logs' => ChatLog::query()->where('user_id', $userId)->get()->toArray(),
            'knowledge_documents' => KnowledgeDocument::query()->where('created_by', $userId)->get()->toArray(),
            'mcp_tool_call_audit' => McpToolCallAudit::query()->where('user_id', $userId)->get()->toArray(),
        ];
    }
}
