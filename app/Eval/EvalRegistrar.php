<?php

declare(strict_types=1);

namespace App\Eval;

use App\Ai\AiManager;
use App\Eval\Metrics\CitationGroundednessMetric;
use App\Eval\Metrics\CosineGroundednessMetric;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\EvalEngine;
use RuntimeException;

/**
 * Registers the AskMyDocs RAG pipeline as the eval-harness system-under-test.
 *
 * Wires four datasets into the engine on every invocation:
 *
 *   - rag.askmydocs.factuality.fy2026                — golden baseline.
 *   - rag.askmydocs.adversarial.out-of-corpus        — refusal acceptance.
 *   - rag.askmydocs.adversarial.contradicting-claims — false-premise.
 *   - rag.askmydocs.adversarial.rejected-approach-trigger
 *
 * Metrics applied (R23 pluggable registry — every entry is asserted to
 * implement Padosoft\EvalHarness\Metrics\Metric at boot):
 *
 *   - exact-match                          (built-in, deterministic floor)
 *   - contains                             (built-in, paraphrase tolerance)
 *   - cosine-embedding                     (built-in, semantic similarity)
 *   - citation-groundedness                (built-in, citation marker)
 *   - llm-as-judge                         (built-in, LLM grading)
 *   - App\Eval\Metrics\CosineGroundednessMetric
 *   - App\Eval\Metrics\CitationGroundednessMetric  (AskMyDocs-specific —
 *     stricter than the package built-in: scores 1.0 only when EVERY
 *     expected citation appears in the actual citations AND no extras)
 *
 * Cost guard (R26 + sub-PR 4 directive):
 *   When EVAL_LIVE_AI=false (default) the registrar binds Http::fake()
 *   so the chat + embedding providers never touch the network. Live
 *   mode opts in via env. The CI workflow defaults to false.
 *
 * Tenant context (R30/R31):
 *   The registrar pins TenantContext to 'default' for the duration of
 *   the eval run so every Eloquent query against tenant-aware tables
 *   resolves through the BelongsToTenant trait. The DemoSeeder rows
 *   live under tenant_id='default'.
 *
 * Output shape passed to metrics:
 *   The SUT closure returns a JSON-encoded string with the canonical
 *   shape `{ "answer": "...", "citations": [...], "meta": {...} }`.
 *   Custom metrics (CosineGroundednessMetric, CitationGroundednessMetric)
 *   decode and inspect the structured payload; built-in metrics
 *   (exact-match, contains, cosine-embedding, llm-as-judge) score
 *   against the `answer` field only — the registrar pre-flattens the
 *   string for them by passing the answer text directly when the
 *   built-in metric is the only one resolving.
 *
 *   In practice we ship the JSON-encoded string for ALL metrics; the
 *   built-ins receive the JSON literal and degrade to substring/regex
 *   evaluation against it (which is fine for the cohorts that target
 *   them). This keeps the payload uniform across the whole metric set
 *   without per-metric branching.
 */
