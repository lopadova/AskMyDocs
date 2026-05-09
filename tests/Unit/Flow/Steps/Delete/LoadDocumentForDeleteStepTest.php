<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Delete;

use App\Flow\Steps\Delete\LoadDocumentForDeleteStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class LoadDocumentForDeleteStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_by_document_id(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(LoadDocumentForDeleteStep::class);

        $result = $step->execute($this->context(['document_id' => $doc->id]));

        $this->assertTrue($result->output['found']);
        $this->assertSame((int) $doc->id, $result->output['document_id']);
    }

    public function test_resolves_by_project_key_and_source_path(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(LoadDocumentForDeleteStep::class);

        $result = $step->execute($this->context([
            'project_key' => $doc->project_key,
            'source_path' => $doc->source_path,
        ]));

        $this->assertTrue($result->output['found']);
        $this->assertSame((int) $doc->id, $result->output['document_id']);
    }

    public function test_returns_not_found_when_no_match(): void
    {
        $step = $this->app->make(LoadDocumentForDeleteStep::class);
        $result = $step->execute($this->context([
            'project_key' => 'acme',
            'source_path' => 'docs/missing.md',
        ]));

        $this->assertFalse($result->output['found']);
    }

    public function test_finds_already_soft_deleted_doc(): void
    {
        $doc = $this->makeDoc();
        $doc->delete();

        $step = $this->app->make(LoadDocumentForDeleteStep::class);
        $result = $step->execute($this->context(['document_id' => $doc->id]));

        $this->assertTrue($result->output['found'], 'withTrashed must reach soft-deleted rows');
        $this->assertTrue($result->output['already_trashed']);
    }

    public function test_throws_when_no_lookup_keys_provided(): void
    {
        $step = $this->app->make(LoadDocumentForDeleteStep::class);

        $this->expectException(RuntimeException::class);
        $step->execute($this->context([]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function context(array $input): FlowContext
    {
        return new FlowContext(
            flowRunId: 'delete-load-test',
            definitionName: 'kb.delete',
            input: array_merge(['tenant_id' => 'default'], $input),
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
