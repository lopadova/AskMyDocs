<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Graph;

use App\Flow\Steps\Graph\DispatchCanonicalIndexerFanOutStep;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class DispatchCanonicalIndexerFanOutStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_dispatches_one_job_per_canonical_doc(): void
    {
        Queue::fake();
        $this->seedDoc('tenant-a', 'p', 'a.md');
        $this->seedDoc('tenant-a', 'p', 'b.md');

        $step = $this->app->make(DispatchCanonicalIndexerFanOutStep::class);
        $result = $step->execute($this->context('tenant-a'));

        $this->assertSame(2, $result->output['dispatched_count']);
        Queue::assertPushed(CanonicalIndexerJob::class, 2);
    }

    public function test_dry_run_skipped(): void
    {
        Queue::fake();
        $this->seedDoc('tenant-a', 'p', 'a.md');

        $step = $this->app->make(DispatchCanonicalIndexerFanOutStep::class);
        $result = $step->execute($this->context('tenant-a', dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Queue::assertNothingPushed();
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(DispatchCanonicalIndexerFanOutStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: [],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation_does_not_dispatch_other_tenants_docs(): void
    {
        Queue::fake();
        // Two tenants on distinct projects — the SQLite test schema's
        // (project_key, slug) UNIQUE prevents collision across tenants
        // (Postgres composite UNIQUE on (tenant_id, project_key, slug)
        // would allow it; we still want a green isolation guard here).
        $this->seedDoc('tenant-a', 'pa', 'a.md');
        $this->seedDoc('tenant-b', 'pb', 'a.md');

        $step = $this->app->make(DispatchCanonicalIndexerFanOutStep::class);
        $step->execute($this->context('tenant-a'));

        Queue::assertPushed(CanonicalIndexerJob::class, 1);
    }

    private function context(string $tenantId, string $projectKey = '', bool $sync = false, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'sync' => $sync,
            ],
            dryRun: $dryRun,
        );
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
}
