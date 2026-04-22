<?php

namespace Tests\Feature\Kb\Retrieval;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Retrieval\GraphExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GraphExpanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_feature_disabled(): void
    {
        config()->set('kb.graph.expansion_enabled', false);
        $doc = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $this->wireGraph('acme', 'dec-a', 'DEC-A', 'mod-b', 'decision_for');

        $seed = collect([$this->seedSearchResult($doc, 'A body')]);

        $expanded = $this->expander()->expand($seed, 'acme');

        $this->assertTrue($expanded->isEmpty());
    }

    public function test_returns_empty_when_project_key_missing(): void
    {
        // Graph is always tenant-scoped — expansion without a project is unsafe.
        $doc = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $this->wireGraph('acme', 'dec-a', 'DEC-A', 'mod-b', 'decision_for');

        $seed = collect([$this->seedSearchResult($doc, 'A body')]);

        $this->assertTrue($this->expander()->expand($seed, null)->isEmpty());
        $this->assertTrue($this->expander()->expand($seed, '')->isEmpty());
    }

    public function test_returns_empty_when_no_canonical_seeds(): void
    {
        // Non-canonical seed — nothing to expand from.
        $doc = KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Plain',
            'source_path' => 'plain.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('z', 64),
            'version_hash' => str_repeat('z', 64),
            'is_canonical' => false,
        ]);
        $seed = collect([$this->seedSearchResult($doc, 'plain body')]);

        $this->assertTrue($this->expander()->expand($seed, 'acme')->isEmpty());
    }

    public function test_expands_one_hop_for_decision_for_edge(): void
    {
        $decision = $this->seedCanonicalDoc('acme', 'dec-cache', 'decision', 'Cache v2');
        $module = $this->seedCanonicalDoc('acme', 'mod-cache', 'module-kb', 'Cache module');
        $this->seedChunk($module, 0, 'Module body explaining cache layer.');

        $this->wireGraph('acme', 'dec-cache', $decision->doc_id, 'mod-cache', 'decision_for', 1.0);

        $seed = collect([$this->seedSearchResult($decision, 'Decision body')]);

        $expanded = $this->expander()->expand($seed, 'acme');

        $this->assertSame(1, $expanded->count());
        $first = $expanded->first();
        $this->assertSame('mod-cache', $first['document']['slug']);
        $this->assertSame('graph_expansion', $first['metadata']['origin']);
        $this->assertSame('decision_for', $first['metadata']['edge_type']);
    }

    public function test_respects_edge_type_allowlist(): void
    {
        config()->set('kb.graph.expansion_edge_types', ['decision_for']);
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $module = $this->seedCanonicalDoc('acme', 'mod-a', 'module-kb', 'A module');
        $this->seedChunk($module, 0, 'body');

        // An edge of type `related_to` — NOT in the allowlist, must be skipped.
        $this->wireGraph('acme', 'dec-a', $decision->doc_id, 'mod-a', 'related_to', 0.5);

        $seed = collect([$this->seedSearchResult($decision, 'd')]);

        $this->assertTrue($this->expander()->expand($seed, 'acme')->isEmpty());
    }

    public function test_drops_dangling_targets(): void
    {
        // Edge exists but the target node is dangling — the target doc has
        // not been canonicalized yet, so there is no chunk to return.
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $this->wireGraph('acme', 'dec-a', $decision->doc_id, 'not-yet', 'decision_for', 1.0);
        KbNode::where('project_key', 'acme')->where('node_uid', 'not-yet')->update([
            'payload_json' => ['dangling' => true],
        ]);

        $seed = collect([$this->seedSearchResult($decision, 'd')]);

        $this->assertTrue($this->expander()->expand($seed, 'acme')->isEmpty());
    }

    public function test_drops_superseded_or_archived_targets(): void
    {
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $superseded = $this->seedCanonicalDoc('acme', 'mod-old', 'module-kb', 'Old', status: 'superseded');
        $this->seedChunk($superseded, 0, 'old body');

        $this->wireGraph('acme', 'dec-a', $decision->doc_id, 'mod-old', 'decision_for', 1.0);

        $seed = collect([$this->seedSearchResult($decision, 'd')]);

        $this->assertTrue($this->expander()->expand($seed, 'acme')->isEmpty());
    }

    public function test_skips_seeds_already_in_the_result_set(): void
    {
        // A seed doc must not appear again in the expansion.
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        $module = $this->seedCanonicalDoc('acme', 'mod-b', 'module-kb', 'B');
        $this->seedChunk($module, 0, 'b body');

        $this->wireGraph('acme', 'dec-a', $decision->doc_id, 'mod-b', 'decision_for', 1.0);
        $this->wireGraph('acme', 'mod-b', $module->doc_id, 'dec-a', 'related_to', 0.5);

        // Both docs are already in the seed — expansion should add nothing.
        $seed = collect([
            $this->seedSearchResult($decision, 'd'),
            $this->seedSearchResult($module, 'b'),
        ]);

        $expanded = $this->expander()->expand($seed, 'acme');
        $this->assertTrue($expanded->isEmpty());
    }

    public function test_respects_max_nodes_cap(): void
    {
        config()->set('kb.graph.expansion_max_nodes', 2);
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');

        foreach (['m1', 'm2', 'm3', 'm4'] as $i => $slug) {
            $mod = $this->seedCanonicalDoc('acme', $slug, 'module-kb', strtoupper($slug));
            $this->seedChunk($mod, 0, "body $slug");
            $this->wireGraph('acme', 'dec-a', $decision->doc_id, $slug, 'decision_for', 1.0 - $i * 0.1);
        }

        $seed = collect([$this->seedSearchResult($decision, 'd')]);

        $this->assertSame(2, $this->expander()->expand($seed, 'acme')->count());
    }

    public function test_expansion_is_bounded_to_constant_queries_regardless_of_neighbour_count(): void
    {
        // Regression for Copilot PR #11 comment: pre-fix, pickRepresentativeChunk
        // ran one query per target → N+1. This asserts we scale linearly in
        // queries-per-request: 1 edge query + 1 doc query + 1 batch chunk query,
        // independent of the neighbour fan-out.
        $decision = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A');
        foreach (range(1, 8) as $i) {
            $mod = $this->seedCanonicalDoc('acme', "m$i", 'module-kb', "M$i");
            $this->seedChunk($mod, 0, "body $i");
            $this->wireGraph('acme', 'dec-a', $decision->doc_id, "m$i", 'decision_for', 1.0 - $i * 0.01);
        }
        $seed = collect([$this->seedSearchResult($decision, 'd')]);

        \DB::enableQueryLog();
        $expanded = $this->expander()->expand($seed, 'acme');
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(8, $expanded->count());
        // Budget: 1 (edges) + 1 (target docs) + 1 (batch chunks) = 3 queries.
        // Leave a small buffer for Laravel internals; the key is it must
        // not scale with neighbour count. Pre-fix this would be ~10.
        $this->assertLessThanOrEqual(4, $queryCount, "Expected ≤4 queries, got $queryCount (N+1 regression).");
    }

    public function test_multi_tenant_isolation_on_expansion(): void
    {
        // Same slug in both tenants, each with its own edge target. Seed
        // from A should only expand into A's graph.
        $decisionA = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X-acme');
        $modA = $this->seedCanonicalDoc('acme', 'mod-acme', 'module-kb', 'Acme module');
        $this->seedChunk($modA, 0, 'acme body');
        $this->wireGraph('acme', 'dec-x', $decisionA->doc_id, 'mod-acme', 'decision_for', 1.0);

        $decisionB = $this->seedCanonicalDoc('beta', 'dec-x', 'decision', 'X-beta');
        $modB = $this->seedCanonicalDoc('beta', 'mod-beta', 'module-kb', 'Beta module');
        $this->seedChunk($modB, 0, 'beta body');
        $this->wireGraph('beta', 'dec-x', $decisionB->doc_id, 'mod-beta', 'decision_for', 1.0);

        $expanded = $this->expander()->expand(collect([$this->seedSearchResult($decisionA, 'd')]), 'acme');

        $slugs = $expanded->pluck('document.slug')->all();
        $this->assertSame(['mod-acme'], $slugs);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function expander(): GraphExpander
    {
        return new GraphExpander();
    }

    private function seedCanonicalDoc(
        string $projectKey,
        string $slug,
        string $type,
        string $title,
        string $status = 'accepted',
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
            'canonical_status' => $status,
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
            'embedding' => array_fill(0, 1536, 0.0),
        ]);
    }

    private function wireGraph(
        string $projectKey,
        string $fromSlug,
        ?string $fromDocId,
        string $toSlug,
        string $edgeType,
        float $weight = 1.0,
    ): void {
        KbNode::firstOrCreate(
            ['project_key' => $projectKey, 'node_uid' => $fromSlug],
            ['node_type' => 'decision', 'label' => $fromSlug, 'source_doc_id' => $fromDocId, 'payload_json' => ['dangling' => false]],
        );
        KbNode::firstOrCreate(
            ['project_key' => $projectKey, 'node_uid' => $toSlug],
            ['node_type' => 'module', 'label' => $toSlug, 'source_doc_id' => null, 'payload_json' => ['dangling' => false]],
        );
        KbEdge::create([
            'edge_uid' => "{$fromSlug}->{$toSlug}:{$edgeType}",
            'from_node_uid' => $fromSlug,
            'to_node_uid' => $toSlug,
            'edge_type' => $edgeType,
            'project_key' => $projectKey,
            'source_doc_id' => $fromDocId,
            'weight' => $weight,
            'provenance' => 'wikilink',
        ]);
    }

    /**
     * @return array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}
     */
    private function seedSearchResult(KnowledgeDocument $doc, string $chunkText): array
    {
        $chunk = KnowledgeChunk::where('knowledge_document_id', $doc->id)->where('chunk_order', 0)->first();
        if ($chunk === null) {
            $this->seedChunk($doc, 0, $chunkText);
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
                'is_canonical' => (bool) $doc->is_canonical,
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => (int) $doc->retrieval_priority,
            ],
        ];
    }
}
