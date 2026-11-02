<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use App\Ai\AiResponse;
use App\FinOps\AiCallMeter;
use App\Support\TenantContext;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Pricing\Cost\HttpUsageCaptureMiddleware;
use Padosoft\LaravelAiFinOps\Pricing\Cost\RawResponseCapture;
use Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\PriceWatch\PriceWatchService;
use Tests\TestCase;

final class OpenRouterPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-finops.pricing.openrouter.enabled' => true,
            'ai-finops.pricing.openrouter.url' => 'https://catalog.example.test/models',
            'ai-finops.pricing.openrouter.embeddings_url' => 'https://catalog.example.test/embeddings/models']);
        Http::preventStrayRequests();
    }

    public function test_host_defaults_enable_the_authoritative_feed_and_actual_cost_capture(): void
    {
        $host = require dirname(__DIR__, 3).'/config/ai-finops.php';
        $this->assertTrue($host['pricing']['openrouter']['enabled']);
        $this->assertTrue($host['pricing']['actual_cost']['enabled']);
        $this->assertFalse($host['pricing']['actual_cost']['store_raw']);
        $this->assertSame('openrouter', $host['pricing']['provider_source_map']['openrouter']);
        $this->assertSame(['manual', 'litellm', 'openrouter'], $host['pricing']['sources']);
    }

    public function test_chat_and_embedding_feeds_price_future_usage_with_provenance(): void
    {
        Http::fake(['https://catalog.example.test/models' => Http::response(['data' => [
            ['id' => 'vendor/arbitrary-chat', 'pricing' => ['prompt' => '.002', 'completion' => '.004']],
            ['id' => 'automatic-router', 'pricing' => ['prompt' => '-1', 'completion' => '-1']],
            ['id' => 'no-price', 'pricing' => []],
        ]]), 'https://catalog.example.test/embeddings/models' => Http::response(['data' => [
            ['id' => 'vendor/arbitrary-embedding', 'pricing' => ['prompt' => '.001']],
        ]])]);
        $source = app(OpenRouterPricingSource::class);
        $this->assertInstanceOf(\App\FinOps\OpenRouterPricingSource::class, $source);
        $this->assertSame(2, $source->sync());
        $this->assertArrayNotHasKey('no-price', $source->all());
        config(['ai-finops.metering' => true]);
        app(TenantContext::class)->set('priced-tenant');
        app(AiCallMeter::class)->meterChat(new AiResponse('test', 'openrouter', 'vendor/arbitrary-chat', 100, 50, 150));
        $row = UsageRecord::where('tenant_id', 'priced-tenant')->firstOrFail();
        $this->assertEqualsWithDelta(.4, (float) $row->cost_total, .0000001);
        $this->assertSame('openrouter', $row->metadata['price_source']);
        $this->assertNotEmpty($row->metadata['source_synced_at']);
        $this->assertEqualsWithDelta(.001, app(PricingRegistry::class)->priceFor('vendor/arbitrary-embedding', 'openrouter')->inputPerToken, .0000001);
        Http::assertSentCount(2);
    }

    public function test_a_failed_refresh_keeps_the_last_complete_catalog_and_its_timestamp(): void
    {
        Cache::put('ai-finops:pricing:openrouter', ['old' => ['input_cost_per_token' => .01]]);
        Cache::put('ai-finops:pricing:openrouter:synced_at', '2026-10-30T00:00:00Z');
        Http::fake(['https://catalog.example.test/models' => Http::response(['data' => [['id' => 'new', 'pricing' => ['prompt' => '.1', 'completion' => '.2']]]]),
            'https://catalog.example.test/embeddings/models' => Http::response([], 503)]);
        $this->assertSame(0, app(OpenRouterPricingSource::class)->sync());
        $this->assertSame(['old'], array_keys(Cache::get('ai-finops:pricing:openrouter')));
        $this->assertSame('2026-10-30T00:00:00Z', Cache::get('ai-finops:pricing:openrouter:synced_at'));
        config(['ai-finops.pricing.openrouter.enabled' => false]);
        $this->assertSame([], app(OpenRouterPricingSource::class)->all());
    }

    public function test_provider_billed_cost_takes_precedence_over_a_tariff_and_is_consumed_once(): void
    {
        config(['ai-finops.metering' => true]);
        Cache::put('ai-finops:pricing:openrouter', ['vendor/arbitrary-chat' => ['input_cost_per_token' => .002, 'output_cost_per_token' => .004]]);
        $capture = app(RawResponseCapture::class);
        $middleware = HttpUsageCaptureMiddleware::make($capture);
        $body = json_encode(['id' => 'synthetic-generation', 'usage' => ['cost' => .73, 'prompt_tokens' => 100, 'completion_tokens' => 50]]);
        $response = $middleware(new Response(200, [], $body));
        $this->assertSame($body, (string) $response->getBody());
        app(TenantContext::class)->set('priced-tenant');
        app(AiCallMeter::class)->meterChat(new AiResponse('test', 'openrouter', 'vendor/arbitrary-chat', 100, 50, 150));
        $row = UsageRecord::where('tenant_id', 'priced-tenant')->firstOrFail();
        $this->assertSame('actual', $row->cost_method);
        $this->assertEqualsWithDelta(.73, (float) $row->cost_total, .0000001);
        $this->assertTrue($capture->isEmpty());
        Http::assertNothingSent();
    }

    public function test_existing_price_maintenance_refreshes_feeds_before_snapshots(): void
    {
        $registry = \Mockery::mock(PricingRegistry::class);
        $registry->shouldReceive('sync')->once()->ordered()->andReturn(2);
        $this->app->instance(PricingRegistry::class, $registry);
        $watch = \Mockery::mock(PriceWatchService::class);
        $watch->shouldReceive('capture')->once()->andReturn(0);
        $this->app->instance(PriceWatchService::class, $watch);
        $this->artisan('ai-finops:capture-prices')->assertSuccessful();
    }
}
