<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps;

use App\Ai\EmbeddingsResponse;
use App\Flow\Steps\EmbedChunksStep;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class EmbedChunksStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_happy_path_returns_embeddings_for_chunk_drafts(): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->once()->andReturn(new EmbeddingsResponse(
            embeddings: [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            provider: 'openai',
            model: 'text-embedding-3-small',
            totalTokens: 2,
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $step = $this->app->make(EmbedChunksStep::class);
        $context = $this->makeContext([
            'chunk-document' => [
                'source_type' => 'markdown',
                'chunk_drafts' => [
                    ['text' => 'first', 'order' => 0, 'heading_path' => '', 'metadata' => []],
                    ['text' => 'second', 'order' => 1, 'heading_path' => '', 'metadata' => []],
                ],
            ],
        ]);

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->output['embeddings']);
        $this->assertSame('openai', $result->output['provider']);
        $this->assertSame('text-embedding-3-small', $result->output['model']);
    }

    public function test_failure_path_propagates_provider_exception(): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->once()->andThrow(new RuntimeException('Provider down'));
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $step = $this->app->make(EmbedChunksStep::class);
        $context = $this->makeContext([
            'chunk-document' => [
                'source_type' => 'markdown',
                'chunk_drafts' => [
                    ['text' => 'first', 'order' => 0, 'heading_path' => '', 'metadata' => []],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider down');
        $step->execute($context);
    }

    public function test_dry_run_skips_step_without_calling_provider(): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        // R26 — proves no external call happens under dry-run.
        $cache->shouldNotReceive('generate');
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $step = $this->app->make(EmbedChunksStep::class);
        $context = $this->makeContext([
            'chunk-document' => [
                'source_type' => 'markdown',
                'chunk_drafts' => [
                    ['text' => 'first', 'order' => 0, 'heading_path' => '', 'metadata' => []],
                ],
            ],
        ], dryRun: true);

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertTrue($result->dryRunSkipped);
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
