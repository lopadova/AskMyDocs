<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Ai\StreamChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\FewShotService;
use App\Services\Kb\Grounding\ConfidenceCalculator;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Canonical\CanonicalType;
use App\Support\Kb\SourceType;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v4.0/W3.1 — server-sent-events streaming variant of MessageController.
 *
 * The synchronous {@see MessageController::store()} returns the assistant
 * reply as a single JSON response after the AI provider finishes; this
 * controller returns a `text/event-stream` response that emits
 * `StreamChunk` SSE frames as the provider produces tokens. The Vercel
 * AI SDK UI on the FE consumes the stream via `useChat()` and renders
 * tokens progressively. Per `docs/v4-platform/PLAN-W3-vercel-chat-migration.md`
 * §6 + `project_v40_w3_decisions` (Lorenzo confirmed Option B on
 * 2026-04-30).
 *
 * Wire format (one SSE message per chunk):
 *
 *     data: {"type":"source", ...}\n\n
 *     data: {"type":"text-delta","textDelta":"..."}\n\n
 *     data: {"type":"data-confidence","confidence":82,"tier":"high"}\n\n
 *     data: {"type":"finish","finishReason":"stop","usage":{...}}\n\n
 *
 * Refusal stream variant (no `text-delta` events):
 *
 *     data: {"type":"data-refusal","reason":"no_relevant_context",...}\n\n
 *     data: {"type":"data-confidence","confidence":null,"tier":"refused"}\n\n
 *     data: {"type":"finish","finishReason":"refusal","usage":{...}}\n\n
 *
 * Persistence semantics: the user message is saved BEFORE the stream
 * starts (so a client that disconnects mid-stream still has the user
 * turn in the conversation). The assistant message + chat-log row are
 * saved AFTER the stream completes — the streaming callback collects
 * the full text from the emitted `text-delta` chunks and persists it
 * once the provider's `finish` event fires.
 *
 * The synchronous {@see MessageController} stays in place and keeps
 * working — it's still used by the legacy chat path and by PHPUnit
 * feature tests. W3.2 (FE migration) switches the SPA's call site
 * from POST /messages to POST /messages/stream.
 *
 * Some of the private helpers (validation rules, RetrievalFilters
 * construction, citation flattening, refusal localization) duplicate
 * MessageController's helpers. W3.3 (cleanup) extracts them into a
 * shared trait — keeping them duplicated for W3.1 minimizes the risk
 * of breaking the synchronous controller's contract while wiring the
 * streaming variant.
 */
class MessageStreamController extends Controller
{
    /**
     * Mirror of MessageController::SELF_REFUSAL_SENTINEL — the LLM
     * emits this token when it can't ground the answer in the
     * provided context. Detected via exact-match (after trim) per
     * R26 / refusal-not-error-ux skill.
     */
    private const SELF_REFUSAL_SENTINEL = '__NO_GROUNDED_ANSWER__';

    public function store(
        Request $request,
        Conversation $conversation,
        AiManager $ai,
        KbSearchService $search,
        ChatLogManager $chatLog,
        FewShotService $fewShot,
        ConfidenceCalculator $confidence,
    ): StreamedResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate(array_merge(
            ['content' => ['required', 'string', 'max:10000']],
            $this->retrievalFilterRules(),
        ));

        $question = $validated['content'];
        $projectKey = $conversation->project_key;
        $userId = $request->user()->id;
        $filters = $this->buildRetrievalFilters($request, $projectKey);

        // Persist user message BEFORE the stream starts. If the client
        // disconnects mid-stream the user turn is still saved and the
        // next request can replay the conversation.
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        $startTime = microtime(true);

        // Build conversation history INCLUDING the user message we
        // just saved (so the LLM sees the new turn).
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // RAG search — same call shape as MessageController so retrieval
        // behaviour is identical between sync and stream paths.
        $chunks = $search->search(
            query: $question,
            projectKey: $projectKey,
            limit: config('kb.default_limit', 8),
            minSimilarity: config('kb.default_min_similarity', 0.30),
            filters: $filters,
        );

        $refusalThreshold = (float) config('kb.refusal.min_chunk_similarity', 0.45);
        $refusalMinChunks = (int) config('kb.refusal.min_chunks_required', 1);
        $grounded = $chunks->filter(
            fn ($c) => (float) ($c->vector_score ?? 0) >= $refusalThreshold
        );

        // Capture session id at controller entry — header() reads from
        // request state which we want to lock before the streaming
        // callback runs (callback fires after the request has nominally
        // finished from the browser's perspective).
        $sessionId = $request->header('X-Session-Id', (string) Str::uuid());
        $clientIp = $request->ip();
        $userAgent = $request->userAgent();

