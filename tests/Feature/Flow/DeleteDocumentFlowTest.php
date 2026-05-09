<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\DeleteDocumentFlow;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class DeleteDocumentFlowTest extends TestCase
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

    public function test_soft_delete_only_when_force_false(): void
    {
        $doc = $this->seedDoc('default', 'acme', 'docs/x.md');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'default',
                'document_id' => $doc->id,
                'force' => false,
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, KnowledgeDocument::count());
        $this->assertSame(1, KnowledgeDocument::onlyTrashed()->count());
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    public function test_hard_delete_removes_rows_and_file(): void
    {
        $doc = $this->seedDoc('default', 'acme', 'docs/x.md');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'default',
                'document_id' => $doc->id,
                'force' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
        Storage::disk('kb')->assertMissing('docs/x.md');
    }

    public function test_hard_delete_with_keep_file_preserves_disk(): void
    {
        $doc = $this->seedDoc('default', 'acme', 'docs/x.md');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'default',
                'document_id' => $doc->id,
                'force' => true,
                'keep_file' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    public function test_not_found_short_circuits_with_success_run(): void
    {
        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'default',
                'project_key' => 'acme',
                'source_path' => 'docs/missing.md',
                'force' => false,
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $loadOutput = $run->stepResults['load-document']->output ?? [];
        $this->assertFalse($loadOutput['found'] ?? false);
    }

    public function test_tenant_isolation_does_not_delete_other_tenant_doc(): void
    {
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->seedDoc('tenant-a', 'shared', 'docs/x.md');

        $tenants->set('tenant-b');
        $docB = $this->seedDoc('tenant-b', 'shared', 'docs/x.md');

        // Delete tenant-a's doc by document_id.
        $tenants->set('tenant-a');
        Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'tenant-a',
                'document_id' => $docA->id,
                'force' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-a'),
        );

        // Tenant-b's doc must still exist.
        $this->assertNotNull(KnowledgeDocument::find($docB->id));
        $this->assertNull(KnowledgeDocument::withTrashed()->find($docA->id));
    }

    public function test_r30_cross_tenant_path_lookup_does_not_resolve_other_tenant_doc(): void
    {
        // R30 — the (project_key, source_path) lookup branch in
        // LoadDocumentForDeleteStep must apply forTenant() so two tenants
        // sharing the same project_key + source_path cannot accidentally
        // resolve each other's row. Without the explicit scope, the
        // first match wins regardless of tenant.
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->seedDoc('tenant-a', 'shared', 'docs/x.md');

        $tenants->set('tenant-b');
        $docB = $this->seedDoc('tenant-b', 'shared', 'docs/x.md');

        // Run delete under tenant-b's identity, by path. Must resolve
        // ONLY tenant-b's row.
        $tenants->set('tenant-b');
        Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'tenant-b',
                'project_key' => 'shared',
                'source_path' => 'docs/x.md',
                'force' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-b'),
        );

        // tenant-a's row must survive (the path lookup must not have
        // matched it under tenant-b's flow).
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($docA->id));
        $this->assertNull(KnowledgeDocument::withTrashed()->find($docB->id));
    }

    public function test_r30_cross_tenant_by_id_lookup_short_circuits_with_not_found(): void
    {
        // R30 — passing tenant-a's document_id to a flow run scoped to
        // tenant-b must short-circuit as `not found` (the load step's
        // forTenant() filter hides tenant-a's row from tenant-b's flow).
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->seedDoc('tenant-a', 'project-a', 'docs/secret.md');

        $tenants->set('tenant-b');
        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'tenant-b',
                'document_id' => $docA->id,
                'force' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'tenant-b'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $loadOutput = $run->stepResults['load-document']->output ?? [];
        $this->assertFalse($loadOutput['found'] ?? true);

        // tenant-a's row must be untouched (the saga short-circuited
        // before any soft/hard delete fired).
        $this->assertNotNull(KnowledgeDocument::find($docA->id));
    }

    public function test_dry_run_makes_no_mutations(): void
    {
        $doc = $this->seedDoc('default', 'acme', 'docs/x.md');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $run = Flow::dryRun(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => 'default',
                'document_id' => $doc->id,
                'force' => true,
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, KnowledgeDocument::count());
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    public function test_persisted_flow_rows_carry_tenant_id(): void
    {
        $tenants = $this->app->make(TenantContext::class);
        $tenants->set('tenant-x');

        $doc = $this->seedDoc('tenant-x', 'acme', 'docs/x.md');

        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            ['tenant_id' => 'tenant-x', 'document_id' => $doc->id, 'force' => false],
            FlowExecutionOptions::make(correlationId: 'tenant-x'),
        );

        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertSame('tenant-x', $runRow->tenant_id);
    }

    private function seedDoc(string $tenantId, string $projectKey, string $sourcePath): KnowledgeDocument
    {
        $tenants = $this->app->make(TenantContext::class);
        $tenants->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.$projectKey.$sourcePath.'doc'),
            'version_hash' => hash('sha256', $tenantId.$projectKey.$sourcePath.'ver'),
            'metadata' => null,
        ]);
    }
}
