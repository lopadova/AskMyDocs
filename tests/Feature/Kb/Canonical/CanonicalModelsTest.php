<?php

namespace Tests\Feature\Kb\Canonical;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalModelsTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------
    // KbNode
    // -------------------------------------------------------------

    public function test_kb_node_is_mass_assignable_with_expected_fields(): void
    {
        $node = KbNode::create([
            'node_uid' => 'dec-cache-v2',
            'node_type' => 'decision',
            'label' => 'Cache invalidation v2',
            'project_key' => 'acme',
            'source_doc_id' => 'DEC-2026-0001',
            'payload_json' => ['dangling' => false, 'tags' => ['cache']],
        ]);

        $this->assertSame('dec-cache-v2', $node->node_uid);
        $this->assertSame(['dangling' => false, 'tags' => ['cache']], $node->payload_json);
    }

    public function test_kb_node_outgoing_edges_relation(): void
    {
        KbNode::create(['node_uid' => 'a', 'node_type' => 'module', 'label' => 'A', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'b', 'node_type' => 'decision', 'label' => 'B', 'project_key' => 'acme']);
        KbEdge::create([
            'edge_uid' => 'a->b',
            'from_node_uid' => 'a',
            'to_node_uid' => 'b',
            'edge_type' => 'decision_for',
            'project_key' => 'acme',
            'weight' => 1.0,
            'provenance' => 'wikilink',
        ]);

        $a = KbNode::where('node_uid', 'a')->first();
        $this->assertCount(1, $a->outgoingEdges);
        $this->assertCount(0, $a->incomingEdges);

        $b = KbNode::where('node_uid', 'b')->first();
        $this->assertCount(1, $b->incomingEdges);
    }

    public function test_kb_node_scopes_filter_by_project_and_type(): void
    {
        KbNode::create(['node_uid' => 'a', 'node_type' => 'decision', 'label' => 'A', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'b', 'node_type' => 'module', 'label' => 'B', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'c', 'node_type' => 'decision', 'label' => 'C', 'project_key' => 'beta']);

        $this->assertSame(2, KbNode::forProject('acme')->count());
        $this->assertSame(2, KbNode::ofType('decision')->count());
        $this->assertSame(1, KbNode::forProject('acme')->ofType('decision')->count());
    }

    // -------------------------------------------------------------
    // KbEdge
    // -------------------------------------------------------------

    public function test_kb_edge_belongs_to_both_nodes(): void
    {
        KbNode::create(['node_uid' => 'x', 'node_type' => 'decision', 'label' => 'X', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'y', 'node_type' => 'module', 'label' => 'Y', 'project_key' => 'acme']);
        $edge = KbEdge::create([
            'edge_uid' => 'x->y',
            'from_node_uid' => 'x',
            'to_node_uid' => 'y',
            'edge_type' => 'decision_for',
            'project_key' => 'acme',
            'weight' => 0.75,
            'provenance' => 'frontmatter_related',
        ]);

        $this->assertSame('X', $edge->fromNode->label);
        $this->assertSame('Y', $edge->toNode->label);
        $this->assertSame(0.75, $edge->weight);
    }

    public function test_kb_edge_scopes_filter_correctly(): void
    {
        KbNode::create(['node_uid' => 'n1', 'node_type' => 'decision', 'label' => 'N1', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'n2', 'node_type' => 'module', 'label' => 'N2', 'project_key' => 'acme']);
        KbEdge::create(['edge_uid' => 'n1->n2', 'from_node_uid' => 'n1', 'to_node_uid' => 'n2', 'edge_type' => 'decision_for', 'project_key' => 'acme', 'weight' => 1.0, 'provenance' => 'wikilink']);
        KbEdge::create(['edge_uid' => 'n2->n1', 'from_node_uid' => 'n2', 'to_node_uid' => 'n1', 'edge_type' => 'related_to', 'project_key' => 'acme', 'weight' => 0.5, 'provenance' => 'wikilink']);

        $this->assertSame(2, KbEdge::forProject('acme')->count());
        $this->assertSame(1, KbEdge::ofType('decision_for')->count());
    }

    // -------------------------------------------------------------
    // KbCanonicalAudit
    // -------------------------------------------------------------

    public function test_kb_canonical_audit_persists_structured_payload(): void
    {
        $row = KbCanonicalAudit::create([
            'project_key' => 'acme',
            'doc_id' => 'DEC-2026-0001',
            'slug' => 'dec-cache-v2',
            'event_type' => 'promoted',
            'actor' => 'lopadova@users.noreply.github.com',
            'before_json' => null,
            'after_json' => ['status' => 'accepted'],
            'metadata_json' => ['ip' => '127.0.0.1'],
        ]);

        $fresh = KbCanonicalAudit::find($row->id);
        $this->assertSame(['status' => 'accepted'], $fresh->after_json);
        $this->assertSame(['ip' => '127.0.0.1'], $fresh->metadata_json);
        $this->assertNotNull($fresh->created_at);
    }

    // -------------------------------------------------------------
    // KnowledgeDocument canonical scopes
    // -------------------------------------------------------------

    public function test_knowledge_document_canonical_scope_filters_is_canonical(): void
    {
        $this->seedCanonicalDoc(['slug' => 'dec-a', 'canonical_type' => 'decision', 'canonical_status' => 'accepted', 'is_canonical' => true]);
        $this->seedCanonicalDoc(['slug' => null, 'canonical_type' => null, 'canonical_status' => null, 'is_canonical' => false]);

        $this->assertSame(1, KnowledgeDocument::canonical()->count());
    }

    public function test_knowledge_document_accepted_scope(): void
    {
        $this->seedCanonicalDoc(['slug' => 'a', 'canonical_status' => 'accepted']);
        $this->seedCanonicalDoc(['slug' => 'b', 'canonical_status' => 'draft']);
        $this->seedCanonicalDoc(['slug' => 'c', 'canonical_status' => 'superseded']);

        $this->assertSame(1, KnowledgeDocument::accepted()->count());
    }

    public function test_accepted_scope_implies_is_canonical(): void
    {
        // Regression for Copilot review on PR #9: a non-canonical row with
        // a stray canonical_status='accepted' must NOT leak into accepted()
        // because the scope now implies canonical() first.
        $this->seedCanonicalDoc([
            'slug' => 'ghost',
            'canonical_status' => 'accepted',
            'is_canonical' => false,  // simulate a bad backfill / manual update
        ]);

        $this->assertSame(0, KnowledgeDocument::accepted()->count());
    }

    public function test_knowledge_document_byType_scope(): void
    {
        $this->seedCanonicalDoc(['slug' => 'a', 'canonical_type' => 'decision']);
        $this->seedCanonicalDoc(['slug' => 'b', 'canonical_type' => 'runbook']);
        $this->seedCanonicalDoc(['slug' => 'c', 'canonical_type' => 'decision']);

        $this->assertSame(2, KnowledgeDocument::byType('decision')->count());
        $this->assertSame(1, KnowledgeDocument::byType('runbook')->count());
    }

    public function test_knowledge_document_bySlug_scope_is_project_scoped(): void
    {
        $this->seedCanonicalDoc(['project_key' => 'acme', 'slug' => 'dec-x']);
        $this->seedCanonicalDoc(['project_key' => 'beta', 'slug' => 'dec-x']);

        $this->assertSame(1, KnowledgeDocument::bySlug('acme', 'dec-x')->count());
        $this->assertSame('acme', KnowledgeDocument::bySlug('acme', 'dec-x')->first()->project_key);
    }

    public function test_frontmatter_json_is_cast_to_array(): void
    {
        $this->seedCanonicalDoc([
            'slug' => 'a',
            'frontmatter_json' => ['owners' => ['platform']],
        ]);
        $doc = KnowledgeDocument::where('slug', 'a')->first();
        $this->assertSame(['owners' => ['platform']], $doc->frontmatter_json);
    }

    /**
     * Helper: create a canonical-ish document with sensible defaults.
     */
    private function seedCanonicalDoc(array $overrides = []): KnowledgeDocument
    {
        static $counter = 0;
        $counter++;
        $defaults = [
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => "Doc $counter",
            'source_path' => "docs/doc-$counter.md",
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat(dechex($counter % 16), 64),
            'slug' => "slug-$counter",
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 50,
        ];

        return KnowledgeDocument::create(array_merge($defaults, $overrides));
    }
}