        if ($grounded->count() < $refusalMinChunks) {
            return $this->streamRefusal(
                conversation: $conversation,
                chatLog: $chatLog,
                question: $question,
                projectKey: $projectKey,
                userId: $userId,
                startTime: $startTime,
                reason: 'no_relevant_context',
                sessionId: $sessionId,
                clientIp: $clientIp,
                userAgent: $userAgent,
            );
        }

        $fewShotExamples = $fewShot->getExamples($userId, $projectKey);

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $chunks,
            'projectKey' => $projectKey,
            'fewShotExamples' => $fewShotExamples,
        ])->render();

        $citations = $this->buildCitations($chunks);

        return $this->streamHappyPath(
            ai: $ai,
            confidence: $confidence,
            conversation: $conversation,
            chatLog: $chatLog,
            systemPrompt: $systemPrompt,
            history: $history,
            chunks: $chunks,
            citations: $citations,
            question: $question,
            projectKey: $projectKey,
            userId: $userId,
            startTime: $startTime,
            fewShotCount: count($fewShotExamples),
            sessionId: $sessionId,
            clientIp: $clientIp,
            userAgent: $userAgent,
        );
    }

    /**
     * Stream the refusal path: no LLM call, emit `data-refusal` +
     * `data-confidence` + `finish`, persist the refusal message + chat
     * log. Mirrors {@see MessageController::refusalResponse()} but as
     * an SSE stream.
     *
     * @param  string  $reason  one of `no_relevant_context` /
     *                          `llm_self_refusal` per the FE
     *                          RefusalNotice rendering vocabulary.
     */
    private function streamRefusal(
        Conversation $conversation,
        ChatLogManager $chatLog,
        string $question,
        ?string $projectKey,
        int $userId,
        float $startTime,
        string $reason,
        string $sessionId,
        ?string $clientIp,
        ?string $userAgent,
    ): StreamedResponse {
        $answer = $this->localizedRefusalMessage($reason);

        return $this->streamingResponse(function () use (
            $reason, $answer, $conversation, $chatLog, $question,
            $projectKey, $userId, $startTime, $sessionId, $clientIp, $userAgent
        ): void {
            // The data-refusal chunk carries the LOCALIZED body so the
            // FE renders it verbatim — BE owns localization (R24).
            $this->emit(StreamChunk::dataRefusal(
                reason: $reason,
                body: $answer,
            ));
            $this->emit(StreamChunk::dataConfidence(null, 'refused'));
            $this->emit(StreamChunk::finish(
                finishReason: 'refusal',
                promptTokens: 0,
                completionTokens: 0,
            ));

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $answer,
                'confidence' => 0,
                'refusal_reason' => $reason,
                'metadata' => [
                    'provider' => 'none',
                    'model' => 'none',
                    'chunks_count' => 0,
                    'latency_ms' => $latencyMs,
                    'citations' => [],
                    'refusal_reason' => $reason,
                    'confidence' => 0,
                    'streamed' => true,
                ],
            ]);

            $conversation->touch();

            $chatLog->log(new ChatLogEntry(
                sessionId: $sessionId,
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
                clientIp: $clientIp,
                userAgent: $userAgent,
                extra: [
                    'refusal_reason' => $reason,
                    'confidence' => 0,
                    'streamed' => true,
                ],
            ));
        });
    }

    /**
     * Stream the happy path: emit `source` chunks for citations BEFORE
     * any text, call `AiManager::chatStream()` and forward each chunk,
     * emit `data-confidence`, then emit the `finish` event. Persist the
     * full assistant message + chat-log row after the stream closes.
     *
     * Sentinel handling: if the LLM emits `__NO_GROUNDED_ANSWER__` as
     * its full response, the stream still completes its `text-delta`
     * + `finish` events, but the persistence path classifies the turn
     * as `llm_self_refusal` (mirror of MessageController's
     * convertSentinelToRefusal). The FE inspects the persisted message
     * via `useChat({ onFinish })` and re-renders as RefusalNotice if
     * `refusal_reason` is set — same pattern as the synchronous flow.
     *
     * @param  Collection<int, Message>|Collection<int, mixed>  $chunks
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array<string, mixed>>  $citations
     */
    private function streamHappyPath(
        AiManager $ai,
        ConfidenceCalculator $confidence,
        Conversation $conversation,
        ChatLogManager $chatLog,
        string $systemPrompt,
        array $history,
        Collection $chunks,
        array $citations,
        string $question,
        ?string $projectKey,
        int $userId,
        float $startTime,
        int $fewShotCount,
        string $sessionId,
        ?string $clientIp,
        ?string $userAgent,
    ): StreamedResponse {
        return $this->streamingResponse(function () use (
            $ai, $confidence, $conversation, $chatLog, $systemPrompt, $history,
            $chunks, $citations, $question, $projectKey, $userId, $startTime,
            $fewShotCount, $sessionId, $clientIp, $userAgent
        ): void {
            // Emit `source` chunks BEFORE any `text-delta` per the wire
            // format invariant (PLAN §6.1). The FE's CitationsPopover
            // renders the chips as the events arrive — popover is
            // visible by the time the answer text starts streaming.
            foreach ($citations as $citation) {
                $this->emit(StreamChunk::source(
                    sourceId: 'doc-' . ($citation['document_id'] ?? 'unknown'),
                    title: (string) ($citation['title'] ?? 'Untitled'),
                    url: $this->citationUrl($citation, $projectKey),
                    origin: 'primary',
                ));
            }

            // Stream the LLM response. Each provider's chatStream()
            // yields StreamChunk instances; we re-emit them as SSE
            // frames AND collect the text into a buffer for
            // post-stream persistence.
            $assistantContent = '';
            $finishChunk = null;
            foreach ($ai->chatStream($systemPrompt, $history) as $chunk) {
                if ($chunk->type === StreamChunk::TYPE_TEXT_DELTA) {
                    $assistantContent .= (string) ($chunk->payload['textDelta'] ?? '');
                }
                if ($chunk->type === StreamChunk::TYPE_FINISH) {
                    // Capture the finish chunk but DON'T re-emit it yet
                    // — we still need to emit the data-confidence chunk
                    // first (computed once we have the full content).
                    $finishChunk = $chunk;
                    continue;
                }
                $this->emit($chunk);
            }

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            // Sentinel detection — same exact-match rule as
            // MessageController. If the LLM refused, the persisted
            // message gets `refusal_reason='llm_self_refusal'` and the
            // FE treats it as a refusal turn even though the stream
            // emitted text-delta(s). The FE refusal-detection path
            // looks at the persisted message metadata — not the
            // stream chunks — so this works without changing the
            // wire format.
            $isSelfRefusal = $this->isSelfRefusalSentinel($assistantContent);
            $refusalReason = $isSelfRefusal ? 'llm_self_refusal' : null;

            // Build provider/model/usage from the captured finish event.
            // Fallback to `unknown` when a provider didn't emit it
            // (defensive — the StreamChunk::finish() factory always
            // produces a usable shape, but iterating provider impls
            // could in theory drop it).
            $promptTokens = $finishChunk?->payload['usage']['promptTokens'] ?? null;
            $completionTokens = $finishChunk?->payload['usage']['completionTokens'] ?? null;
            $finishReason = $finishChunk?->payload['finishReason'] ?? 'stop';
            $providerName = config('ai.default', 'openai');
            $providerInstance = $ai->provider();
            $modelName = $this->resolveStreamingModel($providerInstance);

            $confidenceScore = $isSelfRefusal ? 0 : $confidence->compute(
                primaryChunks: $chunks,
                minThreshold: (float) config('kb.refusal.min_chunk_similarity', 0.45),
                answerWords: str_word_count($assistantContent),
                citationsCount: count($citations),
            );
            $tier = $this->confidenceTier($isSelfRefusal ? null : $confidenceScore);

            // Emit data-confidence + finish AFTER the LLM stream.
            $this->emit(StreamChunk::dataConfidence(
                confidence: $isSelfRefusal ? null : $confidenceScore,
                tier: $tier,
            ));
            $this->emit(StreamChunk::finish(
                finishReason: $isSelfRefusal ? 'refusal' : $finishReason,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ));

            // Persist assistant message AFTER the stream emits — the
            // FE useChat({ onFinish }) callback fetches the persisted
            // shape and reconciles via the optimistic dedupe path.
            $persistedContent = $isSelfRefusal
                ? $this->localizedRefusalMessage('llm_self_refusal')
                : $assistantContent;

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $persistedContent,
                'confidence' => $confidenceScore,
                'refusal_reason' => $refusalReason,
                'metadata' => [
                    'provider' => $providerName,
                    'model' => $modelName,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => ($promptTokens ?? 0) + ($completionTokens ?? 0) ?: null,
                    'chunks_count' => $chunks->count(),
                    'latency_ms' => $latencyMs,
                    'citations' => $isSelfRefusal ? [] : $citations,
                    'few_shot_count' => $fewShotCount,
                    'confidence' => $confidenceScore,
                    'refusal_reason' => $refusalReason,
                    'streamed' => true,
                ],
            ]);

            $conversation->touch();

            $chatLog->log(new ChatLogEntry(
                sessionId: $sessionId,
                userId: $userId,
                question: $question,
                answer: $persistedContent,
                projectKey: $projectKey,
                aiProvider: $providerName,
                aiModel: $modelName,
                chunksCount: $chunks->count(),
                sources: $chunks->pluck('document.source_path')->filter()->unique()->values()->all(),
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: ($promptTokens ?? 0) + ($completionTokens ?? 0) ?: null,
                latencyMs: $latencyMs,
                clientIp: $clientIp,
                userAgent: $userAgent,
                extra: [
                    'few_shot_count' => $fewShotCount,
                    'citations_count' => $isSelfRefusal ? 0 : count($citations),
                    'refusal_reason' => $refusalReason,
                    'confidence' => $confidenceScore,
                    'streamed' => true,
                ],
            ));
        });
    }

    /**
     * Build the SSE response wrapper: correct headers + the streaming
     * callback. The callback closes over the per-request state and
     * runs after the response head has been flushed to the browser.
     */
    private function streamingResponse(\Closure $callback): StreamedResponse
    {
        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // X-Accel-Buffering=no disables nginx buffering on prod
            // deployments behind nginx — without it the browser sees
            // chunks arriving in big batches instead of progressively.
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Emit one chunk to the SSE stream + flush so the browser receives
     * it immediately. PHP output buffering is disabled by Symfony's
     * StreamedResponse, but we call `flush()` explicitly to push
     * through any intermediate buffers (FastCGI, the SAPI's own
     * buffer).
     */
    private function emit(StreamChunk $chunk): void
    {
        echo $chunk->toSseFrame();
        // Drain output buffers so the browser receives the SSE event
        // immediately. The `ob_get_level() > 0` guard ensures we only
        // call `ob_flush()` when there's an active buffer (without
        // that guard, ob_flush emits a "no buffer to flush" notice).
        // No error-suppression operator on the call: R7 forbids it
        // anywhere in the codebase, and the level guard above makes
        // suppression unnecessary anyway. If a notice ever surfaces
        // from this line under load it means the SAPI has buffer
        // state worth investigating, not something to hide.
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Tier mapping for the data-confidence chunk. Mirrors the FE
     * ConfidenceBadge thresholds (T3.5):
     *   null      → refused
     *   0–49      → low
     *   50–79     → moderate
     *   ≥ 80      → high
     */
    private function confidenceTier(?int $score): string
    {
        if ($score === null) {
            return 'refused';
        }
        if ($score >= 80) {
            return 'high';
        }
        if ($score >= 50) {
            return 'moderate';
        }
        return 'low';
    }

    /**
     * Resolve the model name to record in chat logs / message metadata.
     * For streaming we don't have an `AiResponse` object (the SDK only
     * returns chunks), so we read the configured default model for the
     * resolved provider. Defensive default `unknown` when the config
     * key is absent — chat-log persistence should never break the
     * stream.
     */
    private function resolveStreamingModel(\App\Ai\AiProviderInterface $provider): string
    {
        $providerName = $provider->name();
        $modelKey = "ai.providers.{$providerName}.chat_model";
        $configured = config($modelKey);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        // Some providers (Regolo) nest the chat model deeper.
        $nested = config("ai.providers.{$providerName}.models.chat.default");
        if (is_string($nested) && $nested !== '') {
            return $nested;
        }
        return 'unknown';
    }

    /**
     * Best-effort URL for a citation chunk. Today the FE doesn't
     * navigate from the citation chip directly, but we emit the URL
     * so the CitationsPopover can render an anchor for the user. When
     * we don't have a clean URL (no `source_path`), emit null and let
     * the FE fall back to non-anchor rendering.
     */
    private function citationUrl(array $citation, ?string $projectKey): ?string
    {
        $sourcePath = $citation['source_path'] ?? null;
        if (! is_string($sourcePath) || $sourcePath === '' || $projectKey === null) {
            return null;
        }
        return "/app/admin/kb/{$projectKey}/" . trim($sourcePath, '/');
    }

    private function isSelfRefusalSentinel(string $content): bool
    {
        return trim($content) === self::SELF_REFUSAL_SENTINEL;
    }

    /**
     * Per-reason i18n with fallback (mirror of
     * MessageController::localizedRefusalMessage). Uses
     * `kb.refusal.{reason}` first, degrades to `kb.no_grounded_answer`
     * if the per-reason key is missing.
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
            'filters.folder_globs' => ['nullable', 'array'],
            'filters.folder_globs.*' => ['string', 'max:255'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.languages' => ['nullable', 'array'],
            'filters.languages.*' => ['string', 'size:2'],
        ];
    }

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
     * @return list<array<string, mixed>>
     */
    private function buildCitations(Collection $chunks): array
    {
        return $chunks
            ->groupBy('document.source_path')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'document_id' => data_get($first, 'document.id'),
                    'title' => data_get($first, 'document.title', 'Untitled'),
                    'source_path' => data_get($first, 'document.source_path'),
                    'source_type' => data_get($first, 'document.source_type'),
                    'headings' => $group->pluck('heading_path')->filter()->unique()->values()->all(),
                    'chunks_used' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }
}
