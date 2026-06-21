<?php

declare(strict_types=1);

namespace Tests\Live\Rag;

use App\Models\KbCanonicalAudit;
use App\Models\KbDocAnalysis;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KbSearchFailure;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\DocumentIngestor;
use App\Support\CaseStudy\IsolationMatrix;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * LIVE documentation-isolation test — real pgvector + real embeddings.
 *
 * The automated form of the manual checklist in
 * `docs/case-studies/README.md` §6 / §6.5. It ingests the three case-study
 * companies (Rotta / Prometeo / PassoLibero — 11 canonical markdown docs each)
 * into THREE projects inside ONE throwaway tenant, then runs the
 * {@see IsolationMatrix} through the real retrieval pipeline
 * (`ChatRetrievalService` → `KbSearchService::searchWithContext`) and asserts,
 * per case, that the per-project scope holds: no chunk or citation from a
 * foreign company surfaces, no foreign canary leaks, owning questions reach
 * their value, and cross-company questions refuse.
 *
 * All three companies coexist in the SAME tenant on purpose — the hard
 * chunk-level `where project_key in (A)` (plus the project-scoped graph
 * expander / rejected-approach injector) is exactly the mechanism under test;
 * if it ever relaxed to a boost, a foreign doc would surface and a case would
 * fail.
 *
 * THREE gates keep it out of CI / the default suite (it is in no <testsuite> in
 * phpunit.xml, so `vendor/bin/phpunit` never collects it):
 *
 *   1. LIVE_RAG=1 must be exported.
 *   2. A REAL embeddings key must be present (OPENROUTER_API_KEY not the
 *      `sk-or-test-key` phpunit sentinel) — the ingest + each query embed for real.
 *   3. The pgvector database must be reachable (DB_* env; live defaults
 *      127.0.0.1:5433 / askmydocs).
 *
 * It NEVER touches the seeded application data: everything is written under a
 * unique throwaway tenant and torn down in tearDown(). No RefreshDatabase — it
 * runs against the operator's live database on purpose, like
 * {@see \Tests\Live\Rag\LiveRagPipelineTest}.
 *
 * Operator runbook (PowerShell):
 *   $env:LIVE_RAG='1'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='5433'
 *   $env:DB_DATABASE='askmydocs'; $env:DB_USERNAME='postgres'; $env:DB_PASSWORD='<pw>'
 *   $env:OPENROUTER_API_KEY='<real>'; $env:QUEUE_CONNECTION='sync'
 *   vendor/bin/phpunit tests/Live/Rag/LiveRagIsolationTest.php
 */
final class LiveRagIsolationTest extends TestCase
{
    private string $tenant;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (getenv('LIVE_RAG') !== '1') {
            return;
        }

        // Repoint the default connection from SQLite to the real pgvector DB so
        // vector(N) columns + cosine search behave for real. Live defaults match
        // the docker container on host port 5433.
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '5433',
            'database' => getenv('DB_DATABASE') ?: 'askmydocs',
            'username' => getenv('DB_USERNAME') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // Force OpenRouter (the gate checks OPENROUTER_API_KEY; phpunit.xml
        // defaults AI_PROVIDER=openai + a sentinel key).
        $app['config']->set('ai.default', 'openrouter');
        $app['config']->set('ai.embeddings_provider', 'openrouter');

        // Inline queue so CanonicalIndexerJob (dispatched after each ingest)
        // builds kb_nodes / kb_edges synchronously — the graph expander needs
        // them — without requiring the operator to run a worker.
        $app['config']->set('queue.default', 'sync');

