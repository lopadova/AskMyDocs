<?php

namespace Tests\Feature\Kb\Retrieval;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Retrieval\RejectedApproachInjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RejectedApproachInjectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_empty_when_feature_disabled(): void
    {
        config()->set('kb.rejected.injection_enabled', false);
        $this->seedRejected('acme', 'rej-a', 'Bad idea body');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('query', 'acme');

        $this->assertTrue($result->isEmpty());
    }

    public function test_returns_empty_when_project_key_missing(): void
    {
        $this->seedRejected('acme', 'rej-a', 'body');
        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', null);
        $this->assertTrue($result->isEmpty());

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', '');
        $this->assertTrue($result->isEmpty());
    }

    public function test_returns_empty_when_no_rejected_docs_exist(): void
    {
        // Accepted decisions + modules exist but zero rejected-approach docs
        // → injector must never surface them.
        KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Decision A',
            'source_path' => 'decisions/a.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-a',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
        ]);
        $this->assertTrue($this->injector([0.1, 0.2, 0.3])->pick('q', 'acme')->isEmpty());
    }

    public function test_surfaces_a_rejected_doc_above_similarity_threshold(): void
    {
        $this->seedRejected('acme', 'rej-x', 'Never purge cache on price change — bad!');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertSame(1, $result->count());
        $first = $result->first();
        $this->assertSame('rej-x', $first['document']['slug']);
        $this->assertSame('rejected', $first['metadata']['origin']);
        $this->assertArrayHasKey('similarity', $first['metadata']);
    }

    public function test_respects_max_docs_cap(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedRejected('acme', "rej-$i", "body $i");
        }

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme', maxDocs: 2);

        $this->assertSame(2, $result->count());
    }

    public function test_filters_by_min_similarity_threshold(): void
    {
        config()->set('kb.rejected.min_similarity', 0.99);
        $this->seedRejected('acme', 'rej-x', 'body');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertTrue($result->isEmpty());
    }

    public function test_only_returns_one_chunk_per_rejected_doc(): void
    {
        $doc = $this->seedRejected('acme', 'rej-x', 'chunk one');
        $this->seedChunk($doc, 1, 'chunk two same doc');
        $this->seedChunk($doc, 2, 'chunk three same doc');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertSame(1, $result->count());
    }

    public function test_ignores_rejected_docs_in_draft_or_superseded_status(): void
    {
        $this->seedRejected('acme', 'rej-draft', 'body', status: 'draft');
        $this->seedRejected('acme', 'rej-super', 'body', status: 'superseded');
        $this->seedRejected('acme', 'rej-accepted', 'body', status: 'accepted');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertSame(1, $result->count());
        $this->assertSame('rej-accepted', $result->first()['document']['slug']);
    }

    public function test_load_is_bounded_to_summary_chunks_only(): void
    {
        // Regression for Copilot PR #11 comment: loadCandidateChunks used to
        // do an unbounded ->get() of every chunk of every rejected doc.
        // With the chunk_order=0 restriction, an N-chunks-per-doc scenario
        // loads exactly N docs (one summary chunk each), not N×chunks.
        $doc = $this->seedRejected('acme', 'rej-big', 'summary chunk');
        // Add 40 non-summary chunks — they must NOT appear in candidates.
        for ($i = 1; $i <= 40; $i++) {
            $this->seedChunk($doc, $i, "body part $i");
        }

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertSame(1, $result->count());
        $this->assertSame('summary chunk', $result->first()['chunk_text']);
    }

    public function test_chunk_with_mismatched_embedding_dimensions_is_skipped_gracefully(): void
    {
        // Regression for Copilot PR #11 comment (CosineCalculator).
        // Simulates a bad embedding dimension on one rejected chunk.
        // The injector must skip it (log) and keep processing other docs.
        $bad = $this->seedRejected('acme', 'rej-bad', 'bad body');
        $good = $this->seedRejected('acme', 'rej-good', 'good body');
        // Overwrite one chunk's embedding to a different dimension.
        $bad->chunks()->first()->update(['embedding' => [0.1]]);  // 1 dim vs 3-dim query

        // Real CosineCalculator → will throw on the bad chunk internally.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturn(new \App\Ai\EmbeddingsResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        $injector = new \App\Services\Kb\Retrieval\RejectedApproachInjector(
            $cache,
            new \App\Services\Kb\Retrieval\CosineCalculator(),
        );

        // Must not throw; good doc still surfaces.
        $result = $injector->pick('q', 'acme');

        $slugs = $result->pluck('document.slug')->all();
        $this->assertContains('rej-good', $slugs);
        $this->assertNotContains('rej-bad', $slugs);
    }

    public function test_multi_tenant_isolation(): void
    {
        $this->seedRejected('acme', 'rej-x', 'acme rejected body');
        $this->seedRejected('beta', 'rej-x', 'beta rejected body');

        $result = $this->injector([0.1, 0.2, 0.3])->pick('q', 'acme');

        $this->assertSame(1, $result->count());
        // The surfaced doc must be acme's — same slug in beta should stay invisible.
        $this->assertSame('acme rejected body', $result->first()['chunk_text']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function injector(array $queryEmbedding): RejectedApproachInjector
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturn(new EmbeddingsResponse(
            embeddings: [$queryEmbedding],
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        // Force similarity calculation to bypass pgvector (SQLite in tests
        // stores embeddings as JSON). We inject a FakeCosineCalculator below
        // that returns 0.80 by default — above the 0.45 threshold.
        return new RejectedApproachInjector($cache, new FakeCosineCalculator(0.80));
    }

    private function seedRejected(string $projectKey, string $slug, string $body, string $status = 'accepted'): KnowledgeDocument
    {
        static $counter = 0;
        $counter++;
        $doc = KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Rejected $slug",
            'source_path' => "rejected/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $counter, 64, 'a'),
            'version_hash' => str_pad((string) $counter, 64, 'b'),
            'doc_id' => 'REJ-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'slug' => $slug,
            'canonical_type' => 'rejected-approach',
            'canonical_status' => $status,
            'is_canonical' => true,
            'retrieval_priority' => 50,
            'frontmatter_json' => ['_derived' => ['summary' => "Rejected: $body"]],
        ]);
        $this->seedChunk($doc, 0, $body);
        return $doc;
    }

    private function seedChunk(KnowledgeDocument $doc, int $order, string $text): void
    {
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text . $order . $doc->id),
            'heading_path' => null,
            'chunk_text' => $text,
            'metadata' => [],
            'embedding' => array_fill(0, 3, 0.1),
        ]);
    }
}
