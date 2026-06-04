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
     *               abuse counting, PLUS an allowlisted slice of `extra` that
     *               is operational-but-non-PII (refusal_reason + confidence +
     *               the primary/expanded/rejected chunk counts) so refusal /
     *               retrieval dashboards keep working on anonymous turns. NO
     *               question / answer / sources / user_id / client_ip /
     *               user_agent — so the row carries no PII and no way to
     *               reconstruct or attribute the conversation.
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
            'extra' => $this->anonymousExtra($entry),
        ]);
    }

    /**
     * Allowlisted, non-PII slice of the upstream `extra` for an anonymous row.
     *
     * Whitelisting (not blanket-merging) is deliberate: this is a privacy
     * feature, so a future code path that stuffs PII into `extra` must NOT
     * silently leak it onto the minimal anonymous row. Only the known
     * operational keys retrieval/refusal dashboards rely on are kept.
     *
     * @return array<string, mixed>
     */
    private function anonymousExtra(ChatLogEntry $entry): array
    {
        $safe = array_intersect_key($entry->extra, array_flip([
            'refusal_reason',
            'confidence',
            'primary_count',
            'expanded_count',
            'rejected_count',
        ]));

        return $safe + ['anonymous' => true];
    }
}
