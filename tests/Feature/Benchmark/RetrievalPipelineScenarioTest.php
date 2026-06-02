<?php

declare(strict_types=1);

namespace Tests\Feature\Benchmark;

use App\Ai\EmbeddingsResponse;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Retrieval\RetrievalGrounding;
use App\Support\Benchmark\DeterministicEmbedder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * v8.2 WS3 — the registered, deterministic, NO-KEY end-to-end RAG scenario.
 *
 * Ingests the real benchmark corpus (markdown + PDF + DOCX) through the
 * REAL pipeline (converter → per-type chunker → embed → persist → canonical
 * graph), then exercises searchWithContext() and asserts the full giro:
 * per-type chunking, embeddings, graph nodes/edges + 1-hop expansion (related
 * docs), primary ranking, evidence-grade citations, and the refusal gate.
 *
 * Crucially this runs the REAL KbSearchService against SQLite via the new
 * PHP-cosine fallback (no pgvector, no mock) — closing the gap that let the
 * P0.1 shape bug ship green. Embeddings are deterministic + content-
 * discriminative (DeterministicEmbedder), so ranking is meaningful without
 * an API key. True semantic quality (paraphrase, rejected-injection
 * thresholds) is validated separately by the LIVE kb:benchmark.
 */
final class RetrievalPipelineScenarioTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> filename => mime */
    private const CORPUS = [
        'cache-invalidation.md' => 'text/markdown',
        'cache-purge-runbook.md' => 'text/markdown',
        'rejected-clientside-caching.md' => 'text/markdown',
        'incident-runbook.pdf' => 'application/pdf',
        'onboarding-guide.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, content-discriminative embeddings — no API key.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: DeterministicEmbedder::embedBatch($texts),
                provider: 'stub',
                model: 'deterministic-'.DeterministicEmbedder::DEFAULT_DIM,
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);

        config([
            'queue.default' => 'sync',                  // run CanonicalIndexerJob inline → graph built
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,        // FTS is pgsql-only; semantic-only on sqlite
            'kb.graph.expansion_enabled' => true,
            'kb.rejected.injection_enabled' => true,
            'kb.refusal.min_chunk_similarity' => 0.45,
            'kb.refusal.min_rerank_score' => 0.25,
            'kb.refusal.min_chunks_required' => 1,
        ]);

        app(TenantContext::class)->set('default');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function ingestCorpus(): void
    {
        $ingestor = app(DocumentIngestor::class);
        // resource_path() points at Testbench's skeleton, not the repo, so
        // resolve the corpus relative to this test file (repo root).
        $dir = dirname(__DIR__, 3).'/resources/benchmark/corpus';

        foreach (self::CORPUS as $name => $mime) {
            $bytes = (string) file_get_contents($dir.'/'.$name);
            $source = new SourceDocument(
                sourcePath: $name,
                mimeType: $mime,
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            );
            $ingestor->ingest('benchmark', $source, pathinfo($name, PATHINFO_FILENAME));
        }
    }

    public function test_ingestion_persists_all_three_doc_types_with_per_type_chunking_and_embeddings(): void
    {
        $this->ingestCorpus();

        $this->assertSame(5, KnowledgeDocument::query()->forTenant('default')->count(), 'all 5 corpus docs ingested');
        $this->assertSame(3, KnowledgeDocument::query()->forTenant('default')->where('is_canonical', true)->count(), '3 canonical markdown docs');

        // Per-type chunking: the PDF (2 pages) must produce >= 2 chunks via
        // PdfPageChunker; every chunk must carry a DeterministicEmbedder
        // (4096-dim) embedding.
        $pdf = KnowledgeDocument::query()->forTenant('default')->where('source_path', 'incident-runbook.pdf')->firstOrFail();
        $this->assertGreaterThanOrEqual(2, $pdf->chunks()->count(), 'PDF split per page');

        $docx = KnowledgeDocument::query()->forTenant('default')->where('source_path', 'onboarding-guide.docx')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $docx->chunks()->count());

        $sample = KnowledgeChunk::query()->forTenant('default')->firstOrFail();
        $this->assertCount(DeterministicEmbedder::DEFAULT_DIM, (array) $sample->embedding, 'embedding persisted');
    }

    public function test_canonical_graph_nodes_and_edges_are_built_from_frontmatter_wikilinks(): void
    {
        $this->ingestCorpus();

        // Canonical docs become nodes; the [[wikilink]] between the cache
        // decision and its runbook becomes an edge.
        $this->assertTrue(KbNode::query()->forTenant('default')->where('node_uid', 'cache-invalidation')->exists());
        $this->assertTrue(KbNode::query()->forTenant('default')->where('node_uid', 'cache-purge-runbook')->exists());
        $this->assertGreaterThanOrEqual(
            1,
            KbEdge::query()->forTenant('default')->where(fn ($q) => $q->where('from_node_uid', 'cache-invalidation')->orWhere('to_node_uid', 'cache-invalidation'))->count(),
            'cache-invalidation is graph-linked',
        );
    }

    public function test_search_ranks_a_cache_doc_first_for_a_cache_query(): void
    {
        $this->ingestCorpus();

        $result = app(KbSearchService::class)->searchWithContext(
            query: 'What TTL and event-based purge does our cache invalidation use?',
            projectKey: 'benchmark',
            limit: 5,
            minSimilarity: 0.05,
        );

        $this->assertGreaterThan(0, $result->primary->count(), 'primary results returned');
        // A cache query must rank a cache doc first — NOT the onboarding or
        // DB-incident docs. (Exact #1 of the two cache docs is asserted by
        // the LIVE benchmark via nDCG; both are genuinely relevant here.)
        $top = data_get($result->primary->first(), 'document.source_path');
        $this->assertContains($top, ['cache-invalidation.md', 'cache-purge-runbook.md'], 'cache doc ranks #1');
    }

    public function test_graph_expansion_surfaces_the_related_doc_not_already_retrieved(): void
    {
        $this->ingestCorpus();

        // limit:1 keeps only the single best chunk in primary, so the
        // graph-linked partner is NOT already retrieved — forcing 1-hop
        // expansion to pull it in via the related_to edge.
        $result = app(KbSearchService::class)->searchWithContext(
            query: 'What TTL and event-based purge does our cache invalidation use?',
            projectKey: 'benchmark',
            limit: 1,
            minSimilarity: 0.05,
        );

        $primaryPaths = $result->primary->pluck('document.source_path')->all();
        $expandedPaths = $result->expanded->pluck('document.source_path')->all();

        // The two cache docs are graph-linked (related_to). Whichever is the
        // single primary, its partner must surface via expansion.
        $cacheDocs = ['cache-invalidation.md', 'cache-purge-runbook.md'];
        $primaryCache = array_values(array_intersect($primaryPaths, $cacheDocs));
        $this->assertNotEmpty($primaryCache, 'a cache doc is the primary seed');
        $partner = $primaryCache[0] === 'cache-invalidation.md' ? 'cache-purge-runbook.md' : 'cache-invalidation.md';
        $this->assertContains($partner, $expandedPaths, 'graph expansion surfaced the related partner');
    }

    public function test_citations_are_origin_aware_and_evidence_grade(): void
    {
        $this->ingestCorpus();

        $svc = app(\App\Services\Kb\Chat\ChatRetrievalService::class);
        $result = $svc->retrieve('How do I recover when the Redis cache is unreachable?', 'benchmark');
        $citations = $svc->buildCitations($result);

        $this->assertNotEmpty($citations);
        $first = $citations[0];
        $this->assertArrayHasKey('origin', $first);
        $this->assertArrayHasKey('chunks', $first);
        $this->assertNotEmpty($first['chunks'][0]['evidence_hash'], 'citation carries an evidence hash');
    }

    public function test_hybrid_search_with_string_config_does_not_typeerror(): void
    {
        // Regression (caught by the LIVE benchmark, v8.2 WS5): rrf_k + weights
        // arrive from env as STRINGS; under strict_types reciprocalRankFusion
        // (int $k, float ...) TypeErrors. The suite runs hybrid OFF by default
        // so it never exercised this — pin it with explicit string config.
        config([
            'kb.hybrid_search.enabled' => true,
            'kb.hybrid_search.rrf_k' => '60',
            'kb.hybrid_search.semantic_weight' => '0.70',
            'kb.hybrid_search.fts_weight' => '0.30',
        ]);
        $this->ingestCorpus();

        $result = app(KbSearchService::class)->searchWithContext(
            query: 'What TTL and event-based purge does our cache invalidation use?',
            projectKey: 'benchmark',
            limit: 5,
            minSimilarity: 0.05,
        );

        $this->assertGreaterThan(0, $result->primary->count(), 'hybrid search returns results without a TypeError');
    }

    public function test_refusal_gate_refuses_a_query_with_no_grounding_document(): void
    {
        $this->ingestCorpus();

        // Token-disjoint from the (cache / DB-incident / onboarding) corpus.
        // The nuanced "plausible but absent topic" refusal (e.g. HR policy)
        // is a SEMANTIC property validated by the LIVE benchmark with real
        // embeddings; the deterministic token-bag embedder can only model a
        // genuine no-match via a lexically-disjoint query.
        $result = app(KbSearchService::class)->searchWithContext(
            query: 'How do I bake sourdough bread at home?',
            projectKey: 'benchmark',
            limit: 5,
            minSimilarity: 0.30, // production default floor
        );

        // Nothing in the corpus matches → nothing clears the grounding gate.
        $this->assertTrue(
            RetrievalGrounding::shouldRefuse($result->primary),
            'an unanswerable query must refuse',
        );
    }
}
