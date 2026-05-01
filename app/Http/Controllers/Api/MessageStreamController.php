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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
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
 * Wire format — SDK v6 `UIMessageChunk` shape (one SSE message per
 * chunk). See `StreamChunk` for the canonical envelope.
 *
 *     data: {"type":"start"}\n\n
 *     data: {"type":"source-url","sourceId":"doc-1","url":"...","title":"..."}\n\n
 *     data: {"type":"text-start","id":"text_xxx"}\n\n
 *     data: {"type":"text-delta","id":"text_xxx","delta":"..."}\n\n
 *     data: {"type":"text-end","id":"text_xxx"}\n\n
 *     data: {"type":"data-confidence","data":{"confidence":82,"tier":"high"}}\n\n
 *     data: {"type":"finish","finishReason":"stop","usage":{...}}\n\n
 *
 * Refusal stream variant (no text envelope):
 *
 *     data: {"type":"start"}\n\n
 *     data: {"type":"data-refusal","data":{"reason":"...","body":"...","hint":null}}\n\n
 *     data: {"type":"data-confidence","data":{"confidence":null,"tier":"refused"}}\n\n
 *     data: {"type":"finish","finishReason":"stop","usage":{...}}\n\n
 *
 * Refusal turns finish with `'stop'` (NOT `'refusal'`) per the SDK
 * `FinishReason` union — refusal is an application-level
 * categorization carried on the persisted Message row's
 * `refusal_reason` column AND in the `data-refusal` stream chunk;
 * surfacing it on `finish` would fall outside the SDK union and
 * `useChat()`'s stream parser would reject the chunk.
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
    ): Response {
        // Force JSON for both authorization (403) and validation
        // (422) failures: SSE clients send `Accept: text/event-stream`,
        // which makes Laravel's default `abort(403)` and
        // `$request->validate()` fall back to HTML pages / 302
        // redirects instead of the 403/422 JSON the streaming caller
        // can parse. Returning `JsonResponse` explicitly avoids that
        // path. Unauthenticated requests are handled by the `auth.sse`
        // middleware (App\Http\Middleware\AuthenticateForSse, registered
        // in bootstrap/app.php) upstream of this controller — it
        // returns a deterministic JSON 401 instead of the default
        // 302 → /login redirect, so the streaming caller can re-trigger
        // the SPA auth bootstrap and retry without parsing HTML.
        if ($conversation->user_id !== $request->user()->id) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        try {
            $validated = $request->validate(array_merge(
                ['content' => ['required', 'string', 'max:10000']],
                $this->retrievalFilterRules(),
            ));
        } catch (ValidationException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

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
        // Use `data_get` so the predicate works whether each chunk is
        // an array (KbSearchService::search() return shape) or an
        // object (test fixtures sometimes pass stdClass instances).
        // Same defensive read pattern as the rest of this controller's
        // chunk-touching code.
        $grounded = $chunks->filter(
            fn ($c) => (float) (data_get($c, 'vector_score') ?? 0) >= $refusalThreshold
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
                request: $request,
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
            request: $request,
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
        Request $request,
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

        return $this->streamingResponse($request, function () use (
            $reason, $answer, $conversation, $chatLog, $question,
            $projectKey, $userId, $startTime, $sessionId, $clientIp, $userAgent
        ): void {
            // SDK v6 envelope opener — every UIMessage stream MUST
            // begin with a `start` chunk before any text/data parts
            // so `useChat()` knows a new assistant turn has begun.
            $this->emit(StreamChunk::start());

            // Emit data-refusal + data-confidence FIRST so the FE
            // renders the refusal notice immediately. The terminal
            // `finish` event waits until the Message + chat-log rows
            // are persisted — clients that treat `finish` as
            // "everything is consistent now" (e.g. `useChat({
            // onFinish })` fetching the persisted Message via
            // `/messages` to reconcile its cache) can rely on the
            // ordering.
            $this->emit(StreamChunk::dataRefusal(
                reason: $reason,
                // The data-refusal chunk carries the LOCALIZED body
                // so the FE renders it verbatim — BE owns
                // localization (R24).
                body: $answer,
            ));
            $this->emit(StreamChunk::dataConfidence(null, 'refused'));

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

            // Terminal event AFTER persistence — guarantees that any
            // client refetch triggered by `finish` sees the
            // assistant Message row in the DB. Refusal turns close
            // with `'stop'` per the SDK `FinishReason` union; the
            // application-level "this was a refusal" signal lives
            // on the persisted Message's `refusal_reason` column
            // AND in the upstream `data-refusal` chunk, not on the
            // wire-level finish reason.
            $this->emit(StreamChunk::finish(
                finishReason: 'stop',
                promptTokens: 0,
                completionTokens: 0,
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
     * @param  Collection<int, array<string, mixed>>  $chunks  KB search
     *         results from `KbSearchService::search()` — each element
     *         is an associative array (post-Reranker mapping) with
     *         keys including `vector_score`, `chunk_text`, `document.*`.
     *         NOT `App\Models\Message` instances.
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array<string, mixed>>  $citations
     */
    private function streamHappyPath(
        Request $request,
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
        return $this->streamingResponse($request, function () use (
            $ai, $confidence, $conversation, $chatLog, $systemPrompt, $history,
            $chunks, $citations, $question, $projectKey, $userId, $startTime,
            $fewShotCount, $sessionId, $clientIp, $userAgent
        ): void {
            // SDK v6 envelope opener — every UIMessage stream MUST
            // begin with a `start` chunk before any text/data parts.
            $this->emit(StreamChunk::start());

            // Emit `source-url` chunks BEFORE any `text-delta` per the
            // wire format invariant (PLAN §6.1). The FE's
            // CitationsPopover renders the chips as the events arrive
            // — popover is visible by the time the answer text starts
            // streaming.
            foreach ($citations as $citation) {
                // SDK `source-url` mandates a non-null URL. When the
                // citation has no canonical path (rejected-approach
                // injection, transient sources), emit a synthetic
                // in-app fallback so the FE chip still renders. The
                // `#doc-X` form is harmless as a non-navigable
                // anchor and lets `coerceCitationOrigin()` keep
                // defaulting to `'primary'`.
                $url = $this->citationUrl($citation, $projectKey)
                    ?? '#doc-' . ($citation['document_id'] ?? 'unknown');

                $this->emit(StreamChunk::sourceUrl(
                    sourceId: 'doc-' . ($citation['document_id'] ?? 'unknown'),
                    url: $url,
                    title: (string) ($citation['title'] ?? 'Untitled'),
                ));
            }

            // Stream the LLM response. Providers (via FallbackStreaming
            // or a native chatStream() override) yield the SDK v6
            // text envelope: `text-start` → 1+ `text-delta` →
            // `text-end` → `finish`. We re-emit each non-finish chunk
            // as an SSE frame AND collect the `delta` payloads into a
            // buffer for post-stream persistence. The `finish` chunk
            // is captured (not forwarded) — we emit our own terminal
            // `finish` after persistence + `data-confidence`.
            $assistantContent = '';
            $finishChunk = null;
            foreach ($ai->chatStream($systemPrompt, $history) as $chunk) {
                if ($chunk->type === StreamChunk::TYPE_TEXT_DELTA) {
                    $assistantContent .= (string) ($chunk->payload['delta'] ?? '');
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
            // Defensive read — `$finishChunk?->payload['usage'][...]` is
            // NOT null-safe on its own: the nullsafe applies only to
            // the property access, and the chained array indexing on
            // a null `payload` would still throw. Guard explicitly.
            $promptTokens = null;
            $completionTokens = null;
            $finishReason = 'stop';
            if ($finishChunk !== null) {
                $promptTokens = data_get($finishChunk->payload, 'usage.promptTokens');
                $completionTokens = data_get($finishChunk->payload, 'usage.completionTokens');
                $finishReason = data_get($finishChunk->payload, 'finishReason') ?? 'stop';
            }
            $providerName = config('ai.default', 'openai');
            $providerInstance = $ai->provider();
            $modelName = $this->resolveStreamingModel($providerInstance);

            // Total-tokens computation: null when BOTH counts are null
            // (provider didn't return usage); otherwise the sum, which
            // may legitimately be 0 for tool-only / empty responses.
            // The previous `(($a ?? 0) + ($b ?? 0)) ?: null` collapsed
            // a real `0+0` to null, hiding zero-token turns from
            // chat-log analytics.
            $totalTokens = ($promptTokens === null && $completionTokens === null)
                ? null
                : ($promptTokens ?? 0) + ($completionTokens ?? 0);

            $confidenceScore = $isSelfRefusal ? 0 : $confidence->compute(
                primaryChunks: $chunks,
                minThreshold: (float) config('kb.refusal.min_chunk_similarity', 0.45),
                answerWords: str_word_count($assistantContent),
                citationsCount: count($citations),
            );
            $tier = $this->confidenceTier($isSelfRefusal ? null : $confidenceScore);

            // Emit data-confidence first (the FE renders the badge
            // as soon as the score is known) — but DELAY the
            // terminal `finish` event until AFTER the assistant
            // Message + chat-log rows are persisted. Clients that
            // treat `finish` as "the DB state is now consistent"
            // (e.g. `useChat({ onFinish })` fetching the persisted
            // Message via `/messages` to reconcile its cache) need
            // that ordering to avoid a race window where the
            // refetch returns an empty thread.
            $this->emit(StreamChunk::dataConfidence(
                confidence: $isSelfRefusal ? null : $confidenceScore,
                tier: $tier,
            ));

            // Persist assistant message BEFORE the terminal finish
            // event. The FE `useChat({ onFinish })` callback fetches
            // the persisted shape and reconciles via the optimistic
            // dedupe path (R25).
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
                    'total_tokens' => $totalTokens,
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
                totalTokens: $totalTokens,
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

            // Terminal event AFTER persistence — guarantees that any
            // client refetch triggered by `finish` sees the
            // assistant Message row in the DB. Without this ordering,
            // useChat({ onFinish }) → invalidateQueries(['messages',
            // conversationId]) → GET /messages can land in the
            // window between `finish` arriving and the Message row
            // being committed, returning an empty thread and
            // breaking the optimistic-mutation dedupe path.
            //
            // LLM self-refusals collapse to `'stop'` per SDK union —
            // the application-level "this was a refusal" signal lives
            // on the persisted Message's `refusal_reason` column.
            // Provider finish reasons arrive already normalized by
            // `StreamChunk::normalizeFinishReason()` upstream; we
            // re-normalize defensively in case a future native-streaming
            // path skips the trait.
            $this->emit(StreamChunk::finish(
                finishReason: $isSelfRefusal
                    ? 'stop'
                    : StreamChunk::normalizeFinishReason(is_string($finishReason) ? $finishReason : null),
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ));
        });
    }

    /**
     * Build the SSE response wrapper: correct headers + the streaming
     * callback. The callback closes over the per-request state and
     * runs after the response head has been flushed to the browser.
     *
     * Session-lock release: PHP's session handler holds an exclusive
     * lock on the session row/file for the duration of the request.
     * Under the `auth` (web/session) middleware group that means a
     * long-lived `StreamedResponse` blocks every subsequent request
     * from the same user (e.g. UI actions while the chat is
     * streaming) on the lock. Save + close the session BEFORE
     * invoking the user callback so concurrent same-user requests
     * proceed normally during the stream.
     */
    private function streamingResponse(Request $request, \Closure $callback): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $callback) {
            // Save + close the session BEFORE invoking the user
            // callback so concurrent same-user requests proceed
            // normally during the stream. `hasSession()` guards the
            // testbench / non-web context where the session isn't
            // wired up — calling save() unconditionally there opens
            // an output buffer PHPUnit 12 strict-mode flags as
            // unclosed.
            if ($request->hasSession()) {
                $request->session()->save();
            }
            $callback();
        }, 200, [
            // Explicit charset so the wire contract is deterministic
            // across SAPIs / proxies. Laravel's default-charset shim
            // appends `; charset=UTF-8` to text/* responses, but some
            // proxies strip / replace it. Setting it ourselves makes
            // the feature test's
            // `assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')`
            // pass everywhere, not just on the dev server.
            'Content-Type' => 'text/event-stream; charset=UTF-8',
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
        // OpenAI / Anthropic / Gemini / OpenRouter all use the flat
        // `chat_model` config key.
        $configured = config("ai.providers.{$providerName}.chat_model");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        // Regolo nests its chat model under `models.text.default`
        // (NOT `models.chat.default`) — see config/ai.php where
        // `models.text.{default,cheapest,smartest}` host the
        // chat-completion model variants and `models.embeddings`
        // hosts the embeddings model. Reading the wrong key
        // silently fell back to "unknown" in chat-log rows for
        // every Regolo-backed streaming turn.
        $nested = config("ai.providers.{$providerName}.models.text.default");
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
