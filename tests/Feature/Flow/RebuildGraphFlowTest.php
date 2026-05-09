<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\RebuildGraphFlow;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class RebuildGraphFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_truncates_target_tenant_graph_and_dispatches_indexers(): void
    {
        Queue::fake();
        $this->seedDoc('tenant-a', 'pa', 'a.md');
        $this->seedNode('tenant-a', 'pa', 'old-node');

        $tc = $this->app->make(TenantContext::class);
        $tc->set('tenant-a');

        $run = Flow::execute(
            RebuildGraphFlow::NAME,
            [
                'tenant_id' => 'tenant-a',
                'project_key' => '',
                'truncate' => true,
                'sync' => false,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-a', idempotencyKey: 'rebuild-graph:tenant-a:nonce-1'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, KbNode::where('tenant_id', 'tenant-a')->count(), 'tenant-a old node truncated');
        Queue::assertPushed(CanonicalIndexerJob::class, 1);
    }

    public function test_other_tenants_graph_untouched(): void
    {
        Queue::fake();
        $this->seedNode('tenant-a', 'pa', 'na');
        $this->seedNode('tenant-b', 'pb', 'nb');

        $tc = $this->app->make(TenantContext::class);
        $tc->set('tenant-a');

        Flow::execute(
            RebuildGraphFlow::NAME,
            [
                'tenant_id' => 'tenant-a',
                'project_key' => '',
                'truncate' => true,
                'sync' => false,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-a', idempotencyKey: 'rebuild-graph:tenant-a:nonce-2'),
        );

        $this->assertSame(0, KbNode::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, KbNode::where('tenant_id', 'tenant-b')->count());
    }

    public function test_no_truncate_preserves_existing_nodes(): void
    {
        Queue::fake();
        $this->seedDoc('tenant-a', 'pa', 'a.md');
        $this->seedNode('tenant-a', 'pa', 'old-node');

        Flow::execute(
            RebuildGraphFlow::NAME,
            [
                'tenant_id' => 'tenant-a',
                'project_key' => '',
                'truncate' => false,
                'sync' => false,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-a', idempotencyKey: 'rebuild-graph:tenant-a:nonce-3'),
        );

        $this->assertSame(1, KbNode::where('tenant_id', 'tenant-a')->count(), 'no-truncate path preserved old node');
    }

    private function seedDoc(string $tenantId, string $projectKey, string $sourcePath): KnowledgeDocument
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.$sourcePath.'doc'),
            'version_hash' => hash('sha256', $tenantId.$sourcePath.'ver'),
            'metadata' => null,
            'is_canonical' => true,
            'doc_id' => 'DOC-'.$sourcePath,
            'slug' => 'slug-'.basename($sourcePath, '.md'),
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
        ]);
    }

    private function seedNode(string $tenantId, string $projectKey, string $nodeUid): void
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        KbNode::create([
            'project_key' => $projectKey,
            'node_uid' => $nodeUid,
            'node_type' => 'decision',
            'label' => $nodeUid,
            'source_doc_id' => $nodeUid,
            'payload_json' => ['dangling' => false],
        ]);
    }
}
