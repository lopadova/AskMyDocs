<?php

namespace App\Compliance;

use App\Models\ChatLogProvenance;

class TokenLevelExplainability
{
    public function capture(array $rows): void
    {
        foreach ($rows as $row) {
            ChatLogProvenance::query()->create([
                'chat_log_id' => $row['chat_log_id'],
                'message_id' => $row['message_id'],
                'answer_token_start' => $row['answer_token_start'],
                'answer_token_end' => $row['answer_token_end'],
                'knowledge_chunk_id' => $row['knowledge_chunk_id'],
                'source_path' => $row['source_path'],
                'contribution_score' => $row['contribution_score'] ?? 0,
                'tenant_id' => $row['tenant_id'] ?? null,
            ]);
        }
    }
}
