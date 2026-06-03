<?php

declare(strict_types=1);

namespace Tests\Live\Rag;

use App\Models\KbDocAnalysis;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KbSearchFailure;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Analytics\SearchFailureRecorder;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\DocumentIngestor;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * LIVE end-to-end RAG pipeline test — real pgvector + real AI provider.
 *
 * This is the automated form of the manual live verification done with the
 * docker `pgvector/pgvector:pg16` container + the real OpenRouter keys: it
 * ingests a canonical markdown doc, confirms real embeddings land in
 * pgvector, the canonical graph is built, a grounded chat answer cites the
 * doc WITH a non-null project_key (the v8.8 live-verification regression —
 * the retrieval select omits document.project_key, so the citation must read
 * it from the chunk), and an ungroundable question is refused WITHOUT an LLM
 * call and recorded as a content gap.
 *
 * THREE gates keep it out of CI / the default suite (it is not in any
 * <testsuite> in phpunit.xml, so `vendor/bin/phpunit` never collects it):
 *
 *   1. LIVE_RAG=1 must be exported.
 *   2. A REAL embeddings+chat key must be present (OPENROUTER_API_KEY not the
 *      `sk-or-test-key` phpunit sentinel).
 *   3. The pgvector database must be reachable (DB_* env, live defaults
 *      127.0.0.1:5433 / askmydocs).
 *
 * It NEVER touches the seeded application data: everything is written under a
 * throwaway tenant + project and torn down in tearDown(). No RefreshDatabase
 * — it runs against the operator's live database on purpose.
 *
 * Operator runbook (PowerShell):
 *   $env:LIVE_RAG='1'; $env:DB_CONNECTION='pgsql'; $env:DB_HOST='127.0.0.1'
 *   $env:DB_PORT='5433'; $env:DB_DATABASE='askmydocs'
 *   $env:AI_PROVIDER='openrouter'; $env:AI_EMBEDDINGS_PROVIDER='openrouter'
 *   $env:OPENROUTER_API_KEY='<real>'; $env:QUEUE_CONNECTION='sync'
 *   vendor/bin/phpunit tests/Live/Rag/LiveRagPipelineTest.php
 */
final class LiveRagPipelineTest extends TestCase
{
    private string $tenant = 'live-rag-tenant';

    private string $project = 'live-rag-project';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Only repoint at the real pgvector database when the live suite is
        // actually armed. When LIVE_RAG is off the test skips in setUp(), and
        // leaving the default SQLite connection in place keeps the skip path
        // identical to every other test (no pgsql driver / connection set up
        // for nothing — which otherwise tripped PHPUnit's risky-test handler
        // check on the skipped run).
        if (getenv('LIVE_RAG') !== '1') {
            return;
        }

        // Repoint the default connection from the SQLite test DB to the real
        // pgvector database so vector(N) columns + cosine search behave for
        // real. Live defaults match the docker container on host port 5433.
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
            $this->markTestSkipped('LIVE_RAG not set to 1 — live RAG suite disabled.');
        }
        $key = (string) getenv('OPENROUTER_API_KEY');
        if ($key === '' || $key === 'sk-or-test-key') {
            $this->markTestSkipped('No real OPENROUTER_API_KEY exported — live RAG suite disabled.');
        }

        // Third gate (per the class docblock): the pgvector database must be
        // reachable. Probe the connection and SKIP — rather than hard-fail with
        // a raw PDOException — when the docker container is down/misconfigured,
        // so an operator run degrades cleanly instead of looking like a bug.
        try {
            $this->app->make('db')->connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('pgvector database not reachable — live RAG suite disabled: ' . $e->getMessage());
        }

        $this->app->make(TenantContext::class)->set($this->tenant);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_canonical_ingest_embeds_builds_graph_grounds_and_refuses_live(): void
    {
        $markdown = <<<'MD'
        ---
        type: decision
        status: accepted
        slug: live-rag-cache
        id: LIVE-RAG-CACHE
        title: Live RAG cache decision
        ---
        # Live RAG cache decision

        We decided to cache hot read endpoints in Redis with a 1 hour TTL,
        keyed by request signature, to cut database read latency.
        MD;

        /** @var DocumentIngestor $ingestor */
        $ingestor = $this->app->make(DocumentIngestor::class);
        $ingestor->ingestMarkdown(
            $this->project,
            "{$this->project}/live-rag-cache.md",
            'Live RAG cache decision',
            $markdown,
        );

        // 1. Real embeddings landed in pgvector.
        $doc = KnowledgeDocument::query()
            ->where('tenant_id', $this->tenant)
            ->where('project_key', $this->project)
            ->where('slug', 'live-rag-cache')
            ->firstOrFail();
        $this->assertTrue((bool) $doc->is_canonical, 'doc should be canonical');
        $embedded = KnowledgeChunk::query()
            ->where('knowledge_document_id', $doc->id)
            ->whereNotNull('embedding')
            ->count();
        $this->assertGreaterThan(0, $embedded, 'at least one chunk must carry a real embedding vector');

        // 2. Canonical graph node exists for the doc.
        $this->assertSame(
            1,
            KbNode::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->where('node_uid', 'live-rag-cache')->count(),
            'a canonical kb_node must be created for the decision',
        );

        // 3. Grounded chat: real retrieval + real LLM, citation carries project_key.
        /** @var ChatRetrievalService $retrieval */
        $retrieval = $this->app->make(ChatRetrievalService::class);
        $result = $retrieval->retrieve('What caching backend and TTL did we decide on?', $this->project, null);
        $this->assertFalse($retrieval->shouldRefuse($result), 'a grounded question must NOT be refused');

        $citations = $retrieval->buildCitations($result);
        $this->assertNotEmpty($citations, 'grounded retrieval must produce citations');
        $first = $citations[0];
        $this->assertSame('live-rag-cache', $first['slug']);
        $this->assertSame(
            $this->project,
            $first['project_key'],
            'project_key must come from the chunk, not the unselected document relation (v8.8 regression)',
        );

        // 4. Ungroundable question → refusal (no grounded context) + content gap.
        $offTopic = 'What is the airspeed velocity of an unladen swallow on Mars?';
        $refuseResult = $retrieval->retrieve($offTopic, $this->project, null);
        $this->assertTrue($retrieval->shouldRefuse($refuseResult), 'an ungroundable question must be refused');

        $this->app->make(SearchFailureRecorder::class)->record($this->project, $offTopic, 'no_relevant_context');
        $this->assertSame(
            1,
            KbSearchFailure::query()
                ->where('tenant_id', $this->tenant)
                ->where('project_key', $this->project)
                ->where('reason', 'no_relevant_context')
                ->count(),
            'the refusal must be recorded as a content gap',
        );
    }

    private function cleanup(): void
    {
        // Only touch the (pgsql) database when the live suite is armed. On the
        // skipped run the default connection is the migration-less SQLite test
        // DB, so a stray DELETE here would hit a non-existent table during
        // tearDown and surface as a spurious error.
        if (getenv('LIVE_RAG') !== '1') {
            return;
        }

        KbEdge::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->delete();
        KbNode::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->delete();
        KbDocAnalysis::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->delete();
        KbSearchFailure::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->delete();
        KnowledgeChunk::query()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->delete();
        KnowledgeDocument::withTrashed()->where('tenant_id', $this->tenant)->where('project_key', $this->project)->forceDelete();
    }
}
