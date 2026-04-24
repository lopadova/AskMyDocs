<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Logs;

use App\Models\ChatLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase H1 — admin Log Viewer, chat tab.
 *
 * Full shape: the drawer needs question/answer/prompt + token breakdown +
 * `extra` metadata, so we project every column we store. `user_agent`
 * is truncated to 512 in the migration already, so no extra trimming.
 *
 * @property-read ChatLog $resource
 */
class ChatLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'session_id' => $log->session_id,
            'user_id' => $log->user_id,
            'question' => $log->question,
            'answer' => $log->answer,
            'project_key' => $log->project_key,
            'ai_provider' => $log->ai_provider,
            'ai_model' => $log->ai_model,
            'chunks_count' => (int) $log->chunks_count,
            'sources' => $log->sources ?? [],
            'prompt_tokens' => $log->prompt_tokens !== null ? (int) $log->prompt_tokens : null,
            'completion_tokens' => $log->completion_tokens !== null ? (int) $log->completion_tokens : null,
            'total_tokens' => $log->total_tokens !== null ? (int) $log->total_tokens : null,
            'latency_ms' => (int) $log->latency_ms,
            'client_ip' => $log->client_ip,
            'user_agent' => $log->user_agent,
            'extra' => $log->extra ?? null,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }
}
