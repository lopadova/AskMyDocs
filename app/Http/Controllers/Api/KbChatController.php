<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Requests\Api\KbChatRequest;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KbChatController extends Controller
{
    /**
     * T3.4 — literal sentinel string the LLM emits to signal self-refusal.
     * The prompt template `prompts/kb_rag.blade.php` documents this contract
     * under "Refusal Protocol". Detected via `=== trim($content)`, NOT
     * `str_contains` — explanatory wrapping text means the LLM produced
     * a partial answer and that's still useful to ship to the user.
     */
    private const SELF_REFUSAL_SENTINEL = '__NO_GROUNDED_ANSWER__';

    public function __invoke(
        KbChatRequest $request,
        AiManager $ai,
        KbSearchService $search,
        ChatLogManager $chatLog,
    ): JsonResponse {
        $question = (string) $request->input('question');
        // Effective single-project key for the legacy meta payload + the
        // chat log row. When the new `filters.project_keys` payload is
        // used, the FIRST element is treated as the canonical tenant for
        // observability (chat-log row carries one project_key column);
        // the FULL list is still applied via the RetrievalFilters DTO.
        $projectKey = $request->effectiveProjectKey();
        $filters = $request->toFilters();

        $startTime = microtime(true);

        $result = $search->searchWithContext(
            query: $question,
            projectKey: $projectKey,
            limit: config('kb.default_limit', 8),
            minSimilarity: config('kb.default_min_similarity', 0.30),
            filters: $filters,
        );

        // T3.3 — deterministic refusal short-circuit. If too few primary
        // chunks pass the refusal threshold, we don't call the LLM at all
        // and return a refusal payload that the FE can render distinctly
        // (see ConfidenceBadge / RefusalNotice — T3.6/T3.7, deferred).
        // Threshold is intentionally above `default_min_similarity` so
        // the search step over-retrieves but only confident chunks
        // qualify for grounding.
        $refusalThreshold = (float) config('kb.refusal.min_chunk_similarity', 0.45);
        $refusalMinChunks = (int) config('kb.refusal.min_chunks_required', 1);

        $grounded = $result->primary->filter(
            fn ($c) => (float) ($c->vector_score ?? 0) >= $refusalThreshold
        );

        if ($grounded->count() < $refusalMinChunks) {
            return $this->refusalResponse(
                request: $request,
                chatLog: $chatLog,
                question: $question,
                projectKey: $projectKey,
                result: $result,
                startTime: $startTime,
                reason: 'no_relevant_context',
            );
        }

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $result->primary,
            'expanded' => $result->expanded,
            'rejected' => $result->rejected,
            'projectKey' => $projectKey,
        ])->render();

        $aiResponse = $ai->chat($systemPrompt, $question);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // T3.4 — sentinel detection. The prompt instructs the LLM to emit
        // exactly `__NO_GROUNDED_ANSWER__` when it cannot ground the answer
        // in the provided context. Compare with `===` after `trim()` —
        // explanatory wrapping text should NOT trigger the refusal (the
        // user benefits from the partial answer + skip-note pattern that
        // the prompt also encourages).
        if ($this->isSelfRefusalSentinel($aiResponse->content)) {
            return $this->convertSentinelToRefusal(
                request: $request,
                chatLog: $chatLog,
                question: $question,
                projectKey: $projectKey,
                result: $result,
                aiResponse: $aiResponse,
                latencyMs: $latencyMs,
            );
        }

        $citations = $this->buildCitations($result);
        $sources = $this->collectSources($result);

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $request->user()?->id,
            question: $question,
            answer: $aiResponse->content,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $result->totalChunks(),
            sources: $sources,
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
            ],
        ));

        return response()->json([
            'answer' => $aiResponse->content,
            'citations' => $citations,
            // T3.3 — added to the happy-path response too so the FE shape
            // is uniform across grounded vs refused. T3.5 will populate
            // confidence with the real ConfidenceCalculator output; for
            // now grounded answers carry null (FE renders no badge).
            'confidence' => null,
            'refusal_reason' => null,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'chunks_used' => $result->totalChunks(),
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                'latency_ms' => $latencyMs,
                // T2.2 — surface the user-selected filter count so the
                // FE composer can render "5 filters selected" without
                // an extra round-trip. Mirrors the meta key set by
                // KbSearchService::searchWithContext (T2.1).
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
            ],
        ]);
    }

    /**
     * Refusal payload — used when no chunks pass the similarity floor.
     *
     * Persists the chat log row even on refusal (analytics + the admin
     * dashboard need to see refused turns to tune the threshold). Sets
     * `confidence = 0` and `refusal_reason` on the chat_logs row via
     * the `extra` field so existing single-row queries don't break.
     *
     * No LLM call. The latency reported is retrieval-only — useful for
     * observability so we can tell apart "refused fast on missing data"
     * from "refused after slow over-retrieval".
     */
    private function refusalResponse(
        KbChatRequest $request,
        ChatLogManager $chatLog,
        string $question,
        ?string $projectKey,
        SearchResult $result,
        float $startTime,
        string $reason,
    ): JsonResponse {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $answer = (string) __('kb.no_grounded_answer');

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $request->user()?->id,
            question: $question,
            answer: $answer,
            projectKey: $projectKey,
            aiProvider: 'none',
            aiModel: 'none',
            chunksCount: 0,
            sources: [],
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'refusal_reason' => $reason,
                'confidence' => 0,
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
            ],
        ));

        return response()->json([
            'answer' => $answer,
            'citations' => [],
            'confidence' => 0,
            'refusal_reason' => $reason,
            'meta' => [
                'provider' => null,
                'model' => null,
                'chunks_used' => 0,
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                'latency_ms' => $latencyMs,
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
                'refused_early' => true,
            ],
        ]);
    }

    /**
     * T3.4 — exact sentinel match after trim. NOT a substring check; an
     * answer like "I cannot help — __NO_GROUNDED_ANSWER__" still contains
     * useful framing for the user, but a bare sentinel means the LLM
     * decided no grounded answer exists. Whitespace tolerance handles
     * providers that wrap responses in stray spaces/newlines.
     */
    private function isSelfRefusalSentinel(string $content): bool
    {
        return trim($content) === self::SELF_REFUSAL_SENTINEL;
    }

    /**
     * T3.4 — convert an LLM-self-refusal sentinel response into a refusal
     * payload. Different from {@see refusalResponse()} on TWO axes:
     *
     *   1. `refusal_reason` is `'llm_self_refusal'` — retrieval succeeded
     *      but the LLM declared the chunks insufficient. Distinguishing
     *      from `'no_relevant_context'` lets the dashboard tune the
     *      similarity threshold (lots of `llm_self_refusal` ≈ threshold
     *      too lenient) vs. retrieval (lots of `no_relevant_context` ≈
     *      threshold too strict).
     *   2. The chat-log row carries the REAL provider/model/tokens
     *      because the LLM call was paid in full. Latency reflects the
     *      end-to-end retrieval+LLM time, not retrieval-only.
     *
     * The user-facing answer is replaced with the i18n placeholder so the
     * FE renders the RefusalNotice (T3.7, deferred) instead of the literal
     * sentinel string.
     */
    private function convertSentinelToRefusal(
        KbChatRequest $request,
        ChatLogManager $chatLog,
        string $question,
        ?string $projectKey,
        SearchResult $result,
        AiResponse $aiResponse,
        int $latencyMs,
    ): JsonResponse {
        $reason = 'llm_self_refusal';
        $answer = (string) __('kb.no_grounded_answer');

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $request->user()?->id,
            question: $question,
            answer: $answer,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $result->totalChunks(),
            sources: $this->collectSources($result),
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'refusal_reason' => $reason,
                'confidence' => 0,
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
            ],
        ));

        return response()->json([
            'answer' => $answer,
            'citations' => [],
            'confidence' => 0,
            'refusal_reason' => $reason,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'chunks_used' => $result->totalChunks(),
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                'latency_ms' => $latencyMs,
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
                'refused_early' => false,  // LLM was called; only RETRIEVAL was sufficient.
            ],
        ]);
    }

    /**
     * Build citations grouped by source document with an `origin` marker so
     * the UI can label primary / related / rejected differently.
     *
     * @return array<int, array{document_id: ?int, title: string, source_path: ?string, headings: list<string>, chunks_used: int, origin: string}>
     */
    private function buildCitations(SearchResult $result): array
    {
        $citations = [];
        $this->appendCitationsFor($result->primary, 'primary', $citations);
        $this->appendCitationsFor($result->expanded, 'related', $citations);
        $this->appendCitationsFor($result->rejected, 'rejected', $citations);
        return array_values($citations);
    }

    /**
     * @param  Collection<int, array>  $chunks
     * @param  array<string, array>  $citations
     */
    private function appendCitationsFor(Collection $chunks, string $origin, array &$citations): void
    {
        foreach ($chunks->groupBy('document.source_path') as $sourcePath => $group) {
            $key = $origin . ':' . $sourcePath;
            if (isset($citations[$key])) {
                continue;
            }
            $first = $group->first();
            $citations[$key] = [
                'document_id' => data_get($first, 'document.id'),
                'title' => data_get($first, 'document.title', 'Untitled'),
                'source_path' => data_get($first, 'document.source_path'),
                'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                'chunks_used' => $group->count(),
                'origin' => $origin,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function collectSources(SearchResult $result): array
    {
        $all = $result->primary->concat($result->expanded)->concat($result->rejected);
        return $all->pluck('document.source_path')->filter()->unique()->values()->all();
    }
}
