<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalIndexerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_self_node_for_a_canonical_decision(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-cache-v2', 'decision', 'Cache invalidation v2');

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_nodes', [
            'node_uid' => 'dec-cache-v2',
            'node_type' => 'decision',
            'project_key' => 'acme',
            'label' => 'Cache invalidation v2',
            'source_doc_id' => $doc->doc_id,
        ]);
    }

    public function test_creates_edges_for_frontmatter_related_slugs(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-cache-v2', 'decision', 'Cache v2', [
            '_derived' => ['related_slugs' => ['module-cache', 'runbook-purge'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-cache-v2',
            'to_node_uid' => 'module-cache',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
            'provenance' => 'frontmatter_related',
        ]);
        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-cache-v2',
            'to_node_uid' => 'runbook-purge',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
        ]);
    }

    public function test_creates_edges_for_wikilinks_in_chunks(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X');
        $this->seedChunk($doc, 0, 'See [[module-a]].', ['module-a']);
        $this->seedChunk($doc, 1, 'Also [[module-b]] and [[module-c]].', ['module-b', 'module-c']);

        (new CanonicalIndexerJob($doc->id))->handle();

        foreach (['module-a', 'module-b', 'module-c'] as $target) {
            $this->assertDatabaseHas('kb_edges', [
                'from_node_uid' => 'dec-x',
                'to_node_uid' => $target,
                'provenance' => 'wikilink',
                'project_key' => 'acme',
            ]);
        }
    }

    public function test_creates_dangling_target_nodes_for_unknown_slugs(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['not-yet-canonicalized'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $target = KbNode::where('node_uid', 'not-yet-canonicalized')->where('project_key', 'acme')->first();
        $this->assertNotNull($target);
        $this->assertTrue($target->payload_json['dangling'] ?? false);
    }

    public function test_preserves_existing_canonicalized_target_node(): void
    {
        // Pre-existing canonical node in the same project — indexer must NOT
        // mark it as dangling (would overwrite real metadata).
        KbNode::create([
            'node_uid' => 'module-cache',
            'node_type' => 'module',
            'label' => 'Existing module',
            'project_key' => 'acme',
            'source_doc_id' => 'MOD-0001',
            'payload_json' => ['dangling' => false],
        ]);

        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['module-cache'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $existing = KbNode::where('node_uid', 'module-cache')->first();
        $this->assertSame('Existing module', $existing->label);
        $this->assertSame('MOD-0001', $existing->source_doc_id);
        $this->assertFalse($existing->payload_json['dangling']);
    }

    public function test_replaces_previous_edges_on_reindex(): void
    {
        // First run: edges to A and B.
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['a', 'b'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        (new CanonicalIndexerJob($doc->id))->handle();
        $this->assertSame(2, KbEdge::where('from_node_uid', 'dec-x')->count());

        // Update: frontmatter_json now references a, c (b removed).
        $fresh = KnowledgeDocument::find($doc->id);
        $fresh->frontmatter_json = ['_derived' => ['related_slugs' => ['a', 'c'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []]];
        $fresh->save();

        (new CanonicalIndexerJob($doc->id))->handle();

        $targets = KbEdge::where('from_node_uid', 'dec-x')->pluck('to_node_uid')->all();
        sort($targets);
        $this->assertSame(['a', 'c'], $targets);
    }

    public function test_supersedes_generates_typed_edge(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-v2', 'decision', 'V2', [
            '_derived' => ['related_slugs' => [], 'supersedes_slugs' => ['dec-v1'], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-v2',
            'to_node_uid' => 'dec-v1',
            'edge_type' => 'supersedes',
            'provenance' => 'frontmatter_supersedes',
        ]);
    }

    public function test_writes_audit_row_on_success(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X');
        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'doc_id' => $doc->doc_id,
            'slug' => 'dec-x',
            'event_type' => 'promoted',
        ]);
    }

    public function test_no_op_when_document_is_archived(): void
    {
        // Regression for Copilot PR #10 comment: an archived row must not
        // rebuild the graph from stale content.
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['m1'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $doc->update(['status' => 'archived']);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_ensureTargetNode_is_race_safe_under_concurrent_runs(): void
    {
        // Simulate two workers that both tried to create the same target
        // node. The second call must NOT raise — firstOrCreate short-circuits
        // on the composite unique.
        $docA = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A', [
            '_derived' => ['related_slugs' => ['shared-target'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $docB = $this->seedCanonicalDoc('acme', 'dec-b', 'decision', 'B', [
            '_derived' => ['related_slugs' => ['shared-target'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($docA->id))->handle();
        (new CanonicalIndexerJob($docB->id))->handle();

        $this->assertSame(1, KbNode::where('node_uid', 'shared-target')->count());
        // Both edges reach the same target.
        $this->assertSame(2, KbEdge::where('to_node_uid', 'shared-target')->count());
    }

    public function test_no_op_for_non_canonical_document(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Plain doc',
            'source_path' => 'docs/plain.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('z', 64),
            'version_hash' => str_repeat('z', 64),
            'is_canonical' => false,
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_multi_tenant_isolation(): void
    {
        // Same slug in two projects, each with their own target wikilink.
        $docA = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X-acme', [
            '_derived' => ['related_slugs' => ['mod-a'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $docB = $this->seedCanonicalDoc('beta', 'dec-x', 'decision', 'X-beta', [
            '_derived' => ['related_slugs' => ['mod-b'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($docA->id))->handle();
        (new CanonicalIndexerJob($docB->id))->handle();

        // Self nodes coexist in both projects.
        $this->assertSame(2, KbNode::where('node_uid', 'dec-x')->count());
        // Targets are tenant-isolated.
        $this->assertSame(1, KbNode::where('node_uid', 'mod-a')->count());
        $this->assertSame(1, KbNode::where('node_uid', 'mod-b')->count());
        // Edges never cross tenants.
        $acmeEdges = KbEdge::where('project_key', 'acme')->pluck('to_node_uid')->all();
        $betaEdges = KbEdge::where('project_key', 'beta')->pluck('to_node_uid')->all();
        $this->assertSame(['mod-a'], $acmeEdges);
        $this->assertSame(['mod-b'], $betaEdges);
    }

    // -------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------

    private function seedCanonicalDoc(
        string $projectKey,
        string $slug,
        string $type,
        string $title,
        array $frontmatterJson = []
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
            'frontmatter_json' => $frontmatterJson,
        ]);
    }

    private function seedChunk(KnowledgeDocument $doc, int $order, string $text, array $wikilinks): void
    {
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text . $order),
            'heading_path' => null,
            'chunk_text' => $text,
            'metadata' => ['wikilinks' => $wikilinks, 'strategy' => 'section_aware'],
            'embedding' => array_fill(0, 1536, 0.0),
        ]);
    }
}
