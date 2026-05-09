<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps;

use App\Flow\Steps\ChunkDocumentStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class ChunkDocumentStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_produces_chunk_drafts_from_prior_step_output(): void
    {
        $step = $this->app->make(ChunkDocumentStep::class);
        $context = $this->makeContext([
            'parse-markdown' => [
                'project_key' => 'demo',
                'source_path' => 'docs/x.md',
                'mime_type' => 'text/markdown',
                'markdown' => "# Heading\n\nFirst chunk text.\n\n## Sub\n\nSecond chunk text.",
                'media_items' => [],
                'extraction_meta' => [],
                'metadata' => [],
                'canonical' => null,
            ],
        ]);

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame('markdown', $result->output['source_type']);
        $this->assertNotEmpty($result->output['chunk_drafts']);
        $first = $result->output['chunk_drafts'][0];
        $this->assertArrayHasKey('text', $first);
        $this->assertArrayHasKey('order', $first);
        $this->assertArrayHasKey('heading_path', $first);
    }

    public function test_failure_path_throws_when_mime_type_has_no_source_type_mapping(): void
    {
        $step = $this->app->make(ChunkDocumentStep::class);
        $context = $this->makeContext([
            'parse-markdown' => [
                'project_key' => 'demo',
                'source_path' => 'docs/x.weird',
                'mime_type' => 'application/x-unknown-mime',
                'markdown' => 'body',
                'media_items' => [],
                'extraction_meta' => [],
                'metadata' => [],
                'canonical' => null,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing MIME→source-type mapping');
        $step->execute($context);
    }

    public function test_dry_run_does_not_mutate_database(): void
    {
        $step = $this->app->make(ChunkDocumentStep::class);
        $context = $this->makeContext([
            'parse-markdown' => [
                'project_key' => 'demo',
                'source_path' => 'docs/x.md',
                'mime_type' => 'text/markdown',
                'markdown' => "# Heading\n\nText.",
                'media_items' => [],
                'extraction_meta' => [],
                'metadata' => [],
                'canonical' => null,
            ],
        ], dryRun: true);

        $before = KnowledgeDocument::count();
        $result = $step->execute($context);
        $after = KnowledgeDocument::count();

        $this->assertTrue($result->success);
        $this->assertSame($before, $after);
    }

    /**
     * @param array<string, array<string, mixed>> $stepOutputs
     */
    private function makeContext(array $stepOutputs, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'test-run',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/x.md',
                'disk' => 'kb',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
            stepOutputs: $stepOutputs,
            dryRun: $dryRun,
        );
    }
}
