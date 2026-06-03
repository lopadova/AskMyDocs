<?php

namespace App\Services\ChatLog\Drivers;

use App\Models\ChatLog;
use App\Services\ChatLog\ChatLogDriverInterface;
use App\Services\ChatLog\ChatLogEntry;

final class DatabaseChatLogDriver implements ChatLogDriverInterface
{
    public function store(ChatLogEntry $entry): void
    {
        if ($entry->anonymous) {
            $this->storeAnonymous($entry);

            return;
        }

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

    /*
     * Data-minimised write for an anonymous turn. Governed by
     * `chat-log.anonymous_level`:
     *   'none'    — persist nothing.
     *   'minimal' — keep ONLY the by-norm operational fields (tenant via the
     *               model's BelongsToTenant trait, provider/model, token
     *               counts, latency, chunks_count, timestamp) for billing +
     *               abuse counting. NO question / answer / sources / user_id /
     *               client_ip / user_agent — so the row carries no PII and no
     *               way to reconstruct or attribute the conversation.
     */
    private function storeAnonymous(ChatLogEntry $entry): void
    {
        if ((string) config('chat-log.anonymous_level', 'minimal') === 'none') {
            return;
        }

        ChatLog::create([
            'session_id' => $entry->sessionId, // fresh per-request UUID, not user-linkable
            'user_id' => null,
            'question' => '',
            'answer' => '',
            'project_key' => $entry->projectKey,
            'ai_provider' => $entry->aiProvider,
            'ai_model' => $entry->aiModel,
            'chunks_count' => $entry->chunksCount,
            'sources' => [],
            'prompt_tokens' => $entry->promptTokens,
            'completion_tokens' => $entry->completionTokens,
            'total_tokens' => $entry->totalTokens,
            'latency_ms' => $entry->latencyMs,
            'client_ip' => null,
            'user_agent' => null,
            'extra' => ['anonymous' => true],
        ]);
    }
}
