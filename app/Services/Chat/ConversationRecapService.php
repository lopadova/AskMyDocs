<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\AiManager;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Rolling, INCREMENTAL summary of a conversation ("session recap"),
 * injected into the RAG system prompt (prompts/kb_rag.blade.php, "Session
 * Recap" block) so the assistant keeps a compact sense of what has been
 * discussed without re-reading the full message history on every turn.
 *
 * Deliberately incremental: each update feeds the LLM the PREVIOUS recap +
 * only the last `kb.session_recap.window_messages` messages — never the
 * whole conversation (prompts/kb_session_recap.blade.php) — so the cost of
 * keeping the recap fresh stays constant regardless of how long the
 * conversation grows.
 *
 * Mirrors {@see \App\Services\Kb\Analysis\KbChangeAnalyzer}'s graceful
 * JSON-decode contract: a malformed LLM reply is logged and the PREVIOUS
 * recap is left untouched — never corrupted, never thrown into the caller.
 * This is meant to run from a queued job ({@see \App\Jobs\UpdateConversationRecapJob}),
 * off the user-facing request path, so a failure here must never surface as
 * a broken turn.
 *
 * NOT `final` — Mockery-friendly for controller/job tests that want to pin
 * dispatch/gating behaviour without the LLM round-trip (same rationale as
 * `AiManager` and `KbChangeAnalyzer`).
 */
class ConversationRecapService
{
    public function __construct(private readonly AiManager $ai)
    {
    }

    /**
     * Update `$conversation->session_recap` from its most recent messages.
     * No-op (returns without touching anything) when the feature is
     * disabled, when the cadence gate says it isn't this turn's turn, when
     * there are no messages yet, or when the LLM reply isn't valid JSON.
     */
    public function updateAfterTurn(Conversation $conversation): void
    {
        if (! (bool) config('kb.session_recap.enabled', true)) {
            return;
        }

        if (! $this->isDueByCadence($conversation)) {
            return;
        }

        $recentMessages = $this->recentMessages($conversation);
        if ($recentMessages->isEmpty()) {
            return;
        }

        $systemPrompt = View::make('prompts.kb_session_recap', [
            'previousRecap' => $conversation->session_recap,
            'recentMessages' => $recentMessages,
        ])->render();

        $response = $this->ai->chat($systemPrompt, 'Produce the JSON now.');

        $decoded = $this->decodeLlmJson($response->content);
        if ($decoded === null) {
            return;
        }

        $conversation->session_recap = $this->validate($decoded);
        $conversation->save();
    }

    /**
     * `update_every_n_messages` = 1 (default) is due on every call — the
     * common case. A higher value trades freshness for fewer LLM calls on
     * long, chatty conversations by only updating every Nth message
     * (counting both user and assistant rows).
     */
    private function isDueByCadence(Conversation $conversation): bool
    {
        $everyN = max(1, (int) config('kb.session_recap.update_every_n_messages', 1));
        if ($everyN === 1) {
            return true;
        }

        return $conversation->messages()->count() % $everyN === 0;
    }

    /**
     * The last `window_messages` messages, chronological order — NOT the
     * full history. This is the input-cost bound that keeps recap updates
     * cheap regardless of conversation length.
     *
     * @return \Illuminate\Support\Collection<int, array{role: string, content: string}>
     */
    private function recentMessages(Conversation $conversation): \Illuminate\Support\Collection
    {
        $window = max(1, (int) config('kb.session_recap.window_messages', 6));

        return $conversation->messages()
            ->orderByDesc('id')
            ->limit($window)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => (string) $m->role, 'content' => (string) $m->content]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLlmJson(string $content): ?array
    {
        $stripped = trim($content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $stripped, $m) === 1) {
            $stripped = trim($m[1]);
        }

        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        Log::warning('ConversationRecapService: LLM returned non-JSON output', [
            'content_preview' => mb_substr($content, 0, 300),
            'json_error' => json_last_error_msg(),
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{summary: string, topics: list<string>, open_questions: list<string>, updated_at: string}
     */
    private function validate(array $decoded): array
    {
        return [
            'summary' => mb_substr(trim((string) ($decoded['summary'] ?? '')), 0, 1200),
            'topics' => $this->stringList($decoded['topics'] ?? []),
            'open_questions' => $this->stringList($decoded['open_questions'] ?? []),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_slice(array_filter(
            array_map(static fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 200) : '', $value),
            static fn (string $v): bool => $v !== '',
        ), 0, 8));
    }
}
