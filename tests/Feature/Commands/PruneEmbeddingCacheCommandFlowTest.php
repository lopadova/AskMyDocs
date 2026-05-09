<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\EmbeddingCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the v4.2 Flow refactor of `kb:prune-embedding-cache`,
 * including the conditional approval gate.
 */
final class PruneEmbeddingCacheCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_under_threshold_evicts_immediately(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 5000);
        $this->seedRow('h-old', 60);

        $this->artisan('kb:prune-embedding-cache', ['--days' => 30])
            ->expectsOutputToContain('Pruned 1 embedding_cache rows')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddingCache::count());
    }

    public function test_over_threshold_pauses_for_approval_and_exits_failure(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 1);
        $this->seedRow('h-old-1', 60);
        $this->seedRow('h-old-2', 60);
        $this->seedRow('h-old-3', 60);

        $this->artisan('kb:prune-embedding-cache', ['--days' => 30])
            ->expectsOutputToContain('PAUSED for approval')
            ->assertFailed();

        // Eviction did NOT happen — the gate paused before it ran.
        $this->assertSame(3, EmbeddingCache::count());
    }

    public function test_zero_retention_is_a_noop(): void
    {
        $this->seedRow('h-old', 60);
        $this->artisan('kb:prune-embedding-cache', ['--days' => 0])
            ->expectsOutputToContain('skipping prune')
            ->assertSuccessful();
        $this->assertSame(1, EmbeddingCache::count());
    }

    public function test_dry_run_records_plan_without_evicting(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 5000);
        $this->seedRow('h-old', 60);

        $this->artisan('kb:prune-embedding-cache', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Would evict 1')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddingCache::count());
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
