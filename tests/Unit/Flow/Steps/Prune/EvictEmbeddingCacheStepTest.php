<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\EvictEmbeddingCacheStep;
use App\Models\EmbeddingCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class EvictEmbeddingCacheStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_deletes_only_rows_older_than_cutoff(): void
    {
        $this->seedRow('h-old', 60);
        $this->seedRow('h-fresh', 5);

        $step = $this->app->make(EvictEmbeddingCacheStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('default', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['deleted_count']);
        $this->assertSame(1, EmbeddingCache::count());
    }

    public function test_dry_run_skipped(): void
    {
        $this->seedRow('h-old', 60);
        $step = $this->app->make(EvictEmbeddingCacheStep::class);

        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('default', $cutoff->toIso8601String(), dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(1, EmbeddingCache::count());
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(EvictEmbeddingCacheStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: ['cutoff_iso' => CarbonImmutable::now()->toIso8601String()],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    private function context(string $tenantId, string $cutoffIso, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso],
            dryRun: $dryRun,
        );
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
