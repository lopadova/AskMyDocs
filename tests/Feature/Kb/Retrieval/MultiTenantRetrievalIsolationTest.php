<?php

namespace Tests\Feature\Kb\Retrieval;

use App\Ai\EmbeddingsResponse;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Reranker;
use App\Services\Kb\Retrieval\GraphExpander;
use App\Services\Kb\Retrieval\RejectedApproachInjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

/**
 * Hard invariant: graph expansion and rejected-approach injection MUST
 * never cross tenant boundaries. Same slugs / doc_ids can legitimately
 * exist in two projects; the project_key is the one-and-only isolator.
 *
 * These tests drive KbSearchService::searchWithContext() end-to-end
 * across a two-tenant fixture where every primitive (canonical doc,
 * kb_node, kb_edge, rejected-approach chunk) exists in BOTH tenants
 * with identical shape. A single wrong scoping would cross-pollute.
 */
class MultiTenantRetrievalIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_graph_expansion_never_crosses_tenant_boundary(): void
    {
        $this->seedTwoTenantsWithSharedSlugs();

        $expander = new GraphExpander();
        $seed = collect([$this->fakeSeedChunk('acme', 'dec-shared', 'acme decision body')]);

        $expanded = $expander->expand($seed, 'acme');

        // All expanded targets must belong to acme.
        foreach ($expanded as $chunk) {
            $this->assertSame('acme', $chunk['project_key']);
            $this->assertSame('acme', $chunk['document']['project_key'] ?? 'acme');
        }
    }

    public function test_rejected_injection_never_crosses_tenant_boundary(): void
    {
        $this->seedRejectedInBothTenants();

        $injector = new RejectedApproachInjector(
            $this->fakeEmbeddings([0.1, 0.2, 0.3]),
            new FakeCosineCalculator(0.80),
        );

        $result = $injector->pick('query', 'acme');

        foreach ($result as $chunk) {
            $this->assertSame('acme', $chunk['project_key']);
        }
    }

    public function test_rejected_injection_on_beta_returns_beta_docs_only(): void
    {
        $this->seedRejectedInBothTenants();

        $injector = new RejectedApproachInjector(
            $this->fakeEmbeddings([0.1, 0.2, 0.3]),
            new FakeCosineCalculator(0.80),
        );

        $result = $injector->pick('query', 'beta');

        $this->assertGreaterThan(0, $result->count());
        foreach ($result as $chunk) {
            $this->assertSame('beta', $chunk['project_key']);
        }
    }

    public function test_searchWithContext_never_leaks_expansion_across_tenants(): void
    {
        // End-to-end: prime the search (vector+FTS) via a mocked
        // reranker output, then assert the downstream expansion + rejected
        // results never reference the wrong tenant.
        $this->seedTwoTenantsWithSharedSlugs();
        $this->seedRejectedInBothTenants();

        $service = $this->buildSearchServiceWithPrimedPrimary('acme', 'dec-shared');

        $result = $service->searchWithContext('any query', 'acme', limit: 3, minSimilarity: 0.0);

        foreach ($result->primary as $chunk) {
            $this->assertSame('acme', $chunk['project_key']);
        }
        foreach ($result->expanded as $chunk) {
            $this->assertSame('acme', $chunk['project_key']);
        }
        foreach ($result->rejected as $chunk) {
            $this->assertSame('acme', $chunk['project_key']);
        }
    }

    // -----------------------------------------------------------------
    // fixtures: two-tenant mirrored data
    // -----------------------------------------------------------------

    private function seedTwoTenantsWithSharedSlugs(): void
    {
        foreach (['acme', 'beta'] as $project) {
            $decision = $this->seedCanonicalDoc($project, 'dec-shared', 'decision', ucfirst($project) . ' shared decision');
            $module = $this->seedCanonicalDoc($project, 'mod-shared', 'module-kb', ucfirst($project) . ' shared module');
            $this->seedChunk($module, 0, "$project module body");

            KbNode::firstOrCreate(
                ['project_key' => $project, 'node_uid' => 'dec-shared'],
                ['node_type' => 'decision', 'label' => 'Shared decision', 'source_doc_id' => $decision->doc_id, 'payload_json' => ['dangling' => false]],
            );
            KbNode::firstOrCreate(
                ['project_key' => $project, 'node_uid' => 'mod-shared'],
                ['node_type' => 'module', 'label' => 'Shared module', 'source_doc_id' => $module->doc_id, 'payload_json' => ['dangling' => false]],
            );
            KbEdge::create([
                'edge_uid' => "dec-shared->mod-shared:decision_for",
                'from_node_uid' => 'dec-shared',
                'to_node_uid' => 'mod-shared',
                'edge_type' => 'decision_for',
                'project_key' => $project,
                'source_doc_id' => $decision->doc_id,
                'weight' => 1.0,
                'provenance' => 'wikilink',
            ]);
        }
    }

    private function seedRejectedInBothTenants(): void
    {
        foreach (['acme', 'beta'] as $project) {
            $doc = $this->seedCanonicalDoc($project, 'rej-dup', 'rejected-approach', ucfirst($project) . ' rejected');
            $this->seedChunk($doc, 0, "$project rejected body");
        }
    }

    private function seedCanonicalDoc(
        string $projectKey,
        string $slug,
        string $type,
        string $title,
    ): KnowledgeDocument {
        static $counter = 0;
        $counter++;
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => "{$type}s/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $counter, 64, 'a'),
            'version_hash' => str_pad((string) $counter, 64, 'b'),
            'doc_id' => strtoupper(substr($type, 0, 3)) . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'slug' => $slug,
            'canonical_type' => $type,
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
        ]);
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

    // -----------------------------------------------------------------
    // seed / mocks
    // -----------------------------------------------------------------

    /**
     * @return array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}
     */
    private function fakeSeedChunk(string $projectKey, string $slug, string $text): array
    {
        $doc = KnowledgeDocument::where('project_key', $projectKey)->where('slug', $slug)->first();
        $chunk = KnowledgeChunk::where('knowledge_document_id', $doc->id)->where('chunk_order', 0)->first();
        if ($chunk === null) {
            $this->seedChunk($doc, 0, $text);
            $chunk = KnowledgeChunk::where('knowledge_document_id', $doc->id)->where('chunk_order', 0)->first();
        }
        return [
            'chunk_id' => $chunk->id,
            'project_key' => $doc->project_key,
            'heading_path' => null,
            'chunk_text' => $chunk->chunk_text,
            'metadata' => [],
            'vector_score' => 0.9,
            'document' => [
                'id' => $doc->id,
                'title' => $doc->title,
                'source_path' => $doc->source_path,
                'source_type' => $doc->source_type,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'is_canonical' => true,
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => (int) $doc->retrieval_priority,
            ],
        ];
    }

    private function fakeEmbeddings(array $vector): EmbeddingCacheService
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturn(new EmbeddingsResponse(
            embeddings: [$vector],
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        return $cache;
    }

    /**
     * Build a KbSearchService whose base `search()` is stubbed to return
     * exactly one seed chunk from the named project. All downstream
     * services (expander + injector) use real code paths against the
     * fixture DB.
     */
    private function buildSearchServiceWithPrimedPrimary(string $projectKey, string $seedSlug): KbSearchService
    {
        $service = Mockery::mock(KbSearchService::class, [
            $this->fakeEmbeddings([0.1, 0.2, 0.3]),
            new Reranker(),
            new GraphExpander(),
            new RejectedApproachInjector(
                $this->fakeEmbeddings([0.1, 0.2, 0.3]),
                new FakeCosineCalculator(0.80),
            ),
        ])->makePartial();

        $seedChunk = $this->fakeSeedChunk($projectKey, $seedSlug, "$projectKey seed body");
        $service->shouldReceive('search')->andReturn(collect([$seedChunk]));

        return $service;
    }
}