        // Pin the refusal gate to its shipped defaults so the negative cases'
        // refusal expectation does not depend on the operator's local env.
        $app['config']->set('kb.refusal.min_chunk_similarity', 0.45);
        $app['config']->set('kb.refusal.min_rerank_score', 0.25);
        $app['config']->set('kb.refusal.min_chunks_required', 1);
        // Per-project isolation flag is irrelevant here: the scope under test is
        // the explicit per-turn project filter, not the membership gate. Pin it
        // OFF so the result depends only on the project filter.
        $app['config']->set('kb.project_isolation.enabled', false);
    }

    /** The live DB is already migrated — do NOT run the SQLite test migrations. */
    protected function defineDatabaseMigrations(): void
    {
        // intentionally empty
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('LIVE_RAG') !== '1') {
            $this->markTestSkipped('LIVE_RAG not set to 1 — live isolation suite disabled.');
        }
        $key = (string) getenv('OPENROUTER_API_KEY');
        if ($key === '' || $key === 'sk-or-test-key') {
            $this->markTestSkipped('No real OPENROUTER_API_KEY exported — live isolation suite disabled.');
        }
        try {
            $this->app->make('db')->connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('pgvector database not reachable — live isolation suite disabled: ' . $e->getMessage());
        }

        // Unique per-run tenant (stable prefix + random suffix) so the
        // destructive cleanup is scoped to data THIS run created and can never
        // collide with a real operator tenant on the live database. The project
        // keys stay the REAL case-study keys (readability); the throwaway tenant
        // is the isolator.
        $this->tenant = 'live-iso-tenant-' . bin2hex(random_bytes(5));

        $this->app->make(TenantContext::class)->set($this->tenant);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_case_study_documents_never_cross_company_boundaries_live(): void
    {
        $this->ingestAllCompanies();

        /** @var ChatRetrievalService $retrieval */
        $retrieval = $this->app->make(ChatRetrievalService::class);

        // Aggregate every case so one run reports ALL outcomes (not just the
        // first), which is what an operator wants from a live pass. HARD =
        // isolation breach (the gate); SOFT = the README refusal ideal was
        // missed but nothing leaked (a refusal-calibration signal).
        $leaks = [];
        $refusalMisses = [];

        foreach (IsolationMatrix::cases() as $case) {
            $result = $retrieval->retrieve($case['question'], $case['project'], null);
            $refused = $retrieval->shouldRefuse($result);
            $citations = $retrieval->buildCitations($result);

            $verdict = IsolationMatrix::evaluate($case, $result, $citations, $refused);
            if ($verdict['hard'] !== []) {
                $leaks[$case['id']] = ['project' => $case['project'], 'question' => $case['question'], 'failures' => $verdict['hard']];
            }
            if ($verdict['soft'] !== []) {
                $refusalMisses[$case['id']] = $verdict['soft'];
            }
        }

        // The isolation contract: no document crosses a company boundary.
        $this->assertSame(
            [],
            $leaks,
            'Documentation isolation BROKEN for ' . count($leaks) . " case(s):\n"
                . json_encode($leaks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        // The README's refusal ideal is a separate (relevance-calibration)
        // property: a cross-company question answered from the company's OWN
        // docs without leaking is NOT an isolation breach. Enforce it only when
        // LIVE_RAG_STRICT=1 so the default run stays green on a correctly
        // isolating system whose refusal threshold simply grounds same-vocab
        // off-topic questions.
        if (getenv('LIVE_RAG_STRICT') === '1') {
            $this->assertSame(
                [],
                $refusalMisses,
                'Refusal ideal not met (LIVE_RAG_STRICT) for ' . count($refusalMisses) . " case(s) — answered instead of refused, no leak:\n"
                    . json_encode($refusalMisses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }
    }

    /**
     * Ingest all three companies' canonical markdown into the throwaway tenant,
     * under their real project keys, and sanity-check the document counts.
     */
    private function ingestAllCompanies(): void
    {
        /** @var DocumentIngestor $ingestor */
        $ingestor = $this->app->make(DocumentIngestor::class);

        foreach (IsolationMatrix::PROJECTS as $project) {
            $files = IsolationMatrix::documentsFor($project);
            $this->assertNotEmpty($files, "No case-study documents found for {$project} — dataset missing?");

            foreach ($files as $absolutePath) {
                $markdown = file_get_contents($absolutePath);
                $this->assertNotFalse($markdown, "Unable to read {$absolutePath}.");

                $basename = basename($absolutePath);
                $ingestor->ingestMarkdown(
                    $project,
                    "case-studies/{$project}/{$basename}",
                    pathinfo($basename, PATHINFO_FILENAME),
                    $markdown,
                );
            }

            $ingested = KnowledgeDocument::query()
                ->where('tenant_id', $this->tenant)
                ->where('project_key', $project)
                ->count();
            $this->assertSame(
                count($files),
                $ingested,
                "Expected " . count($files) . " ingested docs for {$project}, got {$ingested}.",
            );
        }
    }

    /**
     * Remove every row this run wrote, scoped to the throwaway tenant + the
     * three case-study project keys. Mirrors LiveRagPipelineTest::cleanup().
     */
    private function cleanup(): void
    {
        if (getenv('LIVE_RAG') !== '1' || ! isset($this->tenant)) {
            return;
        }

        foreach (IsolationMatrix::PROJECTS as $project) {
            KbEdge::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            KbNode::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            KbDocAnalysis::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            // graph_rebuild audit rows have no FK to knowledge_documents (the
            // forensic trail survives hard deletes by design), so they must be
            // removed explicitly or the throwaway run leaks audit noise.
            KbCanonicalAudit::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            KbSearchFailure::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            KnowledgeChunk::query()->where('tenant_id', $this->tenant)->where('project_key', $project)->delete();
            KnowledgeDocument::withTrashed()->where('tenant_id', $this->tenant)->where('project_key', $project)->forceDelete();
        }
    }
}
