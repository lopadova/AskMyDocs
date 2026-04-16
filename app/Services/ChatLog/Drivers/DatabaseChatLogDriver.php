<?php

namespace App\Services\ChatLog\Drivers;

use App\Models\ChatLog;
use App\Services\ChatLog\ChatLogDriverInterface;
use App\Services\ChatLog\ChatLogEntry;

final class DatabaseChatLogDriver implements ChatLogDriverInterface
{
    public function store(ChatLogEntry $entry): void
    {
        ChatLog::create([
            'session_id' => $entry->sessionId,
            'user_id' => $entry->userId,
            'question' => $entry->question,
            'answer' => $entry->answer,
            'project_key' => $entry->projectKey,
            'ai_provider' => $entry->aiProvider,
            'ai_model' => $entry->aiModel,
            'chunks_count' => $entry->chunksCount,
            'sources' => $entry->sources,
            'prompt_tokens' => $entry->promptTokens,
            'completion_tokens' => $entry->completionTokens,
            'total_tokens' => $entry->totalTokens,
            'latency_ms' => $entry->latencyMs,
            'client_ip' => $entry->clientIp,
            'user_agent' => $entry->userAgent,
            'extra' => $entry->extra,
        ]);
    }
}
