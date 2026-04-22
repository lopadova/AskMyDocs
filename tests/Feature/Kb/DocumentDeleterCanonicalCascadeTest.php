<?php

namespace Tests\Feature\Kb;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeleterCanonicalCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    public function test_hard_delete_of_canonical_doc_removes_its_graph_nodes(): void
    {
        $doc = $this->seedCanonicalDocWithGraph('acme', 'dec-x', 'DEC-0001');

        (new DocumentDeleter())->delete($doc, force: true);

        $this->assertSame(0, KbNode::where('source_doc_id', 'DEC-0001')->count());
    }

    public function test_hard_delete_cascades_outgoing_edges_via_fk(): void
    {
        $doc = $this->seedCanonicalDocWithGraph('acme', 'dec-x', 'DEC-0001');
        // Extra target node + edge from the doc
        KbNode::create([
            'node_uid' => 'mod-target',
            'node_type' => 'module',
            'label' => 'Target',
            'project_key' => 'acme',
            'source_doc_id' => 'MOD-TARGET-0001',
        ]);
        KbEdge::create([
            'edge_uid' => 'dec-x->mod-target:related_to',
            'from_node_uid' => 'dec-x',
            'to_node_uid' => 'mod-target',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
            'source_doc_id' => 'DEC-0001',
            'weight' => 0.5,
            'provenance' => 'wikilink',
        ]);

        (new DocumentDeleter())->delete($doc, force: true);

        $this->assertSame(0, KbEdge::where('from_node_uid', 'dec-x')->count());
        // Target node remains — it belongs to a different doc
        $this->assertSame(1, KbNode::where('node_uid', 'mod-target')->count());
    }

    public function test_hard_delete_writes_deprecation_audit(): void
    {
        $doc = $this->seedCanonicalDocWithGraph('acme', 'dec-x', 'DEC-0001');

        (new DocumentDeleter())->delete($doc, force: true);

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-x',
            'event_type' => 'deprecated',
            'actor' => 'document-deleter',
        ]);
    }

    public function test_hard_delete_of_non_canonical_doc_does_not_touch_graph_or_audit(): void
    {
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

        (new DocumentDeleter())->delete($doc, force: true);

        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_soft_delete_leaves_graph_intact(): void
    {
        // Soft delete is reversible and users still expect to navigate the
        // graph during the retention window — cascade only on hard delete.
        $doc = $this->seedCanonicalDocWithGraph('acme', 'dec-x', 'DEC-0001');

        (new DocumentDeleter())->delete($doc, force: false);

        $this->assertSame(1, KbNode::where('source_doc_id', 'DEC-0001')->count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_cascade_falls_back_to_slug_when_doc_id_is_null(): void
    {
        // Regression for Copilot PR #10 comment: a canonical doc can have
        // a slug without a doc_id (CanonicalParser doesn't require `id`).
        // The indexer would create a kb_node with source_doc_id=null,
        // so cascade must also match by (project_key, node_uid=slug).
        $doc = KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'No ID canonical',
            'source_path' => 'decisions/dec-no-id.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('q', 64),
            'version_hash' => str_repeat('q', 64),
            'doc_id' => null,
            'slug' => 'dec-no-id',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
        ]);
        KbNode::create([
            'node_uid' => 'dec-no-id',
            'node_type' => 'decision',
            'label' => 'No ID canonical',
            'project_key' => 'acme',
            'source_doc_id' => null,
            'payload_json' => ['dangling' => false],
        ]);

        (new DocumentDeleter())->delete($doc, force: true);

        $this->assertSame(0, KbNode::where('node_uid', 'dec-no-id')->count());
    }

    public function test_multi_tenant_isolation_on_graph_cascade(): void
    {
        // Same doc_id shape in two projects — must delete only one tenant's nodes.
        $a = $this->seedCanonicalDocWithGraph('acme', 'dec-x', 'DEC-0001');
        $b = $this->seedCanonicalDocWithGraph('beta', 'dec-x', 'DEC-0001');

        (new DocumentDeleter())->delete($a, force: true);

        $this->assertSame(0, KbNode::where('project_key', 'acme')->count());
        $this->assertSame(1, KbNode::where('project_key', 'beta')->count());
    }

    private function seedCanonicalDocWithGraph(string $projectKey, string $slug, string $docId): KnowledgeDocument
    {
        static $i = 0;
        $i++;
        $doc = KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Doc $i",
            'source_path' => "decisions/$slug.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $i, 64, 'a'),
            'version_hash' => str_pad((string) $i, 64, 'b'),
            'doc_id' => $docId,
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
        ]);

        KbNode::create([
            'node_uid' => $slug,
            'node_type' => 'decision',
            'label' => "Doc $i",
            'project_key' => $projectKey,
            'source_doc_id' => $docId,
            'payload_json' => ['dangling' => false],
        ]);

        return $doc;
    }
}
