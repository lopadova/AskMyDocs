<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\CountStaleEmbeddingsStep;
use App\Models\EmbeddingCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class CountStaleEmbeddingsStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_counts_only_rows_older_than_cutoff(): void
    {
        $this->seedRow('h-old', 60);
        $this->seedRow('h-fresh', 5);

        $step = $this->app->make(CountStaleEmbeddingsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('default', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['planned_count']);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(CountStaleEmbeddingsStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: ['cutoff_iso' => CarbonImmutable::now()->toIso8601String()],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_count_is_global_across_tenants_by_design(): void
    {
        // embedding_cache has NO tenant_id column — the count is GLOBAL.
        // This test pins the design intent so a future "tenant-scope it"
        // refactor surfaces as a deliberate, conscious change.
        $this->seedRow('a', 60);
        $this->seedRow('b', 60);

        $step = $this->app->make(CountStaleEmbeddingsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);

        $resultA = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));
        $resultB = $step->execute($this->context('tenant-b', $cutoff->toIso8601String()));

        // Same global count regardless of bound tenant.
        $this->assertSame(2, $resultA->output['planned_count']);
        $this->assertSame(2, $resultB->output['planned_count']);
    }

    private function context(string $tenantId, string $cutoffIso): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso],
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
