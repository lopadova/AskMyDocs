<?php

namespace Tests\Feature\Commands;

use App\Models\EmbeddingCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneEmbeddingCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedRow(string $hash, \DateTimeInterface $lastUsedAt): void
    {
        EmbeddingCache::create([
            'text_hash' => $hash,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [0.1, 0.2],
            'last_used_at' => $lastUsedAt,
        ]);
    }

    public function test_uses_config_retention_when_days_option_missing(): void
    {
        config()->set('kb.embedding_cache.retention_days', 10);

        $this->seedRow(hash('sha256', 'old-1'), now()->subDays(15));
        $this->seedRow(hash('sha256', 'old-2'), now()->subDays(12));
        $this->seedRow(hash('sha256', 'recent'), now()->subDays(3));

        $this->artisan('kb:prune-embedding-cache')
            ->expectsOutputToContain('Pruned 2 embedding_cache rows older than 10 days')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddingCache::count());
        $this->assertSame(hash('sha256', 'recent'), EmbeddingCache::first()->text_hash);
    }

    public function test_days_cli_option_overrides_config(): void
    {
        config()->set('kb.embedding_cache.retention_days', 90);

        $this->seedRow(hash('sha256', 'old'), now()->subDays(45));
        $this->seedRow(hash('sha256', 'fresh'), now()->subDay());

        $this->artisan('kb:prune-embedding-cache', ['--days' => 30])
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddingCache::count());
    }

    public function test_retention_zero_is_a_noop(): void
    {
        $this->seedRow(hash('sha256', 'ancient'), now()->subYears(2));

        $this->artisan('kb:prune-embedding-cache', ['--days' => 0])
            ->expectsOutputToContain('skipping prune')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddingCache::count());
    }
}
