<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Compensators;

use App\Flow\Compensators\RestoreSoftDeletedCompensator;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use Tests\TestCase;

final class RestoreSoftDeletedCompensatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_doc_when_newly_trashed_by_run(): void
    {
        $doc = $this->makeDoc();
        $doc->delete();

        $compensator = $this->app->make(RestoreSoftDeletedCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success([
                'document_id' => $doc->id,
                'newly_trashed' => true,
            ]),
        );

        $fresh = KnowledgeDocument::find($doc->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_does_not_restore_when_pre_existing_trashed(): void
    {
        $doc = $this->makeDoc();
        $doc->delete();

        $compensator = $this->app->make(RestoreSoftDeletedCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success([
                'document_id' => $doc->id,
                'newly_trashed' => false,  // pre-existing trashed state
            ]),
        );

        $fresh = KnowledgeDocument::onlyTrashed()->find($doc->id);
        $this->assertNotNull($fresh, 'must preserve operator-prior soft-delete intent');
    }

    public function test_idempotent_on_missing_doc(): void
    {
        $compensator = $this->app->make(RestoreSoftDeletedCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success(['document_id' => 99_999, 'newly_trashed' => true]),
        );

        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
    }

    public function test_r30_does_not_restore_other_tenants_doc_on_id_collision(): void
    {
        // Iteration 3 — Copilot flagged that the bare onlyTrashed()->find()
        // would resurrect another tenant's soft-deleted row when the
        // numeric id happens to match. The compensator now applies
        // forTenant() from $context->input['tenant_id'] so cross-tenant
        // restore is structurally impossible.
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->makeDocFor('tenant-a');
        $docA->delete();

        // Run the compensator under tenant-b's context, but pass tenant-a's
        // numeric document_id. forTenant() must hide it.
        $tenants->set('tenant-b');
        $compensator = $this->app->make(RestoreSoftDeletedCompensator::class);
        $compensator->compensate(
            $this->contextFor('tenant-b'),
            FlowStepResult::success([
                'document_id' => $docA->id,
                'newly_trashed' => true,
            ]),
        );

        // Tenant-a's row must STILL be trashed — the compensator could
        // not see it under tenant-b's scope.
        $this->assertNotNull(KnowledgeDocument::onlyTrashed()->find($docA->id));
        $this->assertNull(KnowledgeDocument::find($docA->id));

        $tenants->reset();
    }

    private function context(): FlowContext
    {
        return $this->contextFor('default');
    }

    private function contextFor(string $tenantId): FlowContext
    {
        return new FlowContext(
            flowRunId: 'rs-test',
            definitionName: 'kb.delete',
            input: ['tenant_id' => $tenantId],
            stepOutputs: [],
            dryRun: false,
        );
    }

    private function makeDoc(): KnowledgeDocument
    {
        return $this->makeDocFor('default');
    }

    private function makeDocFor(string $tenantId): KnowledgeDocument
    {
        $tenants = $this->app->make(TenantContext::class);
        $tenants->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => 'docs/x.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.'doc'),
            'version_hash' => hash('sha256', $tenantId.'ver'),
            'metadata' => null,
        ]);
    }
}
