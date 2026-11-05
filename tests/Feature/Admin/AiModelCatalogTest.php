<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Services\Admin\AiModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Tests\TestCase;

final class AiModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        foreach (AiModelCatalog::PROVIDERS as $provider) {
            config(["ai.providers.$provider.key" => 'server-only-key', "ai.providers.$provider.url" => 'https://'.$provider.'.example.test/v1']);
        }
    }

    private function catalog(array $rates = []): AiModelCatalog
    {
        $feed = new class($rates) implements PricingSource {
            public function __construct(private array $rates) {}
            public function all(): array { return $this->rates; }
            public function sync(): int { return count($this->rates); }
            public function syncedAt(): ?\DateTimeInterface { return now(); }
            public function name(): string { return 'litellm'; }
        };
        $manager = \Mockery::mock(PricingSourceManager::class);
        $manager->shouldReceive('source')->with('litellm')->andReturn($feed);
        return new AiModelCatalog($manager);
    }

    public function test_openrouter_models_have_live_prices_and_no_credentials_or_nontext_outputs(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'vendor/current', 'name' => 'Current', 'pricing' => ['prompt' => '.0000001', 'completion' => '.0000004'], 'supported_parameters' => ['tools']],
            ['id' => 'vendor/free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'vendor/unknown', 'pricing' => ['prompt' => '-1']],
            ['id' => 'vendor/image', 'architecture' => ['output_modalities' => ['image']]],
        ]])]);
        $data = $this->catalog()->get('openrouter', 'tenant-a');
        $models = array_column($data['models'], null, 'id');
        $this->assertCount(3, $models);
        $this->assertEqualsWithDelta(.1, $models['vendor/current']['input_per_million'], .00001);
        $this->assertSame('USD', $models['vendor/current']['currency']);
        $this->assertSame('openrouter', $models['vendor/current']['price_source']);
        $this->assertSame(0.0, $models['vendor/free']['input_per_million']);
        $this->assertNull($models['vendor/unknown']['input_per_million']);
        $this->assertNull($models['vendor/unknown']['output_per_million']);
        $this->assertStringNotContainsString('server-only-key', json_encode($data));
        $this->assertSame($data, $this->catalog()->get('openrouter', 'tenant-a'));
        Http::assertSentCount(1);
        $this->catalog()->get('openrouter', 'tenant-b');
        Http::assertSentCount(2);
    }

    public function test_failed_refresh_retains_the_actual_previous_timestamp_and_marks_the_catalog_stale(): void
    {
        Http::fakeSequence()->push(['data' => [['id' => 'vendor/current', 'pricing' => ['prompt' => '.000001', 'completion' => '.000002']]]])->pushStatus(503);
        $catalog = $this->catalog();
        $first = $catalog->get('openrouter', 'tenant-a');
        $this->travel(2)->hours();
        $failed = $catalog->get('openrouter', 'tenant-a', true);
        $this->assertTrue($failed['stale']);
        $this->assertSame('catalog_unavailable', $failed['error']);
        $this->assertSame($first['updated_at'], $failed['updated_at']);
        $this->assertSame($first['models'], $failed['models']);
    }

    public function test_openai_availability_is_joined_to_finops_prices_without_provider_default_guesses(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-current'], ['id' => 'gpt-unpriced'], ['id' => 'text-embedding-new'], ['id' => 'gpt-retired', 'shutdown_date' => '2025-01-01']]])]);
        $data = $this->catalog(['gpt-current' => ['mode' => 'chat', 'input_cost_per_token' => .0000008, 'output_cost_per_token' => .0000032]])->get('openai', 'tenant-a');
        $models = array_column($data['models'], null, 'id');
        $this->assertCount(2, $models);
        $this->assertEqualsWithDelta(.8, $models['gpt-current']['input_per_million'], .00001);
        $this->assertSame('litellm', $models['gpt-current']['price_source']);
        $this->assertNotNull($models['gpt-current']['price_updated_at']);
        $this->assertNull($models['gpt-unpriced']['input_per_million']);
    }

    public function test_regolo_catalog_prices_use_eur_and_filter_nonchat_models(): void
    {
        Http::fake(['https://regolo.example.test/model_group/info' => Http::response(['data' => [
            ['model_group' => 'current-chat', 'mode' => 'chat', 'input_cost_per_token' => .0000007, 'output_cost_per_token' => .0000021, 'supports_function_calling' => true],
            ['model_group' => 'embedding-model', 'mode' => 'embedding'],
        ]])]);
        $data = $this->catalog()->get('regolo', 'tenant-a');
        $this->assertCount(1, $data['models']);
        $this->assertSame('EUR', $data['models'][0]['currency']);
        $this->assertEqualsWithDelta(.7, $data['models'][0]['input_per_million'], .00001);
        $this->assertSame('current-chat', $data['models'][0]['id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer server-only-key'));
    }

    public function test_anthropic_pagination_and_retirement_are_respected(): void
    {
        Http::fakeSequence()->push(['data' => [['id' => 'claude-current', 'display_name' => 'Current Claude']], 'has_more' => true, 'last_id' => 'claude-current'])
            ->push(['data' => [['id' => 'claude-retired', 'lifecycle' => 'retired']], 'has_more' => false]);
        $data = $this->catalog()->get('anthropic', 'tenant-a');
        $this->assertSame(['claude-current'], array_column($data['models'], 'id'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after_id=claude-current') && $request->hasHeader('x-api-key', 'server-only-key'));
    }

    public function test_gemini_filters_by_generation_methods_and_normalizes_model_ids(): void
    {
        Http::fake(['*' => Http::response(['models' => [
            ['name' => 'models/gemini-current', 'displayName' => 'Current Gemini', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/embedding', 'supportedGenerationMethods' => ['embedContent']],
        ]])]);
        $data = $this->catalog()->get('gemini', 'tenant-a');
        $this->assertSame(['gemini-current'], array_column($data['models'], 'id'));
    }

    public function test_a_missing_native_credential_never_returns_a_fabricated_catalog(): void
    {
        config(['ai.providers.openai.key' => null]);
        $data = $this->catalog()->get('openai', 'tenant-a');
        $this->assertSame([], $data['models']);
        $this->assertSame('missing_credentials', $data['error']);
        Http::assertNothingSent();
    }

    public function test_invalid_credentials_are_distinguished_without_exposing_the_provider_body(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'server-only-key rejected']], 401)]);
        $data = $this->catalog()->get('openai', 'tenant-a');
        $this->assertSame('credentials_rejected', $data['error']);
        $this->assertStringNotContainsString('server-only-key', json_encode($data));
    }

    public function test_manual_prices_keep_their_currency_unit_and_actual_modification_date(): void
    {
        PricingOverride::create(['provider' => 'regolo', 'model' => 'current-chat', 'unit' => 'per_million',
            'currency' => 'EUR', 'input_cost_per_token' => 1.2, 'output_cost_per_token' => 4.8]);
        Http::fake(['*' => Http::response(['data' => [['model_group' => 'current-chat', 'mode' => 'chat']]])]);
        $model = $this->catalog()->get('regolo', 'tenant-a')['models'][0];
        $this->assertSame('manual', $model['price_source']);
        $this->assertSame('EUR', $model['currency']);
        $this->assertEqualsWithDelta(1.2, $model['input_per_million'], .00001);
        $this->assertEqualsWithDelta(4.8, $model['output_per_million'], .00001);
        $this->assertNotNull($model['price_updated_at']);
    }

    public function test_failed_price_feed_does_not_hide_available_models_or_invent_prices(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-current']]])]);
        $feed = \Mockery::mock(PricingSource::class);
        $feed->shouldReceive('syncedAt')->andReturn(null);
        $feed->shouldReceive('sync', 'all')->andThrow(new \RuntimeException('Feed unavailable'));
        $manager = \Mockery::mock(PricingSourceManager::class);
        $manager->shouldReceive('source')->with('litellm')->andReturn($feed);
        $data = (new AiModelCatalog($manager))->get('openai', 'tenant-a');
        $this->assertNull($data['error']);
        $this->assertSame('gpt-current', $data['models'][0]['id']);
        $this->assertNull($data['models'][0]['input_per_million']);
        $this->assertNull($data['models'][0]['price_updated_at']);
    }
}
