<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Compensators;

use App\Ai\EmbeddingsResponse;
use App\Flow\Compensators\RollbackChunksCompensator;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use Tests\TestCase;

final class RollbackChunksCompensatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_force_deletes_document_and_cascades_chunks(): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->once()->andReturn(new EmbeddingsResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);

        // Stage state: insert a real KnowledgeDocument + chunks via the
        // legacy DocumentIngestor path so the compensator has something to
        // unwind.
        $ingestor = $this->app->make(DocumentIngestor::class);
        $document = $ingestor->ingestMarkdown(
            projectKey: 'demo',
            sourcePath: 'docs/intro.md',
            title: 'Intro',
            markdown: "# Heading\n\nBody.",
        );

        $this->assertSame(1, KnowledgeDocument::count());
        $this->assertGreaterThanOrEqual(1, KnowledgeChunk::count());

        $compensator = $this->app->make(RollbackChunksCompensator::class);
        $context = new FlowContext(
            flowRunId: 'rollback-run',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 'default'],
        );
        $stepResult = FlowStepResult::success(
            output: ['knowledge_document_id' => (int) $document->id],
        );

        $compensator->compensate($context, $stepResult);

        $this->assertSame(
            0,
            KnowledgeDocument::onlyTrashed()->count(),
            'force-delete should leave no soft-deleted rows behind.',
        );
        $this->assertSame(0, KnowledgeDocument::count());
        $this->assertSame(0, KnowledgeChunk::count(), 'chunks must cascade away with the parent doc.');
    }

    /** v8.36 / ADR 0030 §8 — the compensated row's own version artifact goes with it; the source stays. */
    public function test_removes_the_rows_version_artifact_but_preserves_the_source(): void
    {
        \Illuminate\Support\Facades\Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.conversion_artifacts.enabled' => true]);
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->once()->andReturn(new EmbeddingsResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);
        \Illuminate\Support\Facades\Storage::disk('kb')->put('docs/intro.md', "# Heading\n\nBody.");
        $document = $this->app->make(DocumentIngestor::class)->ingestMarkdown(
            projectKey: 'demo',
            sourcePath: 'docs/intro.md',
            title: 'Intro',
            markdown: "# Heading\n\nBody.",
        );
        $artifact = (string) $document->markdown_path;
        $this->assertNotSame('', $artifact);
        \Illuminate\Support\Facades\Storage::disk('kb')->assertExists($artifact);

        $this->app->make(RollbackChunksCompensator::class)->compensate(
            new FlowContext(flowRunId: 'rollback-run', definitionName: 'kb.ingest', input: ['tenant_id' => 'default']),
            FlowStepResult::success(output: ['knowledge_document_id' => (int) $document->id]),
        );

        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
        \Illuminate\Support\Facades\Storage::disk('kb')->assertMissing($artifact);
        \Illuminate\Support\Facades\Storage::disk('kb')->assertExists('docs/intro.md'); // the source is never the flow's to destroy
    }

    public function test_no_op_when_document_already_deleted(): void
    {
        $compensator = $this->app->make(RollbackChunksCompensator::class);
        $context = new FlowContext(
            flowRunId: 'rollback-run',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 'default'],
        );
        $stepResult = FlowStepResult::success(
            output: ['knowledge_document_id' => 999_999],
        );

        // Should not throw.
        $compensator->compensate($context, $stepResult);

        $this->assertSame(0, KnowledgeDocument::count());
    }

    public function test_no_op_when_step_result_lacks_document_id(): void
    {
        $compensator = $this->app->make(RollbackChunksCompensator::class);
        $context = new FlowContext(
            flowRunId: 'rollback-run',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 'default'],
        );
        $stepResult = FlowStepResult::success(output: []);

        $compensator->compensate($context, $stepResult);

        // Nothing to assert beyond no-throw.
        $this->assertTrue(true);
    }
}
