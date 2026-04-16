<?php

namespace Tests\Feature\Kb;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\EmbeddingsResponse;
use App\Models\EmbeddingCache;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmbeddingCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(?EmbeddingsResponse $apiResponse = null, bool $expectCall = true): EmbeddingCacheService
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('supportsEmbeddings')->andReturn(true);

        $manager = Mockery::mock(AiManager::class);
        $manager->shouldReceive('embeddingsProvider')->andReturn($provider);

        if ($expectCall && $apiResponse !== null) {
            $manager->shouldReceive('generateEmbeddings')->andReturn($apiResponse);
        } elseif (! $expectCall) {
            $manager->shouldNotReceive('generateEmbeddings');
        }

        return new EmbeddingCacheService($manager);
    }

    public function test_bypasses_cache_when_disabled(): void
    {
        config()->set('kb.embedding_cache.enabled', false);

        $service = $this->makeService(
            apiResponse: new EmbeddingsResponse(
                embeddings: [[1.0, 2.0]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );

        $result = $service->generate(['hello']);

        $this->assertSame([[1.0, 2.0]], $result->embeddings);
        $this->assertSame(0, EmbeddingCache::count());
    }

    public function test_caches_embeddings_on_first_call(): void
    {
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        $service = $this->makeService(
            apiResponse: new EmbeddingsResponse(
                embeddings: [[0.1, 0.2], [0.3, 0.4]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );

        $result = $service->generate(['foo', 'bar']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $result->embeddings);
        $this->assertSame(2, EmbeddingCache::count());
        $this->assertSame(
            hash('sha256', 'foo'),
            EmbeddingCache::orderBy('id')->first()->text_hash,
        );
    }

    public function test_uses_cache_on_second_call_without_api(): void
    {
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        // Seed cache
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'hello'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [0.9, 0.8, 0.7],
            'last_used_at' => now()->subDay(),
        ]);

        $service = $this->makeService(expectCall: false);

        $result = $service->generate(['hello']);

        $this->assertSame([[0.9, 0.8, 0.7]], $result->embeddings);
    }

    public function test_mixed_hit_and_miss_preserves_order(): void
    {
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        // Seed cache for "second"
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'second'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [9.5, 9.5],
            'last_used_at' => now(),
        ]);

        // API returns for "first" and "third" (the cache misses)
        $service = $this->makeService(
            apiResponse: new EmbeddingsResponse(
                embeddings: [[1.0, 1.0], [3.0, 3.0]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );

        $result = $service->generate(['first', 'second', 'third']);

        $this->assertSame([
            [1.0, 1.0], // miss → API index 0
            [9.5, 9.5], // hit (cached)
            [3.0, 3.0], // miss → API index 1
        ], $result->embeddings);

        // 3 total entries now in cache
        $this->assertSame(3, EmbeddingCache::count());
    }

    public function test_flush_removes_all_entries(): void
    {
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'a'),
            'provider' => 'openai',
            'model' => 'm',
            'embedding' => [1.0],
            'last_used_at' => now(),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'b'),
            'provider' => 'gemini',
            'model' => 'm',
            'embedding' => [2.0],
            'last_used_at' => now(),
        ]);

        $service = $this->makeService(expectCall: false);

        $this->assertSame(2, $service->flush());
        $this->assertSame(0, EmbeddingCache::count());
    }

    public function test_flush_by_provider_only(): void
    {
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'a'),
            'provider' => 'openai',
            'model' => 'm',
            'embedding' => [1.0],
            'last_used_at' => now(),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'b'),
            'provider' => 'gemini',
            'model' => 'm',
            'embedding' => [2.0],
            'last_used_at' => now(),
        ]);

        $service = $this->makeService(expectCall: false);

        $this->assertSame(1, $service->flush('openai'));
        $this->assertSame(1, EmbeddingCache::count());
        $this->assertSame('gemini', EmbeddingCache::first()->provider);
    }

    public function test_prune_removes_entries_older_than_cutoff(): void
    {
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'old'),
            'provider' => 'openai',
            'model' => 'm',
            'embedding' => [1.0],
            'last_used_at' => now()->subDays(40),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'new'),
            'provider' => 'openai',
            'model' => 'm',
            'embedding' => [2.0],
            'last_used_at' => now()->subDay(),
        ]);

        $service = $this->makeService(expectCall: false);

        $this->assertSame(1, $service->prune(now()->subDays(30)));
        $this->assertSame(1, EmbeddingCache::count());
        $this->assertSame(hash('sha256', 'new'), EmbeddingCache::first()->text_hash);
    }

    public function test_stats_reports_counts_and_groups(): void
    {
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'a'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [1.0],
            'last_used_at' => now(),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'b'),
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [2.0],
            'last_used_at' => now(),
        ]);
        EmbeddingCache::create([
            'text_hash' => hash('sha256', 'c'),
            'provider' => 'gemini',
            'model' => 'text-embedding-004',
            'embedding' => [3.0],
            'last_used_at' => now(),
        ]);

        $service = $this->makeService(expectCall: false);
        $stats = $service->stats();

        $this->assertSame(3, $stats['total_entries']);
        $this->assertCount(2, $stats['providers']);
    }
}
