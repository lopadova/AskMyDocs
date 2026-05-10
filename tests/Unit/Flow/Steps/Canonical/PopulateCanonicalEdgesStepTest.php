<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Canonical;

use App\Flow\Steps\Canonical\PopulateCanonicalEdgesStep;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class PopulateCanonicalEdgesStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserts_typed_edges_from_frontmatter(): void
    {
        $doc = $this->seedCanonicalDoc([
            'frontmatter_json' => ['_derived' => [
                'related_slugs' => ['mod-a'],
                'supersedes_slugs' => ['dec-old'],
                'superseded_by_slugs' => [],
            ]],
        ]);
        // The composite FK on kb_edges requires both endpoints to exist.
        // Production order: PopulateCanonicalNodesStep runs FIRST and
        // seeds the self-node + targets; here we mirror that by seeding
        // the nodes manually so this step's responsibility (edge writes)
        // can be exercised in isolation.
        $this->seedNode('acme', 'dec-x', 'decision', false);
        $this->seedNode('acme', 'mod-a', 'module', false);
        $this->seedNode('acme', 'dec-old', 'decision', false);

        $step = $this->app->make(PopulateCanonicalEdgesStep::class);
        $result = $step->execute($this->contextAfterLoad($doc));

        $this->assertTrue($result->success);
        $this->assertSame(2, KbEdge::where('from_node_uid', 'dec-x')->count());

        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-x',
            'to_node_uid' => 'mod-a',
            'edge_type' => 'related_to',
            'provenance' => 'frontmatter_related',
        ]);
        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-x',
            'to_node_uid' => 'dec-old',
            'edge_type' => 'supersedes',
            'provenance' => 'frontmatter_supersedes',
        ]);
    }

    public function test_replaces_prior_outgoing_edges_idempotently(): void
    {
        $doc = $this->seedCanonicalDoc();
        $this->seedNode('acme', 'dec-x', 'decision', false);
        $this->seedNode('acme', 'a', 'module', false);
        $this->seedNode('acme', 'b', 'module', false);
        $this->seedNode('acme', 'c', 'module', false);

        // Pre-existing edge to b that the indexer should clean up.
        KbEdge::create([
            'project_key' => 'acme',
            'edge_uid' => 'dec-x->b:related_to',
            'from_node_uid' => 'dec-x',
            'to_node_uid' => 'b',
            'edge_type' => 'related_to',
            'source_doc_id' => 'OLD',
            'weight' => 0.5,
            'provenance' => 'frontmatter_related',
        ]);

        $doc->update(['frontmatter_json' => [
            '_derived' => ['related_slugs' => ['a', 'c'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]]);

        $step = $this->app->make(PopulateCanonicalEdgesStep::class);
        $step->execute($this->contextAfterLoad($doc->fresh()));

        $targets = KbEdge::where('from_node_uid', 'dec-x')->pluck('to_node_uid')->all();
        sort($targets);
        $this->assertSame(['a', 'c'], $targets, 'prior edge to "b" must be replaced');
    }

    public function test_writes_graph_rebuild_audit_row(): void
    {
        $doc = $this->seedCanonicalDoc();
        $this->seedNode('acme', 'dec-x', 'decision', false);
        $step = $this->app->make(PopulateCanonicalEdgesStep::class);
        $step->execute($this->contextAfterLoad($doc));

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-x',
            'event_type' => 'graph_rebuild',
        ]);
    }

    public function test_dry_run_returns_skipped(): void
    {
        $doc = $this->seedCanonicalDoc();
        $step = $this->app->make(PopulateCanonicalEdgesStep::class);

        $result = $step->execute($this->contextAfterLoad($doc, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    private function contextAfterLoad(KnowledgeDocument $doc, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'edges-test-run',
            definitionName: 'kb.canonical-index',
            input: ['tenant_id' => 'default', 'document_id' => $doc->id],
            stepOutputs: [
                'load-document' => [
                    'indexable' => true,
                    'document_id' => (int) $doc->id,
                    'project_key' => (string) $doc->project_key,
                    'slug' => (string) $doc->slug,
                    'doc_id' => $doc->doc_id,
                ],
            ],
            dryRun: $dryRun,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedCanonicalDoc(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Decision X',
            'source_path' => 'decisions/dec-x.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-x',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 80,
            'frontmatter_json' => ['_derived' => ['related_slugs' => [], 'supersedes_slugs' => [], 'superseded_by_slugs' => []]],
        ], $overrides));
    }

    private function seedNode(string $project, string $uid, string $type, bool $dangling): void
    {
        KbNode::create([
            'project_key' => $project,
            'node_uid' => $uid,
            'node_type' => $type,
            'label' => $uid,
            'source_doc_id' => null,
            'payload_json' => ['dangling' => $dangling],
        ]);
    }
}
