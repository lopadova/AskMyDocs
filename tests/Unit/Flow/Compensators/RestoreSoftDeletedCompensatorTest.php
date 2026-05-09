<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Compensators;

use App\Flow\Compensators\RestoreSoftDeletedCompensator;
use App\Models\KnowledgeDocument;
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

    private function context(): FlowContext
    {
        return new FlowContext(
            flowRunId: 'rs-test',
            definitionName: 'kb.delete',
            input: ['tenant_id' => 'default'],
            stepOutputs: [],
            dryRun: false,
        );
    }

    private function makeDoc(): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => 'docs/x.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
        ]);
    }
}
