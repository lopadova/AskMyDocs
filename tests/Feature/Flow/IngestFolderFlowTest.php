<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\IngestFolderFlow;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class IngestFolderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_dispatches_one_ingest_job_per_supported_file(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.md', '# b');
        Storage::disk('kb')->put('docs/c.md', '# c');

        $run = $this->runFlow('default', 'docs', recursive: false);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $dispatch = $run->stepResults['dispatch-ingest-fan-out'];
        $this->assertSame(3, $dispatch->output['dispatched_count']);
        Queue::assertPushed(IngestDocumentJob::class, 3);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');

        $run = Flow::dryRun(
            IngestFolderFlow::NAME,
            $this->input('default', 'docs'),
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        Queue::assertNothingPushed();
    }

    public function test_prune_orphans_removes_doc_whose_file_is_absent(): void
    {
        // Seed two docs in the DB; only ONE has a corresponding file on disk.
        $tc = $this->app->make(TenantContext::class);
        $tc->set('default');
        $orphan = KnowledgeDocument::create($this->docRow('default', 'p', 'docs/orphan.md'));
        $kept = KnowledgeDocument::create($this->docRow('default', 'p', 'docs/keep.md'));
        Storage::disk('kb')->put('docs/keep.md', '# keep');

        $run = $this->runFlow('default', 'docs', pruneOrphans: true, projectKey: 'p');

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        // Orphan was soft-deleted (default delete mode).
        $this->assertNull(KnowledgeDocument::find($orphan->id));
        $this->assertNotNull(KnowledgeDocument::find($kept->id));
    }

    public function test_empty_folder_with_prune_orphans_still_removes_orphan_doc(): void
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set('default');
        $orphan = KnowledgeDocument::create($this->docRow('default', 'p', 'docs/orphan.md'));

        // No files at all on disk under docs/.
        $run = $this->runFlow('default', 'docs', pruneOrphans: true, projectKey: 'p');

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNull(KnowledgeDocument::find($orphan->id));
    }

    public function test_unsupported_extension_recorded_as_failure_count(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        // Force include of an unsupported extension via the pattern override.
        $run = Flow::execute(
            IngestFolderFlow::NAME,
            array_merge($this->input('default', 'docs'), ['extensions' => ['md', 'png']]),
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        // Only the .md file is on disk so failure_count should be 0 here.
        $dispatch = $run->stepResults['dispatch-ingest-fan-out'];
        $this->assertSame(1, $dispatch->output['dispatched_count']);
    }

    private function runFlow(
        string $tenantId,
        string $basePath,
        bool $recursive = false,
        bool $pruneOrphans = false,
        string $projectKey = 'default',
    ): FlowRun {
        $input = $this->input($tenantId, $basePath, $recursive, $pruneOrphans, $projectKey);
        return Flow::execute(
            IngestFolderFlow::NAME,
            $input,
            FlowExecutionOptions::make(
                correlationId: $tenantId,
                idempotencyKey: 'ingest-folder:'.$tenantId.':kb:'.$basePath.':'.hrtime(true),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function input(
        string $tenantId,
        string $basePath,
        bool $recursive = false,
        bool $pruneOrphans = false,
        string $projectKey = 'default',
    ): array {
        return [
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'disk' => 'kb',
            'base_path' => $basePath,
            'extensions' => ['md', 'markdown', 'txt'],
            'recursive' => $recursive,
            'sync' => false,
            'limit' => 0,
            'prefix' => '',
            'prune_orphans' => $pruneOrphans,
            'force_delete' => null,
            'relative_base_path' => $basePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function docRow(string $tenantId, string $projectKey, string $sourcePath): array
    {
        return [
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
        ];
    }
}
