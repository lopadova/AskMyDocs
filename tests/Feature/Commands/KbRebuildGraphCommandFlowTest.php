<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end coverage for the v4.2 Flow refactor of `kb:rebuild-graph` —
 * tenant fan-out + idempotency-busting nonce.
 */
final class KbRebuildGraphCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_fans_out_per_tenant_and_dispatches_indexer_jobs(): void
    {
        Queue::fake();
        $this->seedCanonicalDoc('tenant-a', 'pa', 'a.md');
        $this->seedCanonicalDoc('tenant-b', 'pb', 'b.md');

        $this->artisan('kb:rebuild-graph')
            ->assertSuccessful();

        Queue::assertPushed(CanonicalIndexerJob::class, 2);
    }

    public function test_tenant_filter_restricts_dispatch(): void
    {
        Queue::fake();
        $this->seedCanonicalDoc('tenant-a', 'pa', 'a.md');
        $this->seedCanonicalDoc('tenant-b', 'pb', 'b.md');

        $this->artisan('kb:rebuild-graph', ['--tenant' => 'tenant-a'])
            ->assertSuccessful();

        Queue::assertPushed(CanonicalIndexerJob::class, 1);
    }

    public function test_no_canonical_docs_short_circuits(): void
    {
        Queue::fake();
        $this->artisan('kb:rebuild-graph')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_truncates_existing_graph_for_target_tenant(): void
    {
        Queue::fake();
        $this->seedCanonicalDoc('tenant-a', 'pa', 'a.md');
        $this->seedNode('tenant-a', 'pa', 'old-node');
        $this->seedNode('tenant-b', 'pb', 'untouched');

        $this->artisan('kb:rebuild-graph', ['--tenant' => 'tenant-a'])
            ->assertSuccessful();

        $this->assertSame(0, KbNode::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, KbNode::where('tenant_id', 'tenant-b')->count());
    }

    private function seedCanonicalDoc(string $tenantId, string $projectKey, string $sourcePath): KnowledgeDocument
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
