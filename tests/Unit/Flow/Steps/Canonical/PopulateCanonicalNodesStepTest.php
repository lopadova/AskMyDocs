<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Canonical;

use App\Flow\Steps\Canonical\PopulateCanonicalNodesStep;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class PopulateCanonicalNodesStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserts_self_node_and_dangling_target_nodes(): void
    {
        $doc = $this->makeCanonicalDoc([
            'frontmatter_json' => ['_derived' => [
                'related_slugs' => ['mod-a'],
                'supersedes_slugs' => [],
                'superseded_by_slugs' => [],
            ]],
        ]);

        $step = $this->app->make(PopulateCanonicalNodesStep::class);
        $result = $step->execute($this->contextAfterLoad($doc));

        $this->assertTrue($result->success);
        $this->assertSame(2, KbNode::count(), 'self node + 1 target dangling node');

        $self = KbNode::where('node_uid', 'dec-x')->first();
        $this->assertNotNull($self);
        $this->assertFalse($self->payload_json['dangling']);

        $target = KbNode::where('node_uid', 'mod-a')->first();
        $this->assertNotNull($target);
        $this->assertTrue($target->payload_json['dangling']);

        // created_node_ids must include both new nodes.
        $this->assertCount(2, $result->output['created_node_ids']);
    }

    public function test_does_not_record_existing_nodes_as_created(): void
    {
        // Pre-existing target node — should NOT be tracked as created so
        // the compensator never deletes it on rollback.
        KbNode::create([
            'project_key' => 'acme',
            'node_uid' => 'mod-existing',
            'node_type' => 'module',
            'label' => 'Existing',
            'source_doc_id' => 'MOD-EXIST',
            'payload_json' => ['dangling' => false],
        ]);

        $doc = $this->makeCanonicalDoc([
            'frontmatter_json' => ['_derived' => [
                'related_slugs' => ['mod-existing'],
                'supersedes_slugs' => [],
                'superseded_by_slugs' => [],
            ]],
        ]);

        $step = $this->app->make(PopulateCanonicalNodesStep::class);
        $result = $step->execute($this->contextAfterLoad($doc));

        // Only the self node should be tracked as created (the target
        // already existed).
        $this->assertCount(1, $result->output['created_node_ids']);

        $existing = KbNode::where('node_uid', 'mod-existing')->first();
        $this->assertSame('Existing', $existing->label, 'existing node label preserved');
    }

    public function test_dry_run_returns_skipped(): void
    {
        $doc = $this->makeCanonicalDoc();
        $step = $this->app->make(PopulateCanonicalNodesStep::class);

        $result = $step->execute($this->contextAfterLoad($doc, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, KbNode::count());
    }

    public function test_propagates_load_short_circuit(): void
    {
        $step = $this->app->make(PopulateCanonicalNodesStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.canonical-index',
            input: ['tenant_id' => 'default', 'document_id' => 1],
            stepOutputs: ['load-document' => ['indexable' => false, 'reason' => 'not_canonical']],
            dryRun: false,
        );

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['skipped']);
        $this->assertSame(0, KbNode::count());
    }

    private function contextAfterLoad(KnowledgeDocument $doc, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'nodes-test-run',
            definitionName: 'kb.canonical-index',
            input: ['tenant_id' => 'default', 'document_id' => $doc->id],
            stepOutputs: [
                'load-document' => [
                    'indexable' => true,
                    'document_id' => (int) $doc->id,
                    'project_key' => (string) $doc->project_key,
                    'slug' => (string) $doc->slug,
                    'doc_id' => $doc->doc_id,
                    'canonical_type' => (string) $doc->canonical_type,
                    'canonical_status' => $doc->canonical_status,
                    'retrieval_priority' => (int) $doc->retrieval_priority,
                    'title' => (string) $doc->title,
                ],
            ],
            dryRun: $dryRun,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCanonicalDoc(array $overrides = []): KnowledgeDocument
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
}