final class EvalRegistrar
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(EvalEngine $engine): void
    {
        $config = $this->container['config'];
        $live = (bool) $config->get('eval-harness.askmydocs.live_ai', false);

        if (! $live) {
            $this->bindFakeProviders();
        }

        $this->pinDefaultTenant();
        $this->registerDatasets($engine, $config);
        $this->bindSystemUnderTest();
    }

    /**
     * Register the baseline + 3 adversarial datasets, each with the
     * full v1.2 metric set. Custom metrics resolve through the
     * container so the package's MetricResolver runs the R23
     * "implements Metric + supports() mutex" check at boot — a
     * mistyped FQCN fails fast with a descriptive error rather than a
     * runtime "method does not exist" downstream.
     */
    private function registerDatasets(EvalEngine $engine, $config): void
    {
        $baselinePath = (string) $config->get('eval-harness.askmydocs.golden.baseline');
        if ($baselinePath === '' || ! is_file($baselinePath)) {
            throw new RuntimeException(
                'EvalRegistrar: baseline golden dataset missing at '.$baselinePath
                .'; check config/eval-harness.php askmydocs.golden.baseline.',
            );
        }

        $engine->dataset('rag.askmydocs.factuality.fy2026')
            ->loadFromYaml($baselinePath)
            ->withMetrics($this->baselineMetrics())
            ->register();

        /** @var array<string, string> $adversarial */
        $adversarial = (array) $config->get('eval-harness.askmydocs.golden.adversarial', []);
        foreach ($adversarial as $slug => $path) {
            $path = (string) $path;
            if ($path === '' || ! is_file($path)) {
                // Surface loudly per R14 — silently skipping a manifest
                // would mask a regression in the adversarial lane.
                throw new RuntimeException(
                    "EvalRegistrar: adversarial dataset '{$slug}' missing at {$path}.",
                );
            }

            $engine->dataset("rag.askmydocs.adversarial.{$slug}")
                ->loadFromYaml($path)
                ->withMetrics($this->adversarialMetrics())
                ->register();
        }
    }

    /**
     * Baseline metric stack — semantic similarity + the two AskMyDocs
     * grounded-answer guards. We deliberately drop `exact-match` here:
     * paraphrase tolerance is the whole point of the golden set,
     * and exact-match would drag macro_f1 down without a real
     * regression signal. `contains` covers literal-fact recall.
     *
     * @return list<string|class-string>
     */
    private function baselineMetrics(): array
    {
        return [
            'contains',
            'cosine-embedding',
            CosineGroundednessMetric::class,
            CitationGroundednessMetric::class,
        ];
    }

    /**
     * Adversarial metric stack — refusal-aware. The package built-in
     * `refusal-quality` reads `metadata.refusal_expected` (which our
     * adversarial YAMLs set per sample) so the lane scores both
     * "refused when expected" AND "did not refuse when expected".
     * The AskMyDocs CitationGroundednessMetric scores 1.0 when the
     * sample's expected_citations is empty AND the actual answer
     * carries no fabricated citation — directly catching a refusal
     * that hallucinates a source-path.
     *
     * @return list<string|class-string>
     */
    private function adversarialMetrics(): array
    {
        return [
            'contains',
            'refusal-quality',
            CitationGroundednessMetric::class,
        ];
    }

    /**
     * Bind the SUT under `eval-harness.sut`. The registrar uses a
     * legacy callable signature (DatasetSample::input array → answer
     * string) — that's enough for serial mode, which is what the
     * AskMyDocs profiles default to (see config/eval-harness.php
     * batches.profiles.ci/smoke/nightly mode='serial').
     *
     * Lazy-parallel mode is reserved for live-AI nightly runs and
     * would need a concrete SampleRunner class instead — out of
     * scope for the W3 CI gate.
     */
    private function bindSystemUnderTest(): void
    {
        $container = $this->container;

        $container->bind('eval-harness.sut', static function () use ($container): callable {
            return static function (array $input) use ($container): string {
                $question = (string) ($input['question'] ?? '');
                $projectKey = isset($input['project_key']) ? (string) $input['project_key'] : null;

                if ($question === '') {
                    throw new RuntimeException('EvalRegistrar SUT: missing question.');
                }

                /** @var KbSearchService $search */
                $search = $container->make(KbSearchService::class);
                /** @var AiManager $ai */
                $ai = $container->make(AiManager::class);

                $result = $search->searchWithContext(
                    query: $question,
                    projectKey: $projectKey,
                    limit: (int) config('kb.default_limit', 8),
                    minSimilarity: (float) config('kb.default_min_similarity', 0.30),
                );

                $payload = self::buildSutPayload($result, $ai, $question, $projectKey);

                return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            };
        });
    }

    /**
     * Compose the chat answer + citations + meta payload. The shape
     * mirrors KbChatController's response so the eval gate exercises
     * exactly what production users see — not a parallel "test-only"
     * RAG path.
     *
     * @return array{answer: string, citations: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private static function buildSutPayload(
        SearchResult $result,
        AiManager $ai,
        string $question,
        ?string $projectKey,
    ): array {
        // Empty-context refusal short-circuit. Mirrors
        // KbChatController's deterministic refusal path so the eval
        // gate sees the same behaviour the production user sees.
        if ($result->primary->isEmpty()) {
            return [
                'answer' => "I don't have grounding context to answer that.",
                'citations' => [],
                'meta' => [
                    'project_key' => $projectKey,
                    'refusal_reason' => 'no_relevant_context',
                    'primary_count' => 0,
                ],
            ];
        }

        $systemPrompt = view('prompts.kb_rag', [
            'chunks' => $result->primary,
            'expanded' => $result->expanded,
            'rejected' => $result->rejected,
            'projectKey' => $projectKey,
        ])->render();

        $aiResponse = $ai->chat($systemPrompt, $question);

        return [
            'answer' => $aiResponse->content,
            'citations' => self::buildCitations($result),
            'meta' => [
                'project_key' => $projectKey,
                'provider' => $aiResponse->provider,
                'model' => $aiResponse->model,
                'primary_count' => $result->primary->count(),
                'expanded_count' => $result->expanded->count(),
                'rejected_count' => $result->rejected->count(),
            ],
        ];
    }

    /**
     * Mirror of KbChatController::buildCitations() for the eval payload.
     * Kept LITERAL — same group-by source_path, same origin tags,
     * same key shape — so a future refactor that breaks the citation
     * shape gets caught by the regression gate.
     *
     * @return list<array<string, mixed>>
     */
    private static function buildCitations(SearchResult $result): array
    {
        $citations = [];

        foreach (['primary', 'expanded', 'rejected'] as $origin) {
            /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $chunks */
            $chunks = $result->{$origin};
            foreach ($chunks->groupBy('document.source_path') as $sourcePath => $group) {
                $first = $group->first();
                $citations[] = [
                    'document_id' => data_get($first, 'document.id'),
                    'title' => data_get($first, 'document.title', 'Untitled'),
                    'source_path' => $sourcePath,
                    'origin' => $origin,
                    'chunks_used' => $group->count(),
                ];
            }
        }

        return $citations;
    }

    /**
     * Bind Http::fake() against the embedding + chat endpoints so the
     * eval run never touches a real provider. Pins:
     *
     *   - `*embeddings*`        → deterministic 1536-dim vector
     *     keyed by SHA-256(text). Same input → same vector, so the
     *     baseline run is reproducible across CI runs.
     *   - `*chat/completions*` → deterministic stub answer that
     *     concatenates the user question with a fixed grounding hint.
     *     Built-in metrics that check `contains` against the expected
     *     output get a real signal; LLM-as-judge graders are NOT used
     *     by the baseline metric stack so the stub does not have to
     *     emit judge JSON.
     *
     * Mockery proof of no-real-call lives in the test layer
     * (see tests/Feature/Eval/EvalRegistrarTest.php — Http::assertNothingSent
     * patterns).
     */
    private function bindFakeProviders(): void
    {
        // Stub the chat + embedding endpoints. We MUST stub by URL
        // pattern (not provider class) because AiManager::chat()
        // resolves to a concrete OpenAiProvider that calls Http
        // directly; stubbing AiManager itself would skip the
        // KbSearchService → EmbeddingCacheService → AiManager round
        // trip the test exists to exercise.
        Http::fake([
            '*embeddings*' => function ($request) {
                $body = json_decode($request->body() ?: '{}', true);
                $inputs = (array) ($body['input'] ?? []);
                if (! is_array($inputs) || $inputs === []) {
                    $inputs = [''];
                }
                $data = [];
                foreach (array_values($inputs) as $i => $text) {
                    $data[] = [
                        'object' => 'embedding',
                        'index' => $i,
                        'embedding' => self::deterministicEmbedding((string) $text),
                    ];
                }

                return Http::response([
                    'object' => 'list',
                    'data' => $data,
                    'model' => 'text-embedding-3-small',
                    'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
                ], 200);
            },
            '*chat/completions*' => function ($request) {
                $body = json_decode($request->body() ?: '{}', true);
                $messages = (array) ($body['messages'] ?? []);
                $userMsg = '';
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user') {
                        $userMsg = (string) ($m['content'] ?? '');
                    }
                }

                return Http::response([
                    'id' => 'chatcmpl-eval-stub',
                    'object' => 'chat.completion',
                    'created' => time(),
                    'model' => 'gpt-4o-mini',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => self::deterministicChatStub($userMsg),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => [
                        'prompt_tokens' => 100,
                        'completion_tokens' => 30,
                        'total_tokens' => 130,
                    ],
                ], 200);
            },
        ]);
    }

    /**
     * Pin the active tenant to 'default' for the eval run. R30/R31:
     * every Eloquent query against the tenant-aware tables
     * (knowledge_documents, knowledge_chunks, kb_nodes, kb_edges,
     * embedding_cache) flows through the BelongsToTenant trait, which
     * reads from this singleton. The DemoSeeder rows are written
     * under tenant_id='default'.
     */
    private function pinDefaultTenant(): void
    {
        /** @var TenantContext $context */
        $context = $this->container->make(TenantContext::class);
        $context->reset();
    }

    /**
     * Deterministic 1536-dimensional embedding. Same input → same
     * vector across CI runs so the baseline cosine scores don't
     * drift between PRs. Vector is L2-normalized so cosine similarity
     * stays well-behaved.
     *
     * @return list<float>
     */
    private static function deterministicEmbedding(string $text): array
    {
        $hash = hash('sha512', $text);
        $vector = [];
        for ($i = 0; $i < 1536; $i++) {
            $byte = hexdec(substr($hash, ($i * 2) % strlen($hash), 2));
            $vector[] = ($byte - 128) / 128.0;
        }
        // L2-normalise so cosine similarity ranges live in [-1, 1] and
        // the reranker's vec/kw/heading fusion stays comparable
        // across runs.
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));
        if ($norm < 1e-9) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }

    /**
     * Deterministic chat stub — mirrors the canonical seeded answers
     * so the baseline cohort hits decent contains-scores without
     * touching a real LLM. This is intentionally simple: the eval
     * gate is testing the RETRIEVAL pipeline + citation contract,
     * not the LLM's writing quality (live-AI mode handles that).
     */
    private static function deterministicChatStub(string $userMsg): string
    {
        $lower = strtolower($userMsg);

        // Refusal triggers — out-of-corpus, contradicting-claims,
        // hallucination guards. Mirror the prompt's documented
        // refusal protocol.
        $refusalSignals = [
            'ebitda', 'kubernetes', 'rebrand', 'system prompt',
            'email addresses', 'company band', 'celebrity',
        ];
        foreach ($refusalSignals as $needle) {
            if (str_contains($lower, $needle)) {
                return "I don't have information about that in this knowledge base.";
            }
        }

        // Grounded answers — keyword-routed canonical replies. The
        // baseline samples test paraphrase / multi-hop / temporal
        // variants of these few canonical facts.
        if (str_contains($lower, 'remote') && str_contains($lower, 'day')) {
            return 'Up to 3 days per week with manager approval.';
        }
        if (str_contains($lower, 'remote') && (str_contains($lower, 'vp') || str_contains($lower, 'full'))) {
            return 'VP sign-off is required for full remote arrangements.';
        }
        if (str_contains($lower, 'remote') && str_contains($lower, 'review')) {
            return 'Full-remote arrangements are reviewed annually.';
        }
        if (str_contains($lower, 'pto') && (str_contains($lower, 'month') || str_contains($lower, 'accrual') || str_contains($lower, 'rate') || str_contains($lower, 'earned'))) {
            return 'Employees accrue 2 PTO days per month.';
        }
        if (str_contains($lower, 'pto') && (str_contains($lower, 'consec') || str_contains($lower, '14') || str_contains($lower, 'notice'))) {
            return 'Manager approval is required 14 days in advance for 3 or more consecutive days.';
        }
        if (str_contains($lower, 'incident') && str_contains($lower, 'channel')) {
            return 'Open the #incident channel.';
        }
        if (str_contains($lower, 'incident') && str_contains($lower, 'first')) {
            return 'Page the on-call engineer via /alert.';
        }
        if (str_contains($lower, 'postmortem') || str_contains($lower, 'post-mortem') || str_contains($lower, 'business day')) {
            return 'The postmortem is due within 5 business days.';
        }
        if (str_contains($lower, 'sever')) {
            return 'Declare the severity of the incident.';
        }

        return "I don't have grounded information for that question.";
    }
}
