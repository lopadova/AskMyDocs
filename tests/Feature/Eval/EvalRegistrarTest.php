<?php

declare(strict_types=1);

namespace Tests\Feature\Eval;

use App\Eval\EvalRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\EvalEngine;
use Tests\TestCase;

/**
 * Feature test: EvalRegistrar wires the AskMyDocs RAG pipeline as the
 * eval-harness system-under-test, registers all four golden datasets,
 * binds Http::fake() in CI mode, and exposes the four custom metrics
 * to the v1.2 MetricResolver.
 *
 * R26 — Http::assertNothingSent() proves no real provider call leaked.
 * R23 — every custom metric resolves cleanly via FQCN through the
 *       v1.2 MetricResolver (mistyped FQCN would throw at register()).
 */
final class EvalRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force CI mode (no real provider calls).
        config()->set('eval-harness.askmydocs.live_ai', false);
    }

    public function test_registrar_registers_baseline_and_three_adversarial_datasets(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        (new EvalRegistrar($this->app))($engine);

        $this->assertTrue($engine->hasDataset('rag.askmydocs.factuality.fy2026'));
        $this->assertTrue($engine->hasDataset('rag.askmydocs.adversarial.out-of-corpus'));
        $this->assertTrue($engine->hasDataset('rag.askmydocs.adversarial.contradicting-claims'));
        $this->assertTrue($engine->hasDataset('rag.askmydocs.adversarial.rejected-approach-trigger'));

        $names = $engine->registeredDatasetNames();
        $this->assertCount(4, $names);
    }

    public function test_baseline_dataset_carries_at_least_thirty_samples(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        (new EvalRegistrar($this->app))($engine);

        $dataset = $engine->getDataset('rag.askmydocs.factuality.fy2026');
        $this->assertGreaterThanOrEqual(
            30,
            count($dataset->samples),
            'Baseline golden dataset must carry at least 30 samples (W3 sub-PR 4 acceptance).',
        );
    }

    public function test_baseline_dataset_resolves_four_metric_implementations(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        (new EvalRegistrar($this->app))($engine);

        $dataset = $engine->getDataset('rag.askmydocs.factuality.fy2026');
        $names = array_map(static fn ($m) => $m->name(), $dataset->metrics);

        // 'contains' + 'cosine-embedding' (built-in) +
        // 'cosine-groundedness' + 'citation-groundedness-strict' (custom).
        $this->assertCount(4, $names);
        $this->assertContains('contains', $names);
        $this->assertContains('cosine-embedding', $names);
        $this->assertContains('cosine-groundedness', $names);
        $this->assertContains('citation-groundedness-strict', $names);
    }

    public function test_adversarial_datasets_register_refusal_quality_metric(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        (new EvalRegistrar($this->app))($engine);

        foreach ([
            'rag.askmydocs.adversarial.out-of-corpus',
            'rag.askmydocs.adversarial.contradicting-claims',
            'rag.askmydocs.adversarial.rejected-approach-trigger',
        ] as $name) {
            $dataset = $engine->getDataset($name);
            $metricNames = array_map(static fn ($m) => $m->name(), $dataset->metrics);
            $this->assertContains('refusal-quality', $metricNames, "Adversarial dataset '{$name}' must include refusal-quality.");
            $this->assertContains('citation-groundedness-strict', $metricNames);
        }
    }

    public function test_registrar_in_ci_mode_binds_http_fake_against_chat_and_embedding_endpoints(): void
    {
        Http::preventStrayRequests();

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        (new EvalRegistrar($this->app))($engine);

        // R26: prove the fakes are bound by issuing one direct call to
        // each provider URL pattern. preventStrayRequests() throws on
        // ANY unmatched URL — so reaching the assertions below proves
        // that BOTH OpenAI-compatible URL families (the embedding
        // endpoint AND the chat completions endpoint) hit the bound
        // fakes rather than leaking out to the real provider. This is
        // the load-bearing cost guard described in the directive.
        $embeddings = Http::post('https://api.openai.com/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => ['hello world'],
        ]);
        $chat = Http::post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'system'],
                ['role' => 'user', 'content' => 'user-question'],
            ],
        ]);

        $this->assertSame(200, $embeddings->status());
        $this->assertNotEmpty($embeddings->json('data.0.embedding'));

        $this->assertSame(200, $chat->status());
        $this->assertNotEmpty($chat->json('choices.0.message.content'));
    }

    public function test_registrar_binds_a_callable_under_eval_harness_sut(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        (new EvalRegistrar($this->app))($engine);

        $sut = $this->app->make('eval-harness.sut');

        // The SUT is the closure that drives KbSearchService + AiManager
        // when invoked; here we just assert the binding is callable so
        // the registrar's contract with eval-harness:run is honoured.
        // (Invoking it requires the seeded RAG corpus + a SQL backend
        // that supports pgvector cast — out of scope for this unit;
        // the regression-detection test exercises the full pipeline
        // via EvalEngine::run() against a controlled stub SUT.)
        $this->assertIsCallable($sut);
    }
}
