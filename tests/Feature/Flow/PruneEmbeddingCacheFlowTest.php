<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\PruneEmbeddingCacheFlow;
use App\Models\EmbeddingCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class PruneEmbeddingCacheFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_under_threshold_completes_without_approval_gate(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 5000);

        $this->seedRow('h-old', 60);
        $this->seedRow('h-fresh', 5);

        $run = Flow::execute(
            PruneEmbeddingCacheFlow::NAME,
            [
                'tenant_id' => 'default',
                'cutoff_iso' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $evict = $run->stepResults['evict-embedding-cache'];
        $this->assertSame(1, $evict->output['deleted_count']);
        $this->assertSame(1, EmbeddingCache::count());
    }

    public function test_over_threshold_pauses_for_approval(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 1);

        $this->seedRow('h-old-1', 60);
        $this->seedRow('h-old-2', 60);
        $this->seedRow('h-old-3', 60);

        $run = Flow::execute(
            PruneEmbeddingCacheFlow::NAME,
            [
                'tenant_id' => 'default',
                'cutoff_iso' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_PAUSED, $run->status);
        // Eviction did NOT happen — gate paused before it ran.
        $this->assertSame(3, EmbeddingCache::count());
        $assess = $run->stepResults[PruneEmbeddingCacheFlow::ASSESS_STEP];
        $this->assertTrue($assess->output['approval_required']);
        $this->assertSame(3, $assess->output['planned_count']);
    }

    public function test_dry_run_auto_resolves_even_over_threshold(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 1);
        $this->seedRow('h-old-1', 60);
        $this->seedRow('h-old-2', 60);

        $run = Flow::dryRun(
            PruneEmbeddingCacheFlow::NAME,
            [
                'tenant_id' => 'default',
                'cutoff_iso' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
            ],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(2, EmbeddingCache::count(), 'dry-run never deletes');
    }

    private function seedRow(string $textHash, int $lastUsedDaysAgo): void
    {
        EmbeddingCache::create([
            'text_hash' => str_pad($textHash, 64, 'x'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [0.1, 0.2, 0.3],
            'created_at' => CarbonImmutable::now()->subDays($lastUsedDaysAgo + 1),
            'last_used_at' => CarbonImmutable::now()->subDays($lastUsedDaysAgo),
        ]);
    }
}
