<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Delete;

use App\Flow\Steps\Delete\SoftDeleteDocumentStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class SoftDeleteDocumentStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deletes_document(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(SoftDeleteDocumentStep::class);

        $result = $step->execute($this->context($doc));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['newly_trashed']);
        $this->assertSame(1, KnowledgeDocument::onlyTrashed()->count());
        $this->assertSame(0, KnowledgeDocument::count());
    }

    public function test_idempotent_on_already_trashed(): void
    {
        $doc = $this->makeDoc();
        $doc->delete();

        $step = $this->app->make(SoftDeleteDocumentStep::class);
        $result = $step->execute($this->context($doc));

        $this->assertTrue($result->success);
        $this->assertFalse($result->output['newly_trashed']);
        $this->assertTrue($result->output['already_trashed']);
        $this->assertSame(1, KnowledgeDocument::onlyTrashed()->count());
    }

    public function test_dry_run_does_nothing(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(SoftDeleteDocumentStep::class);

        $result = $step->execute($this->context($doc, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_propagates_load_short_circuit(): void
    {
        $step = $this->app->make(SoftDeleteDocumentStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.delete',
            input: ['tenant_id' => 'default'],
            stepOutputs: ['load-document' => ['found' => false]],
            dryRun: false,
        );

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['skipped']);
    }

    private function context(KnowledgeDocument $doc, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'soft-test',
            definitionName: 'kb.delete',
            input: ['tenant_id' => 'default'],
            stepOutputs: [
                'load-document' => [
                    'found' => true,
                    'document_id' => (int) $doc->id,
                    'project_key' => (string) $doc->project_key,
                    'source_path' => (string) $doc->source_path,
                    'already_trashed' => (bool) $doc->trashed(),
                ],
            ],
            dryRun: $dryRun,
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
