<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\FinOps\ChatTraceContext;
use App\FinOps\ChatTurnCost;
use App\FinOps\ChatTurnCostResolver;
use App\Http\Requests\Api\KbChatRequest;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Grounding\ConfidenceCalculator;
use App\Services\Kb\Retrieval\CounterfactualService;
use App\Services\Guardrails\ChatGuardrails;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        ChatRetrievalService $retrieval,
        ChatLogManager $chatLog,
        ConfidenceCalculator $confidence,
        CounterfactualService $counterfactual,
        TenantContext $tenants,
        RedactorEngine $redactor,
        ChatTurnCostResolver $costResolver,
        ChatGuardrails $guardrails,
    ): JsonResponse {
        $question = (string) $request->input('question');

        // v8.8.3 — anonymous (authenticated, non-persisted) turn. R43: when the
        // flag is off we REJECT rather than silently fall back to a persisted
        // turn, so toggling the flag can never surprise an operator.
        $anonymous = $request->isAnonymous();
        if ($anonymous && ! (bool) config('kb.anonymous_chat.enabled', false)) {
            throw new HttpException(422, 'Anonymous chat is disabled (kb.anonymous_chat.enabled=false).');
        }

        // Anonymous turns redact the question BEFORE it reaches retrieval, the
        // LLM, the content-gap rollup, or the minimal log — so an anonymous turn
        // is MORE redacted than a normal stateless turn, never a PII bypass.
        //
        // Two deliberate choices here:
        //  1. `MaskStrategy` is passed EXPLICITLY (not resolved from the
        //     operator's configured default strategy) to FORCE a NON-PERSISTENT
        //     redaction — mask writes no reversible token map anywhere. An
        //     operator whose default is the reversible `tokenise` strategy must
        //     not have a reversible PII map persisted on a turn that is supposed
        //     to be anonymous; forcing mask guarantees that.
        //  2. The global `pii-redactor.enabled` knob is still honoured: it is
        //     NOT re-checked here because `RedactorEngine::redact()` already
        //     returns the input unchanged when the engine is disabled (see its
        //     `if (! $this->enabled ...)` guard). A redundant guard here would
        //     just duplicate that. Pair anonymous chat with PII redaction on.
        if ($anonymous) {
            $question = $redactor->redact($question, new MaskStrategy());
        }
        // Effective single-project key for the legacy meta payload + the
        // chat log row. When the new `filters.project_keys` payload is
        // used, the FIRST element is treated as the canonical tenant for
        // observability (chat-log row carries one project_key column);
        // the FULL list is still applied via the RetrievalFilters DTO.
        $projectKey = $request->effectiveProjectKey();
        $filters = $request->toFilters();

        $startTime = microtime(true);

        $result = $retrieval->retrieve($question, $projectKey, $filters);

        // v8.0/W3.4 — Counterfactual mini-retrieval against up to 3
        // other projects the user has membership in. RBAC-critical:
        // the candidate project set comes STRICTLY from the user's
        // `project_memberships` rows (tenant-scoped), never from the
        // raw chunk pool — a project the user has no membership in
        // must never appear here. Default-ON via config; per-user
        // preference toggle lands in W3.5 (FE work).
        $counterfactualPanels = $counterfactual->pick(
            query: $question,
            userId: $request->user()?->id,
            tenantId: $tenants->current(),
            primaryProjectKey: $projectKey,
        );

        // T3.3 — deterministic refusal short-circuit. If too few primary
        // chunks pass the grounding gate, we don't call the LLM at all and
        // return a refusal payload the FE renders distinctly (ConfidenceBadge
        // / RefusalNotice). v8.1 — the gate lives in RetrievalGrounding so
        // all three chat surfaces decide identically, reads the score
        // shape-agnostically (search() returns ARRAYS — object syntax read
        // null → 0 and silently disabled this gate in production), and
        // grounds on the FINAL rerank_score OR the vector floor so
        // lexically-strong matches aren't wrongly refused.
        if ($retrieval->shouldRefuse($result)) {
            return $this->refusalResponse(
                request: $request,
                chatLog: $chatLog,
                question: $question,
                projectKey: $projectKey,
                result: $result,
                startTime: $startTime,
                reason: 'no_relevant_context',
                counterfactual: $counterfactualPanels,
            );
        }

        // v8.19/W2 — INPUT GUARDRAIL (laravel-ai-guardrails Control B). Screen the
        // user's question for prompt-injection / jailbreak / exfiltration BEFORE it
        // reaches the LLM, and append the attempt to the append-only audit (which
        // feeds the guardrails admin console, W3). A blocked prompt is a REFUSAL,
        // not an error: same response shape as a no-context refusal (R26/R27), never
        // a 500. The ChatGuardrails adapter resolves the enforce/monitor/off mode;
        // the `guardrailsInputEnabled()` gate makes the master/per-control OFF state
        // a clean pass-through that never reaches the adapter (R43). Screening before
        // the LLM (not before retrieval) is security-equivalent for injection defense
        // — retrieval is a read-only vector search over our own KB that injection
        // cannot exploit — and lets us reuse refusalResponse().
        $principal = $request->user()?->id !== null ? (string) $request->user()->id : null;
        if ($this->guardrailsInputEnabled() && $guardrails->screenInput($question, $principal)) {
            return $this->refusalResponse(
                request: $request,
                chatLog: $chatLog,
                question: $question,
                projectKey: $projectKey,
                result: $result,
                startTime: $startTime,
                reason: 'blocked_by_guardrails',
                counterfactual: $counterfactualPanels,
            );
        }

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $result->primary,
            'expanded' => $result->expanded,
            'rejected' => $result->rejected,
            'projectKey' => $projectKey,
        ])->render();

        // v8.16/W3 — one trace id per turn: run the LLM call inside the finops
        // trace context so the metering hook stamps this trace_id on the ledger
        // row, and persist the SAME id on the chat_logs row (join key).
        $traceId = ChatTraceContext::newTraceId();
        $aiResponse = ChatTraceContext::within($traceId, fn (): AiResponse => $ai->chat($systemPrompt, $question));

        // v8.19/W2 — OUTPUT GUARDRAIL (laravel-ai-guardrails Control C). Sanitize the
        // model's answer (markdown-exfil neutralization; HTML escaping + PII redaction
        // are disabled in the host config — see config/ai-guardrails.php) BEFORE it is
        // logged, scored, or returned. Swapped in one place via AiResponse::withContent()
        // so every downstream consumer (sentinel check, confidence, cost, chat log,
        // response) sees the sanitized body. The adapter resolves enforce/monitor/off
        // and records an output-stat; the `guardrailsOutputEnabled()` gate makes the OFF
        // state a clean pass-through (R43).
        if ($this->guardrailsOutputEnabled()) {
            $aiResponse = $aiResponse->withContent($guardrails->sanitizeOutput($aiResponse->content));
        }

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
                retrieval: $retrieval,
                question: $question,
                projectKey: $projectKey,
                result: $result,
                aiResponse: $aiResponse,
                latencyMs: $latencyMs,
                counterfactual: $counterfactualPanels,
                traceId: $traceId,
            );
        }

        $citations = $retrieval->buildCitations($result);
        $sources = $retrieval->collectSources($result);

        // T3.5 — composite confidence score for the grounded answer. Wires
        // the ConfidenceCalculator (T3.2) into the hot path. citationsCount
        // is the count of DOCUMENTS we ended up citing (one per source-path
        // group), not the chunks themselves — that's what the user sees in
        // the FE citations panel and what the prompt rewards via the
        // citation-density signal.
        $confidenceScore = $confidence->compute(
            primaryChunks: $result->primary,
            minThreshold: (float) config('kb.refusal.min_chunk_similarity', 0.45),
            answerWords: str_word_count($aiResponse->content),
            citationsCount: count($citations),
        );

        // v8.16/W3 — resolve the real per-turn cost server-side (cache-warm via the
        // metering hook that just ran inside the trace context above). Null when
        // finops metering is off — the meta keys still ship as null (R27).
        $cost = $costResolver->resolve(
            provider: $aiResponse->provider,
            model: $aiResponse->model,
            promptTokens: $aiResponse->promptTokens,
            completionTokens: $aiResponse->completionTokens,
            promptText: $question,
            completionText: $aiResponse->content,
            traceId: $traceId,
        );

        $chatLog->log(new ChatLogEntry(
            sessionId: $this->chatSessionId($request),
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
                // T3.5 — persist confidence on chat_logs row too so admin
                // dashboards can roll up "average confidence per project"
                // without joining messages.metadata. Mirrors T3.1 column
                // additions on chat_logs.
                'confidence' => $confidenceScore,
            ],
            anonymous: $anonymous,
            traceId: $traceId,
        ));

        return response()->json($this->buildSuccessResponse(
            answer: $aiResponse->content,
            citations: $citations,
            confidence: $confidenceScore,
            aiResponse: $aiResponse,
            result: $result,
            totalLatencyMs: $latencyMs,
            counterfactual: $counterfactualPanels,
            cost: $cost,
        ));
    }

    /**
     * T3.5 — happy-path response shape. Extracted so the conversation
     * controller (MessageController) can compose the same shape without
     * duplicating the meta-building logic. ADDITIVE-ONLY rule (L21):
     * `latency_ms` stays a flat int (legacy clients), the breakdown
     * lives under `latency_ms_breakdown` as a sibling. Same for
     * `confidence` (now populated with a real score) and `refusal_reason`
     * (still null on happy path) — both kept on every response for shape
     * uniformity across grounded vs refused.
     *
     * @param  array<int, array<string, mixed>>  $citations
     * @param  array<int, array{project_key: string, top_chunks: array<int, array<string, mixed>>}>  $counterfactual
     */
    private function buildSuccessResponse(
        string $answer,
        array $citations,
        int $confidence,
        AiResponse $aiResponse,
        SearchResult $result,
        int $totalLatencyMs,
        array $counterfactual = [],
        ?ChatTurnCost $cost = null,
    ): array {
        $retrievalMs = (int) ($result->meta['retrieval_ms'] ?? 0);
        $llmMs = max(0, $totalLatencyMs - $retrievalMs);

        return [
            'answer' => $answer,
            'citations' => $citations,
            'confidence' => $confidence,
            'refusal_reason' => null,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                // v8.16/W3 — server-resolved per-turn cost (R27 additive: new
                // sibling keys, null when finops is off / cost unresolved; the FE
                // reads these instead of computing from static client-side rates).
                'cost' => $cost?->cost,
                'cost_currency' => $cost?->currency,
                'chunks_used' => $result->totalChunks(),
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                // v8.0/W3.1 — Why-not-cited: surface the runner-up
                // chunks (considered but not used in primary) so the
                // chat UI can render the "Considered but not used"
                // tab. R27 additive: new sibling key, never replaces
                // existing keys; legacy clients that ignore it keep
                // working byte-for-byte.
                'retrieval_runner_up' => $result->runnerUp()->values()->all(),
                'runner_up_count' => $result->runnerUp()->count(),
                // L21 — `latency_ms` stays a flat int for back-compat;
                // breakdown lives under `latency_ms_breakdown` as a
                // sibling. Don't sub-objectify load-bearing keys.
                'latency_ms' => $totalLatencyMs,
                'latency_ms_breakdown' => [
                    'retrieval' => $retrievalMs,
                    'llm' => $llmMs,
                    'total' => $totalLatencyMs,
                ],
                // T2.2 — surface the user-selected filter count.
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
                // T3.5 — search_strategy + retrieval_stats sub-objects.
                // Empty defaults if KbSearchService didn't emit them
                // (e.g. older mocks in tests) so existing assertions
                // that ignore meta.* don't see undefined keys.
                'search_strategy' => $result->meta['search_strategy'] ?? null,
                'retrieval_stats' => $result->meta['retrieval_stats'] ?? null,
                // v8.0/W3.4 — counterfactual neighbor-project panels.
                // R27 additive: always present (empty array when the
                // user has no other memberships, when the toggle is
                // off, or when the calling user is anonymous).
                'counterfactual' => $counterfactual,
                'counterfactual_count' => count($counterfactual),
            ],
        ];
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
     *
     * @param  array<int, array{project_key: string, top_chunks: array<int, array<string, mixed>>}>  $counterfactual
     */
    private function refusalResponse(
        KbChatRequest $request,
        ChatLogManager $chatLog,
        string $question,
        ?string $projectKey,
        SearchResult $result,
        float $startTime,
        string $reason,
        array $counterfactual = [],
    ): JsonResponse {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $answer = $this->localizedRefusalMessage($reason);

        $chatLog->log(new ChatLogEntry(
            sessionId: $this->chatSessionId($request),
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
            anonymous: $request->isAnonymous(),
        ));

        // v8.8/W4 — record the content gap (the question the KB couldn't
        // answer). Side-channel: never breaks the chat path.
        app(\App\Services\Kb\Analytics\SearchFailureRecorder::class)
            ->record($projectKey, $question, $reason);

        $retrievalMs = (int) ($result->meta['retrieval_ms'] ?? $latencyMs);

        return response()->json([
            'answer' => $answer,
            'citations' => [],
            'confidence' => 0,
            'refusal_reason' => $reason,
            'meta' => [
                'provider' => null,
                'model' => null,
                // v8.16/W3 — R27 shape uniformity: cost keys present on every path
                // (null here — a pre-LLM refusal made no priced call).
                'cost' => null,
                'cost_currency' => null,
                'chunks_used' => 0,
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                'retrieval_runner_up' => $result->runnerUp()->values()->all(),
                'runner_up_count' => $result->runnerUp()->count(),
                'latency_ms' => $latencyMs,
                'latency_ms_breakdown' => [
                    'retrieval' => $retrievalMs,
                    'llm' => 0,  // No LLM call on the no_relevant_context path.
                    'total' => $latencyMs,
                ],
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
                'refused_early' => true,
                'search_strategy' => $result->meta['search_strategy'] ?? null,
                'retrieval_stats' => $result->meta['retrieval_stats'] ?? null,
                // v8.0/W3.4 — counterfactual is still meaningful on
                // refusal: "no relevant context HERE — but here is
                // what your other projects have on this query".
                'counterfactual' => $counterfactual,
                'counterfactual_count' => count($counterfactual),
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
     * Session id for the chat-log row. An anonymous turn always gets a FRESH
     * random UUID — never the client-supplied `X-Session-Id`, which could be a
     * stable, user-linkable value — so the minimal anonymous row carries
     * nothing that could re-identify the user or correlate turns.
     */
    private function chatSessionId(KbChatRequest $request): string
    {
        if ($request->isAnonymous()) {
            return (string) Str::uuid();
        }

        return $request->header('X-Session-Id', (string) Str::uuid());
    }

    /**
     * T3.8-BE — resolve the user-facing refusal message for the given
     * reason via the localized hierarchy:
     *
     *   1. `kb.refusal.{reason}` — per-reason copy (preferred). Lets the
     *      user see why the answer was withheld ("no documents match"
     *      vs "AI couldn't ground").
     *   2. `kb.no_grounded_answer` — generic fallback when the per-reason
     *      key is missing. Forward-compat hatch: a future task can add
     *      a new refusal_reason in code without breaking response
     *      rendering before the lang lines land in a follow-up PR.
     *
     * The `__()` translator returns the dotted key itself when no entry
     * exists, so we use that as the "miss" sentinel and degrade to the
     * generic message. Callers should NEVER receive the raw key — the
     * test suite asserts this on every refusal path.
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
     * v8.19/W2 — is the laravel-ai-guardrails INPUT screening control live for the
     * chat path? Gated on the package master switch AND the per-control flag so an
     * operator can disable screening (or the whole package) and the chat path
     * degrades to a clean pass-through — never calling the facade (R43 OFF-state).
     * The enforce/monitor/off MODE is resolved inside ChatGuardrails::screenInput()
     * (via ControlMode::resolve); this gate only decides whether we screen at all.
     */
    private function guardrailsInputEnabled(): bool
    {
        return (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.input_screen.enabled', true);
    }

    /**
     * v8.19/W2 — is the laravel-ai-guardrails OUTPUT sanitization control live for
     * the chat path? Same gating rationale as {@see guardrailsInputEnabled()}; in
     * monitor/off mode AiGuardrails::sanitize() returns the answer unchanged.
     */
    private function guardrailsOutputEnabled(): bool
    {
        return (bool) config('ai-guardrails.enabled', true)
            && (bool) config('ai-guardrails.output_handler.enabled', true);
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
     *
     * @param  array<int, array{project_key: string, top_chunks: array<int, array<string, mixed>>}>  $counterfactual
     */
    private function convertSentinelToRefusal(
        KbChatRequest $request,
        ChatLogManager $chatLog,
        ChatRetrievalService $retrieval,
        string $question,
        ?string $projectKey,
        SearchResult $result,
        AiResponse $aiResponse,
        int $latencyMs,
        array $counterfactual = [],
        ?string $traceId = null,
    ): JsonResponse {
        $reason = 'llm_self_refusal';
        $answer = $this->localizedRefusalMessage($reason);

        $chatLog->log(new ChatLogEntry(
            sessionId: $this->chatSessionId($request),
            userId: $request->user()?->id,
            question: $question,
            answer: $answer,
            projectKey: $projectKey,
            aiProvider: $aiResponse->provider,
            aiModel: $aiResponse->model,
            chunksCount: $result->totalChunks(),
            sources: $retrieval->collectSources($result),
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
            anonymous: $request->isAnonymous(),
            traceId: $traceId,
        ));

        // v8.8/W4 — record the content gap (LLM self-refusal). Side-channel.
        app(\App\Services\Kb\Analytics\SearchFailureRecorder::class)
            ->record($projectKey, $question, $reason);

        $retrievalMs = (int) ($result->meta['retrieval_ms'] ?? 0);
        $llmMs = max(0, $latencyMs - $retrievalMs);

        return response()->json([
            'answer' => $answer,
            'citations' => [],
            'confidence' => 0,
            'refusal_reason' => $reason,
            'meta' => [
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                // v8.16/W3 — R27 shape uniformity. This sentinel-refusal turn DID
                // consume tokens, so its real cost is resolved + persisted on the
                // chat_logs row by the driver; the response meta keeps the keys
                // present (null here) rather than re-resolving on the refusal path.
                'cost' => null,
                'cost_currency' => null,
                'chunks_used' => $result->totalChunks(),
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
                // v8.0/W3.1 — Why-not-cited: surface the runner-up
                // chunks (considered but not used in primary) so the
                // chat UI can render the "Considered but not used"
                // tab. R27 additive: new sibling key, never replaces
                // existing keys; legacy clients that ignore it keep
                // working byte-for-byte.
                'retrieval_runner_up' => $result->runnerUp()->values()->all(),
                'runner_up_count' => $result->runnerUp()->count(),
                'latency_ms' => $latencyMs,
                'latency_ms_breakdown' => [
                    'retrieval' => $retrievalMs,
                    'llm' => $llmMs,  // LLM was called and paid for.
                    'total' => $latencyMs,
                ],
                'filters_selected' => $result->meta['filters_selected'] ?? 0,
                'refused_early' => false,  // LLM was called; only RETRIEVAL was sufficient.
                'search_strategy' => $result->meta['search_strategy'] ?? null,
                'retrieval_stats' => $result->meta['retrieval_stats'] ?? null,
                // v8.0/W3.4 — counterfactual on LLM self-refusal too.
                'counterfactual' => $counterfactual,
                'counterfactual_count' => count($counterfactual),
            ],
        ]);
    }

}
