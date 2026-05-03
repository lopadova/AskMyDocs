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

    public function test_same_text_can_coexist_under_different_provider_or_model(): void
    {
        // v4.0.1 — the schema's composite UNIQUE on
        // (text_hash, provider, model) lets identical text be cached
        // under two distinct (provider, model) tuples simultaneously.
        // The original v4.0 schema (UNIQUE on text_hash alone) raised
        // a duplicate-key error on the second insert; the migration
        // 2026_05_03_000001 relaxes that constraint so multi-model
        // deployments share a database without bespoke shadowing.
        $sameText = hash('sha256', 'shared input string');

        EmbeddingCache::create([
            'text_hash' => $sameText,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'embedding' => [1.0, 2.0],
            'last_used_at' => now(),
        ]);

        // Same text, different model under the same provider.
        EmbeddingCache::create([
            'text_hash' => $sameText,
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'embedding' => [3.0, 4.0],
            'last_used_at' => now(),
        ]);

        // Same text, different provider entirely.
        EmbeddingCache::create([
            'text_hash' => $sameText,
            'provider' => 'gemini',
            'model' => 'text-embedding-004',
            'embedding' => [5.0, 6.0],
            'last_used_at' => now(),
        ]);

        $this->assertSame(3, EmbeddingCache::where('text_hash', $sameText)->count());
    }

    public function test_regolo_provider_round_trips_through_cache(): void
    {
        // v4.0.1 — exercises the public service path against the
        // `regolo` provider. Pre-fix `resolveModelName('regolo')`
        // returned the `'unknown'` literal, so cache lookups hit
        // `model='unknown'` while inserts stored the real model
        // name; the lookup never matched its own writes, polluting
        // the cache with unreachable rows. The fix wires the
        // provider's nested config key
        // (`ai.providers.regolo.models.embeddings.default`) into the
        // resolver so reads and writes share a key.
        //
        // The mock model name MUST match what the resolver returns
        // for the test environment. `phpunit.xml` sets
        // `REGOLO_EMBEDDINGS_MODEL=gte-Qwen2`, so both the resolver
        // and the API mock return that value here — a mismatch
        // would mean the test passes by accident on the duplicate-
        // insert path rather than proving the cache hit.
        config()->set('kb.embedding_cache.enabled', true);
        $configuredModel = config('ai.providers.regolo.models.embeddings.default');
        $this->assertSame('gte-Qwen2', $configuredModel, 'Test config drift — expected gte-Qwen2 from phpunit.xml');

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('regolo');
        $provider->shouldReceive('supportsEmbeddings')->andReturn(true);

        $manager = Mockery::mock(AiManager::class);
        $manager->shouldReceive('embeddingsProvider')->andReturn($provider);

        $apiResponse = new EmbeddingsResponse(
            // Float-distinct values so JSON round-trip preserves
            // the float type (Eloquent's array cast JSON-decodes
            // `7.0` back as integer `7`, would mask a real bug).
            embeddings: [[7.5, 8.5]],
            provider: 'regolo',
            model: $configuredModel,
        );
        // `once()` is the load-bearing assertion: a regression that
        // resurrects `'unknown'` for regolo would make the second
        // generate() miss the cache and re-invoke the API, tripping
        // the `once()` constraint at tearDown.
        $manager->shouldReceive('generateEmbeddings')->once()->andReturn($apiResponse);

        $service = new EmbeddingCacheService($manager);

        // First call inserts.
        $first = $service->generate(['regolo embedding test']);
        $this->assertSame([[7.5, 8.5]], $first->embeddings);
        $this->assertSame(1, EmbeddingCache::count());

        // Second call must HIT the cache via the composite
        // (text_hash, provider, model) key.
        $second = $service->generate(['regolo embedding test']);
        $this->assertSame([[7.5, 8.5]], $second->embeddings);

        // No duplicate row inserted — the lookup found the prior
        // entry by (text_hash, provider='regolo', model=$configuredModel).
        $this->assertSame(1, EmbeddingCache::count());
        $row = EmbeddingCache::first();
        $this->assertSame('regolo', $row->provider);
        $this->assertSame($configuredModel, $row->model);
    }

    public function test_pii_pre_redact_off_by_default_does_not_alter_text(): void
    {
        // v4.1/W4.1.C — fail-safe contract: when the integration knobs
        // are at their defaults (false / false) the service must hash
        // and embed the ORIGINAL text. A regression that always
        // redacts would silently flip cache hit-rate for v3 hosts
        // upgrading to v4.1.
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_before_embeddings', false);
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        $service = $this->makeService(
            apiResponse: new EmbeddingsResponse(
                embeddings: [[1.0, 2.0]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );

        $service->generate(['Email mario@example.com please']);

        // Cache row must be keyed on the ORIGINAL bytes — not the
        // masked variant — proving redaction was a no-op.
        $row = EmbeddingCache::first();
        $this->assertNotNull($row);
        $this->assertSame(
            hash('sha256', 'Email mario@example.com please'),
            $row->text_hash,
            'PII pre-redact must be a no-op when both knobs are false.',
        );
    }

    public function test_pii_pre_redact_off_when_master_switch_off_even_if_embeddings_knob_on(): void
    {
        // Master switch beats the per-touch-point knob. Same
        // observable behaviour as the all-off case: the cache key is
        // the SHA-256 of the original input.
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_before_embeddings', true);
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        $service = $this->makeService(
            apiResponse: new EmbeddingsResponse(
                embeddings: [[1.0, 2.0]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );

        $service->generate(['contact: mario@example.com']);

        $row = EmbeddingCache::first();
        $this->assertNotNull($row);
        $this->assertSame(
            hash('sha256', 'contact: mario@example.com'),
            $row->text_hash,
            'Master switch off must short-circuit the embeddings knob.',
        );
    }

    public function test_pii_pre_redact_masks_text_before_hashing_and_provider_call(): void
    {
        // v4.1/W4.1.C — when both knobs are on, the PII must be
        // masked OUT of the text before (a) the cache hash is
        // computed and (b) the text is sent to the provider. We
        // assert (a) by inspecting the persisted `text_hash` and
        // (b) by reading the args the AiManager mock received.
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_embeddings', true);
        config()->set('kb.embedding_cache.enabled', true);
        config()->set('ai.providers.openai.embeddings_model', 'text-embedding-3-small');

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('supportsEmbeddings')->andReturn(true);

        $manager = Mockery::mock(AiManager::class);
        $manager->shouldReceive('embeddingsProvider')->andReturn($provider);

        $captured = [];
        $manager->shouldReceive('generateEmbeddings')
            ->once()
            ->andReturnUsing(function (array $texts) use (&$captured) {
                $captured = $texts;

                return new EmbeddingsResponse(
                    embeddings: array_map(static fn () => [0.1, 0.2], $texts),
                    provider: 'openai',
                    model: 'text-embedding-3-small',
                );
            });

        $service = new EmbeddingCacheService($manager);
        $service->generate(['Email mario@example.com please']);

        // (a) The text reaching the provider must NOT contain the
        // raw email — MaskStrategy replaces the matched span with a
        // sentinel like `[REDACTED:email]`.
        $this->assertCount(1, $captured);
        $this->assertStringNotContainsString(
            'mario@example.com',
            $captured[0],
            'Provider must NOT see the original PII when redact_before_embeddings is on.',
        );

        // (b) The cache row's text_hash matches the SHA-256 of the
        // MASKED string (NOT the original) — proving redaction
        // happened before the hash, so PII never lands in the cache
        // key column.
        $row = EmbeddingCache::first();
        $this->assertNotNull($row);
        $this->assertSame(
            hash('sha256', $captured[0]),
            $row->text_hash,
            'Cache key must be the SHA-256 of the masked text, not the original.',
        );
        $this->assertNotSame(
            hash('sha256', 'Email mario@example.com please'),
            $row->text_hash,
            'Cache key must NOT be the SHA-256 of the original text.',
        );
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
