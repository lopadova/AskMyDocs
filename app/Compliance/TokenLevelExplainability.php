<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Models\ChatLogProvenance;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v6.0/W7 — Token-level explainability decorator over the chat path.
 *
 * Records the chunk-to-answer-token mapping for every assistant turn so
 * auditors can trace any output sentence back to the retrieval chunk
 * that grounded it. Implements the "decorator" half of the AI Act Art.
 * 14 explainability obligation; the storage half lives in
 * `chat_log_provenance` (rows survive hard deletes of `knowledge_chunks`
 * via denormalized `source_path` + a SET NULL FK).
 *
 * Two integration points:
 *   - `record($chatLog, $message, $chunks, $answer)` is the high-level
 *     call from the streaming + non-streaming controllers. It runs the
 *     attribution heuristic (chunk-keyword overlap on the answer text)
 *     and persists one provenance row per (chunk, answer_span).
 *   - `capture($rows)` keeps the v6/W6 low-level signature for callers
 *     that already computed the spans.
 *
 * Provenance is best-effort — failures are logged but NEVER propagate
 * (the chat path must remain hot). The capture() side runs in a single
 * insert transaction so partial provenance is impossible.
 */
final class TokenLevelExplainability
{
    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    /**
     * High-level: record provenance for one assistant turn.
     *
     * @param  ChatLog  $chatLog
     * @param  Message  $message
     * @param  iterable<array{
     *   id?:int|null,
     *   knowledge_chunk_id?:int|null,
     *   chunk_id?:int|null,
     *   source_path?:string|null,
     *   chunk_text?:string|null,
     *   text?:string|null,
     *   score?:float|null
     * }>  $chunks
     * @param  string  $answer
     */
    public function record(ChatLog $chatLog, Message $message, iterable $chunks, string $answer): int
    {
        if (! config('compliance.token_explainability.enabled', true)) {
            return 0;
        }
        if (trim($answer) === '') {
            return 0;
        }

        $rows = [];
        $tenantId = $this->resolveTenantId($chatLog);
        $answerLength = strlen($answer);
        $tokens = $this->tokenize($answer);

        foreach ($chunks as $chunk) {
            $chunkId = $chunk['knowledge_chunk_id'] ?? $chunk['chunk_id'] ?? $chunk['id'] ?? null;
            $chunkText = (string) ($chunk['chunk_text'] ?? $chunk['text'] ?? '');
            $sourcePath = (string) ($chunk['source_path'] ?? '');

            if (! is_int($chunkId) || $chunkText === '' || $sourcePath === '') {
                continue;
            }

            $attribution = $this->attribute($tokens, $chunkText, $answerLength);
            if ($attribution === null) {
                continue;
            }

            $rows[] = [
                'chat_log_id' => $chatLog->getKey(),
                'message_id' => $message->getKey(),
                'answer_token_start' => $attribution['start'],
                'answer_token_end' => $attribution['end'],
                'knowledge_chunk_id' => $chunkId,
                'source_path' => $sourcePath,
                'contribution_score' => $attribution['score'],
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        try {
            DB::transaction(function () use ($rows): void {
                ChatLogProvenance::query()->insert($rows);
            });
            return count($rows);
        } catch (\Throwable $exception) {
            Log::warning('TokenLevelExplainability.record persistence failed', [
                'chat_log_id' => $chatLog->getKey(),
                'message_id' => $message->getKey(),
                'error' => $exception->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Low-level (v6/W6 signature): persist precomputed provenance spans.
     *
     * @param  list<array{
     *   chat_log_id:int,
     *   message_id:int,
     *   answer_token_start:int,
     *   answer_token_end:int,
     *   knowledge_chunk_id:int,
     *   source_path:string,
     *   contribution_score?:float,
     *   tenant_id?:?string,
     * }>  $rows
     */
    public function capture(array $rows): void
    {
        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'chat_log_id' => (int) $row['chat_log_id'],
                'message_id' => (int) $row['message_id'],
                'answer_token_start' => (int) $row['answer_token_start'],
                'answer_token_end' => (int) $row['answer_token_end'],
                'knowledge_chunk_id' => (int) $row['knowledge_chunk_id'],
                'source_path' => (string) $row['source_path'],
                'contribution_score' => (float) ($row['contribution_score'] ?? 0),
                'tenant_id' => $row['tenant_id'] ?? $this->resolveTenantId(null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($payload === []) {
            return;
        }
        DB::transaction(fn () => ChatLogProvenance::query()->insert($payload));
    }

    /**
     * Heuristic: chunk-keyword overlap with the answer text. Returns the
     * approximate byte span in the answer that best matches the chunk
     * plus a normalized contribution score in [0, 1].
     *
     * Production wiring can replace this with provider-supplied
     * grounding spans (e.g. Claude citations, Vertex AI sources) — the
     * interface is stable.
     *
     * @param  list<string>  $tokens
     * @return array{start:int,end:int,score:float}|null
     */
    private function attribute(array $tokens, string $chunkText, int $answerLength): ?array
    {
        $chunkTokens = $this->tokenize($chunkText);
        if ($chunkTokens === [] || $tokens === []) {
            return null;
        }

        $chunkVocabulary = array_unique($chunkTokens);
        $totalChunkTokens = count($chunkVocabulary);

        $bestStart = 0;
        $bestEnd = 0;
        $bestOverlap = 0;
        $windowSize = max(8, (int) ceil(count($tokens) / 4));

        for ($i = 0; $i + $windowSize <= count($tokens); $i++) {
            $window = array_slice($tokens, $i, $windowSize);
            $overlap = count(array_intersect($window, $chunkVocabulary));
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $bestStart = $i;
                $bestEnd = $i + $windowSize;
            }
        }

        if ($bestOverlap === 0) {
            return null;
        }

        $tokenLength = count($tokens);
        $startByte = (int) floor(($bestStart / max(1, $tokenLength)) * $answerLength);
        $endByte = (int) ceil(($bestEnd / max(1, $tokenLength)) * $answerLength);

        return [
            'start' => max(0, $startByte),
            'end' => min($answerLength, $endByte),
            'score' => round(min(1.0, $bestOverlap / max(1, $totalChunkTokens)), 4),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text));
        if (! is_string($normalized)) {
            return [];
        }
        $parts = array_filter(
            array_map('trim', explode(' ', $normalized)),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        );
        return array_values($parts);
    }

    private function resolveTenantId(?ChatLog $chatLog): ?string
    {
        if ($chatLog instanceof ChatLog && ! empty($chatLog->getAttribute('tenant_id'))) {
            return (string) $chatLog->getAttribute('tenant_id');
        }
        if ($this->tenantContext !== null) {
            try {
                return $this->tenantContext->current();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
