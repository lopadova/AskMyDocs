<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Delete;

use App\Flow\Steps\Delete\HardDeleteRowsStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class HardDeleteRowsStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_deletes_db_rows_when_force_true(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(HardDeleteRowsStep::class);

        $result = $step->execute($this->context($doc, force: true));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['hard_deleted']);
        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
    }

    public function test_no_op_when_force_false(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(HardDeleteRowsStep::class);

        $result = $step->execute($this->context($doc, force: false));

        $this->assertTrue($result->output['skipped']);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_dry_run_skipped(): void
    {
        $doc = $this->makeDoc();
        $step = $this->app->make(HardDeleteRowsStep::class);

        $result = $step->execute($this->context($doc, force: true, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    private function context(KnowledgeDocument $doc, bool $force, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'hard-test',
            definitionName: 'kb.delete',
            input: ['tenant_id' => 'default', 'force' => $force],
            stepOutputs: [
                'load-document' => [
                    'found' => true,
                    'document_id' => (int) $doc->id,
                    'project_key' => (string) $doc->project_key,
                    'source_path' => (string) $doc->source_path,
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
