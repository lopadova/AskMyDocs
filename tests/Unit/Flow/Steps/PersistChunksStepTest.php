<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps;

use App\Ai\EmbeddingsResponse;
use App\Flow\Steps\PersistChunksStep;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class PersistChunksStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_happy_path_creates_knowledge_document_and_chunks(): void
    {
        $step = $this->app->make(PersistChunksStep::class);
        $context = $this->makeFullContext();

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame(1, KnowledgeDocument::count());
        $this->assertGreaterThanOrEqual(1, KnowledgeChunk::count());

        $document = KnowledgeDocument::first();
        $this->assertSame('demo', $document->project_key);
        $this->assertSame('docs/intro.md', $document->source_path);
        $this->assertSame($document->id, $result->output['knowledge_document_id']);
    }

    public function test_failure_path_rolls_back_transaction_on_persist_error(): void
    {
        // Inject a DocumentIngestor that throws during persistence so we
        // can prove the transaction rolls back: zero docs after the call.
        $ingestor = Mockery::mock(DocumentIngestor::class);
        $ingestor->shouldReceive('persistDrafts')
            ->once()
            ->andThrow(new RuntimeException('forced persist failure'));
        $this->app->instance(DocumentIngestor::class, $ingestor);

        $step = $this->app->make(PersistChunksStep::class);
        $context = $this->makeFullContext();

        try {
            $step->execute($context);
            $this->fail('Expected RuntimeException did not fire.');
        } catch (RuntimeException $e) {
            $this->assertSame('forced persist failure', $e->getMessage());
        }

        $this->assertSame(0, KnowledgeDocument::count());
        $this->assertSame(0, KnowledgeChunk::count());
    }

    public function test_dry_run_skips_persistence(): void
    {
        $step = $this->app->make(PersistChunksStep::class);
        $context = $this->makeFullContext(dryRun: true);

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, KnowledgeDocument::count());
    }

    private function makeFullContext(bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'test-run',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/intro.md',
                'disk' => 'kb',
                'title' => 'Intro',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
            stepOutputs: [
                'parse-markdown' => [
                    'project_key' => 'demo',
                    'source_path' => 'docs/intro.md',
                    'mime_type' => 'text/markdown',
                    'markdown' => "# Hello\n\nBody paragraph.",
                    'media_items' => [],
                    'extraction_meta' => [],
                    'metadata' => ['disk' => 'kb', 'prefix' => ''],
                    'canonical' => null,
                ],
                'chunk-document' => [
                    'source_type' => 'markdown',
                    'chunk_drafts' => [
                        [
                            'text' => 'Body paragraph.',
                            'order' => 0,
                            'heading_path' => 'Hello',
                            'metadata' => [],
                        ],
                    ],
                ],
                'embed-chunks' => [
                    'embeddings' => [[0.1, 0.2, 0.3]],
                    'provider' => 'openai',
                    'model' => 'text-embedding-3-small',
                    'total_tokens' => 1,
                ],
            ],
            dryRun: $dryRun,
        );
    }
}
