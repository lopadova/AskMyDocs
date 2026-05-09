<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Folder;

use App\Flow\Steps\Folder\PruneOrphansStep;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class PruneOrphansStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_no_op_when_prune_orphans_disabled(): void
    {
        $this->seedDoc('default', 'p', 'docs/keep.md');
        $step = $this->app->make(PruneOrphansStep::class);

        $result = $step->execute($this->context(['docs/keep.md'], pruneOrphans: false));

        $this->assertTrue($result->output['skipped']);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_dry_run_skipped(): void
    {
        $this->seedDoc('default', 'p', 'docs/orphan.md');
        $step = $this->app->make(PruneOrphansStep::class);

        $result = $step->execute($this->context([], pruneOrphans: true, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_deletes_doc_whose_source_file_is_missing(): void
    {
        $orphan = $this->seedDoc('default', 'p', 'docs/orphan.md');
        $kept = $this->seedDoc('default', 'p', 'docs/keep.md');

        $step = $this->app->make(PruneOrphansStep::class);
        // The "existing" file list contains keep.md but not orphan.md.
        $result = $step->execute($this->context(['docs/keep.md'], pruneOrphans: true));

        $this->assertSame(1, $result->output['orphans_deleted_count']);
        // Soft delete by default — withTrashed still finds, query() does not.
        $this->assertNull(KnowledgeDocument::find($orphan->id));
        $this->assertNotNull(KnowledgeDocument::find($kept->id));
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(PruneOrphansStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['prune_orphans' => true, 'project_key' => 'p'],
            stepOutputs: ['list-files' => ['matched_files' => []]],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_throws_on_missing_project_key_when_pruning(): void
    {
        $step = $this->app->make(PruneOrphansStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['tenant_id' => 'default', 'prune_orphans' => true],
            stepOutputs: ['list-files' => ['matched_files' => []]],
        );

        $this->expectException(\RuntimeException::class);
        $step->execute($context);
    }

    /**
     * @param  list<string>  $existing
     */
    private function context(array $existing, bool $pruneOrphans, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'p',
                'prune_orphans' => $pruneOrphans,
                'force_delete' => null,
                'relative_base_path' => 'docs',
                'prefix' => '',
            ],
            stepOutputs: [
                'list-files' => ['matched_files' => $existing],
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
        ]);
    }
}
