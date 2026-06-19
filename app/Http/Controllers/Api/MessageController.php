<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\FinOps\ChatTraceContext;
use App\FinOps\ChatTurnCostResolver;
use App\Mcp\Client\McpToolCallingService;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\FewShotService;
use App\Services\Kb\Grounding\ConfidenceCalculator;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Canonical\CanonicalType;
use App\Support\Kb\SourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    /**
     * T3.4 — same sentinel as KbChatController. Centralizing on a class
     * constant per controller (not pulling into a shared trait yet) so
     * each controller's contract with the prompt is explicit on the
     * surface that's most likely to be touched together.
     */
    private const SELF_REFUSAL_SENTINEL = '__NO_GROUNDED_ANSWER__';

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'metadata', 'rating', 'created_at']);

        return response()->json($messages);
    }

    public function store(
        Request $request,
        Conversation $conversation,
        AiManager $ai,
        McpToolCallingService $toolCallingService,
        ChatRetrievalService $retrieval,
        ChatLogManager $chatLog,
        FewShotService $fewShot,
        ConfidenceCalculator $confidence,
    ): JsonResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate(array_merge(
            ['content' => ['required', 'string', 'max:10000']],
            // T2.7 — accept the same `filters.*` payload shape as
            // /api/kb/chat (validated by KbChatRequest). Inline here
            // rather than via FormRequest because MessageController
            // already validates `content` and switching to a custom
            // FormRequest would touch every route binding. The
            // resulting filter rule set is duplicated with
            // KbChatRequest::rules() — refactor to a shared trait
            // is a follow-up (M4 consolidamento).
            $this->retrievalFilterRules(),
        ));

        $question = $validated['content'];
        $projectKey = $conversation->project_key;
        $userId = $request->user()->id;
        $filters = $this->buildRetrievalFilters($request, $projectKey);

        // 1. Save user message
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        $startTime = microtime(true);

        // 2. Load conversation history
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // 3. RAG: v8.1 P0.2 — unified retrieval. The conversation flow now
        // runs the SAME searchWithContext() path as /api/kb/chat (primary +
        // graph-expanded + rejected-approach context) via the shared
        // ChatRetrievalService, so the same question yields identical
        // grounding context + citations across the sync / stream / chat
        // channels. T2.7 threads the user-selected filters; with no filters
        // it falls back to the legacy single-project DTO.
        $result = $retrieval->retrieve($question, $projectKey, $filters);
        $chunks = $result->primary;

        // 3b. T3.3 — deterministic refusal short-circuit. If too few chunks
        // pass the shared grounding gate (rerank_score OR vector floor, read
        // shape-agnostically — see RetrievalGrounding), save a refusal
        // assistant message and return WITHOUT calling the LLM.
        if ($retrieval->shouldRefuse($result)) {
            return $this->refusalResponse(
                request: $request,
                chatLog: $chatLog,
                conversation: $conversation,
                question: $question,
                projectKey: $projectKey,
                userId: $userId,
                startTime: $startTime,
                reason: 'no_relevant_context',
            );
        }

        // 4. Get few-shot examples from positively-rated past answers
        $fewShotExamples = $fewShot->getExamples($userId, $projectKey);

        // 5. Build system prompt with RAG context + few-shot examples.
        // v8.1 P0.2 — pass the typed blocks (expanded + rejected) so the
        // conversation prompt renders the ⚠ REJECTED / 📎 RELATED sections
        // identically to /api/kb/chat.
        $systemPrompt = view('prompts.kb_rag', array_merge(
            $retrieval->promptContext($result),
            ['projectKey' => $projectKey, 'fewShotExamples' => $fewShotExamples],
        ))->render();

        // 6. Send full history to AI provider
        // v8.16/W3 — one trace id per turn covering the whole MCP tool loop, so
        // every metered call in the loop + this turn's chat_logs row share it.
        $traceId = ChatTraceContext::newTraceId();
        $aiResponse = ChatTraceContext::within($traceId, fn (): AiResponse => $toolCallingService->chatWithTools(
            systemPrompt: $systemPrompt,
            messages: $history,
            options: [],
            user: $request->user(),
            context: [
                'conversation_id' => $conversation->id,
                'message_id' => $userMessage->id,
            ],
        ));
        $toolCalls = $this->summarizeToolCallsForMetadata($aiResponse->toolCalls);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Real per-turn cost server-side (cache-warm from the metering hook that
        // ran inside the trace context). Null when finops metering is off (R27).
        $cost = app(ChatTurnCostResolver::class)->resolve(
            provider: $aiResponse->provider,
            model: $aiResponse->model,
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            promptText: $question,
            completionText: $aiResponse->content,
            traceId: $traceId,
        );

        // 6b. T3.4 — sentinel detection. Mirrors KbChatController behaviour.
        // The LLM emits `__NO_GROUNDED_ANSWER__` when it can't ground the
        // answer in the provided context (prompt instruction in
        // prompts/kb_rag.blade.php "Refusal Protocol" section). Compare
        // with === after trim — substring matching would misfire on
        // partial answers that mention the sentinel as part of the body.
        if ($this->isSelfRefusalSentinel($aiResponse->content)) {
            return $this->convertSentinelToRefusal(
                request: $request,
                chatLog: $chatLog,
                conversation: $conversation,
                question: $question,
                projectKey: $projectKey,
                userId: $userId,
                chunks: $chunks,
                aiResponse: $aiResponse,
                latencyMs: $latencyMs,
                traceId: $traceId,
            );
        }

        // 7. Build citations (shared origin-aware builder).
        $citations = $retrieval->buildCitations($result);

        // 7b. T3.5 — composite confidence score for the grounded answer.
        // Same formula as KbChatController; identical signal across both
        // chat surfaces lets the dashboard aggregate without de-duping
        // by route. The conversation flow has a thinner meta surface
        // (no search_strategy / retrieval_stats — search() doesn't
        // expose them) but the score itself is fully comparable.
        $confidenceScore = $confidence->compute(
            primaryChunks: $chunks,
            minThreshold: (float) config('kb.refusal.min_chunk_similarity', 0.45),
            answerWords: str_word_count($aiResponse->content),
            citationsCount: count($citations),
        );

        // 8. Save assistant message
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $aiResponse->content,
            // T3.5 — populate the dedicated columns (T3.1) so SQL
            // aggregations don't have to scan messages.metadata JSON.
            'confidence' => $confidenceScore,
            'refusal_reason' => null,
            'metadata' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'prompt_tokens' => $aiResponse->promptTokens,
                'completion_tokens' => $aiResponse->completionTokens,
                'total_tokens' => $aiResponse->totalTokens,
                // v8.16/W3 — server-resolved per-turn cost (R27 additive; null when
                // finops metering is off). FE reads these instead of computing.
                'cost' => $cost?->cost,
                'cost_currency' => $cost?->currency,
                'chunks_count' => $chunks->count(),
                'latency_ms' => $latencyMs,
                'citations' => $citations,
                'few_shot_count' => count($fewShotExamples),
                'tool_calls_count' => count($toolCalls),
                'tool_calls' => $toolCalls,
                // T3.5 — confidence mirrored in metadata so the FE can
                // render the badge from a single read of the message
                // payload (no separate /confidence endpoint needed).
                'confidence' => $confidenceScore,
                'refusal_reason' => null,
            ],
        ]);

        $conversation->touch();

        // 9. Log chat interaction (if enabled)
        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $userId,
            question: $question,
            answer: $aiResponse->content,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $chunks->count(),
            sources: $chunks->pluck('document.source_path')->filter()->unique()->values()->all(),
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'few_shot_count' => count($fewShotExamples),
                'citations_count' => count($citations),
                'tool_calls_count' => count($toolCalls),
                'tool_calls' => $toolCalls,
            ],
            traceId: $traceId,
        ));

        return response()->json([
            'id' => $assistantMessage->id,
            'role' => 'assistant',
            'content' => $aiResponse->content,
            'metadata' => $assistantMessage->metadata,
            'rating' => null,
            // T3.5 — top-level confidence + refusal_reason for FE shape
            // uniformity (same surface as /api/kb/chat happy path).
            'confidence' => $confidenceScore,
            'refusal_reason' => null,
            'created_at' => $assistantMessage->created_at,
        ]);
    }

    /**
     * T3.3 — refusal payload for the conversation flow.
     *
     * Persists an assistant message with role='assistant', the i18n
     * "no grounded answer" body, and metadata.refusal_reason +
     * metadata.confidence so the FE can render it as a RefusalNotice
     * (T3.7, deferred). Also writes the chat_log row so analytics see
     * the refusal turn. The DB-level columns `messages.refusal_reason`
     * + `messages.confidence` (T3.1) get populated alongside the
     * metadata blob — keeps SQL aggregation cheap without scanning JSON.
     */
    private function refusalResponse(
        Request $request,
        ChatLogManager $chatLog,
        Conversation $conversation,
        string $question,
        ?string $projectKey,
        int $userId,
        float $startTime,
        string $reason,
    ): JsonResponse {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $answer = $this->localizedRefusalMessage($reason);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'confidence' => 0,
            'refusal_reason' => $reason,
            'metadata' => [
                'provider' => 'none',
                'model' => 'none',
                // v8.16/W3 — R27 shape uniformity (null: a pre-LLM refusal makes no
                // priced call).
                'cost' => null,
                'cost_currency' => null,
                'chunks_count' => 0,
                'latency_ms' => $latencyMs,
                'citations' => [],
                'refusal_reason' => $reason,
                'tool_calls_count' => 0,
                'tool_calls' => [],
                'confidence' => 0,
            ],
        ]);

        $conversation->touch();

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $userId,
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
                'tool_calls_count' => 0,
                'tool_calls' => [],
            ],
        ));

        // v8.8/W4 — record the content gap (side-channel; never breaks chat).
        app(\App\Services\Kb\Analytics\SearchFailureRecorder::class)
            ->record($projectKey, $question, $reason);

        return response()->json([
            'id' => $assistantMessage->id,
            'role' => 'assistant',
            'content' => $answer,
            'metadata' => $assistantMessage->metadata,
            'rating' => null,
            'confidence' => 0,
            'refusal_reason' => $reason,
            'created_at' => $assistantMessage->created_at,
        ]);
    }

    /**
     * T3.4 — exact-match sentinel detection (mirror of
     * KbChatController::isSelfRefusalSentinel). Whitespace tolerated;
     * substring matches are NOT treated as refusal.
     */
    private function isSelfRefusalSentinel(string $content): bool
    {
        return trim($content) === self::SELF_REFUSAL_SENTINEL;
    }

    /**
     * T3.8-BE — per-reason i18n with fallback (mirror of
     * KbChatController::localizedRefusalMessage). Uses
     * `kb.refusal.{reason}` first, degrades to `kb.no_grounded_answer`
     * if the per-reason key is missing. The translator returns the raw
     * key on a miss — we use that as the sentinel and never leak the
     * key to the user.
     */
    private function localizedRefusalMessage(string $reason): string
    {
        $perReasonKey = "kb.refusal.{$reason}";
        $perReasonMessage = __($perReasonKey);

        if (is_string($perReasonMessage) && $perReasonMessage !== $perReasonKey) {
            return $perReasonMessage;
        }

        return (string) __('kb.no_grounded_answer');
    }

    /**
     * T3.4 — sentinel-to-refusal conversion for the conversation flow.
     *
     * Differs from {@see refusalResponse()} (which handles `no_relevant_context`)
     * on two axes:
     *   1. `refusal_reason` is `'llm_self_refusal'` — retrieval succeeded
     *      but the LLM declared the chunks insufficient. The split lets
     *      the dashboard distinguish threshold-too-lenient from
     *      threshold-too-strict.
     *   2. The chat-log row + assistant message metadata carry the REAL
     *      provider/model/token counts (the LLM call was paid in full).
     *      Latency reflects retrieval+LLM, not retrieval-only.
     *
     * The assistant message body is replaced with the i18n placeholder
     * (NOT the literal sentinel) so the FE renders RefusalNotice instead
     * of leaking the protocol token to the user.
     */
    private function convertSentinelToRefusal(
        Request $request,
        ChatLogManager $chatLog,
        Conversation $conversation,
        string $question,
        ?string $projectKey,
        int $userId,
        Collection $chunks,
        AiResponse $aiResponse,
        int $latencyMs,
        ?string $traceId = null,
    ): JsonResponse {
        $reason = 'llm_self_refusal';
        $answer = $this->localizedRefusalMessage($reason);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'confidence' => 0,
            'refusal_reason' => $reason,
            'metadata' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'prompt_tokens' => $aiResponse->promptTokens,
                'completion_tokens' => $aiResponse->completionTokens,
                'total_tokens' => $aiResponse->totalTokens,
                // v8.16/W3 — R27 shape uniformity (null on refusal; the turn's real
                // cost is persisted on the chat_logs row by the driver).
                'cost' => null,
                'cost_currency' => null,
                'chunks_count' => $chunks->count(),
                'latency_ms' => $latencyMs,
                'citations' => [],
                'tool_calls_count' => 0,
                'tool_calls' => [],
                'refusal_reason' => $reason,
                'confidence' => 0,
            ],
        ]);

        $conversation->touch();

        $chatLog->log(new ChatLogEntry(
            sessionId: $request->header('X-Session-Id', (string) Str::uuid()),
            userId: $userId,
            question: $question,
            answer: $answer,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $chunks->count(),
            sources: $chunks->pluck('document.source_path')->filter()->unique()->values()->all(),
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            totalTokens: $aiResponse->totalTokens,
            latencyMs: $latencyMs,
            clientIp: $request->ip(),
            userAgent: $request->userAgent(),
            extra: [
                'refusal_reason' => $reason,
                'tool_calls_count' => 0,
                'tool_calls' => [],
                'confidence' => 0,
            ],
            traceId: $traceId,
        ));

        // v8.8/W4 — record the content gap (LLM self-refusal). Side-channel.
        app(\App\Services\Kb\Analytics\SearchFailureRecorder::class)
            ->record($projectKey, $question, $reason);

        return response()->json([
            'id' => $assistantMessage->id,
            'role' => 'assistant',
            'content' => $answer,
            'metadata' => $assistantMessage->metadata,
            'rating' => null,
            'confidence' => 0,
            'refusal_reason' => $reason,
            'created_at' => $assistantMessage->created_at,
        ]);
    }

    /**
     * T2.7 — validation rules for the optional `filters.*` payload
     * mirroring `KbChatRequest::rules()`'s filter-only subset. Drives
     * `$request->validate()` in `store()`. Kept inline (not extracted
     * to a shared trait) so each controller's contract stays auditable
     * in one file; M4 consolidamento can refactor to a trait later.
     *
     * @return array<string, array<int, string>>
     */
    private function retrievalFilterRules(): array
    {
        $sourceTypeValues = collect(SourceType::cases())
            ->reject(fn (SourceType $t) => $t === SourceType::UNKNOWN)
            ->map(fn (SourceType $t) => $t->value)
            ->all();
        $sourceTypeRule = 'in:' . implode(',', $sourceTypeValues);

        $canonicalTypeValues = array_map(
            fn (CanonicalType $t) => $t->value,
            CanonicalType::cases(),
        );
        $canonicalTypeRule = 'in:' . implode(',', $canonicalTypeValues);

        return [
            'filters' => ['nullable', 'array'],
            'filters.project_keys' => ['nullable', 'array'],
            'filters.project_keys.*' => ['string', 'max:120'],
            'filters.tag_slugs' => ['nullable', 'array'],
            'filters.tag_slugs.*' => ['string', 'max:120'],
            'filters.source_types' => ['nullable', 'array'],
            'filters.source_types.*' => ['string', $sourceTypeRule],
            'filters.canonical_types' => ['nullable', 'array'],
            'filters.canonical_types.*' => ['string', $canonicalTypeRule],
            'filters.connector_types' => ['nullable', 'array'],
            'filters.connector_types.*' => ['string', 'max:120'],
            'filters.doc_ids' => ['nullable', 'array'],
            'filters.doc_ids.*' => ['integer', 'min:1'],
            'filters.collection_id' => ['nullable', 'integer', 'min:1'],
            'filters.folder_globs' => ['nullable', 'array'],
            'filters.folder_globs.*' => ['string', 'max:255'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.languages' => ['nullable', 'array'],
            'filters.languages.*' => ['string', 'size:2'],
        ];
    }

    /**
     * T2.7 — constructs a {@see RetrievalFilters} DTO from the validated
     * request payload. When no `filters` block is present (legacy
     * composer), returns the legacy single-project DTO so retrieval
     * behaviour matches pre-T2.7 callers bit-for-bit.
     */
    private function buildRetrievalFilters(Request $request, ?string $conversationProject): RetrievalFilters
    {
        $f = $request->input('filters', []) ?? [];

        if ($f === []) {
            return RetrievalFilters::forLegacyProject($conversationProject);
        }

        $projectKeys = array_key_exists('project_keys', $f) && is_array($f['project_keys'])
            ? array_values(array_map('strval', $f['project_keys']))
            : ($conversationProject !== null && $conversationProject !== '' ? [(string) $conversationProject] : []);

        return new RetrievalFilters(
            projectKeys: $projectKeys,
            tagSlugs: array_values(array_map('strval', $f['tag_slugs'] ?? [])),
            sourceTypes: array_values(array_map('strval', $f['source_types'] ?? [])),
            canonicalTypes: array_values(array_map('strval', $f['canonical_types'] ?? [])),
            connectorTypes: array_values(array_map('strval', $f['connector_types'] ?? [])),
            docIds: array_values(array_map('intval', $f['doc_ids'] ?? [])),
            collectionId: isset($f['collection_id']) ? (int) $f['collection_id'] : null,
            folderGlobs: array_values(array_map('strval', $f['folder_globs'] ?? [])),
            dateFrom: $this->normaliseDate($f['date_from'] ?? null),
            dateTo: $this->normaliseDate($f['date_to'] ?? null),
            languages: array_values(array_map(
                fn ($v) => strtolower((string) $v),
                $f['languages'] ?? [],
            )),
        );
    }

    private function normaliseDate(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function summarizeToolCallsForMetadata(array $toolCalls): array
    {
        return array_map(
            static fn(array $toolCall): array => [
                'id' => (string) ($toolCall['id'] ?? ''),
                'name' => (string) ($toolCall['name'] ?? ''),
                'status' => (string) ($toolCall['status'] ?? 'completed'),
                'server_id' => $toolCall['server_id'] ?? null,
                'server_name' => $toolCall['server_name'] ?? null,
                'error' => $toolCall['error'] ?? null,
            ],
            $toolCalls,
        );
    }
}
