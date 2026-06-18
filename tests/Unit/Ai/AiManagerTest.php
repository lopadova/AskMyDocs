<?php

namespace Tests\Unit\Ai;

use App\Ai\AiManager;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\FakeProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\RegoloProvider;
use App\FinOps\AiCallMeter;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class AiManagerTest extends TestCase
{
    public function test_resolves_openai_as_default(): void
    {
        config()->set('ai.default', 'openai');

        $manager = new AiManager();

        $this->assertInstanceOf(OpenAiProvider::class, $manager->provider());
        $this->assertSame('openai', $manager->provider()->name());
    }

    public function test_resolves_specific_providers(): void
    {
        $manager = new AiManager();

        $this->assertInstanceOf(OpenAiProvider::class, $manager->provider('openai'));
        $this->assertInstanceOf(AnthropicProvider::class, $manager->provider('anthropic'));
        $this->assertInstanceOf(GeminiProvider::class, $manager->provider('gemini'));
        $this->assertInstanceOf(OpenRouterProvider::class, $manager->provider('openrouter'));
        $this->assertInstanceOf(RegoloProvider::class, $manager->provider('regolo'));
    }

    public function test_resolves_fake_provider_in_testing_environment(): void
    {
        // The test suite runs under APP_ENV=testing, so the gate allows it.
        $manager = new AiManager();

        $this->assertInstanceOf(FakeProvider::class, $manager->provider('fake'));
        $this->assertSame('fake', $manager->provider('fake')->name());
    }

    public function test_fake_provider_is_blocked_outside_testing_local(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('only available in the testing/local');

            (new AiManager())->provider('fake');
        } finally {
            // Restore so later tests in the suite see the real env (R16).
            $this->app->detectEnvironment(fn () => $original);
        }
    }

    public function test_regolo_supports_embeddings(): void
    {
        config()->set('ai.default', 'regolo');
        config()->set('ai.embeddings_provider', 'regolo');

        $manager = new AiManager();

        $this->assertSame('regolo', $manager->embeddingsProvider()->name());
    }

    public function test_caches_resolved_providers(): void
    {
        $manager = new AiManager();

        $first = $manager->provider('openai');
        $second = $manager->provider('openai');

        $this->assertSame($first, $second);
    }

    public function test_throws_on_unconfigured_provider(): void
    {
        config()->set('ai.providers.nonexistent', null);

        $manager = new AiManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AI provider [nonexistent] is not configured.');

        $manager->provider('nonexistent');
    }

    public function test_embeddings_provider_uses_configured_override(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', 'openai');

        $manager = new AiManager();
        $provider = $manager->embeddingsProvider();

        $this->assertSame('openai', $provider->name());
    }

    public function test_embeddings_provider_falls_back_to_default(): void
    {
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', null);

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_provider_throws_for_unsupported(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', 'anthropic');

        $manager = new AiManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not support embeddings/i');

        $manager->embeddingsProvider();
    }

    public function test_embeddings_auto_fallback_when_chat_provider_lacks_support(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.gemini.key', null);
        config()->set('ai.providers.regolo.key', null);
        config()->set('ai.providers.openrouter.key', null);

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_prefers_openai_when_all_keys_present(): void
    {
        // Fallback order is openai → openrouter → regolo → gemini (R14:
        // 1536-dim-default providers first so a stock pgvector schema
        // doesn't silently corrupt under auto-selection).
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.gemini.key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');
        config()->set('ai.providers.openrouter.key', 'or-test');

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_picks_openrouter_when_openai_missing(): void
    {
        // openai missing → next 1536-dim-default candidate is openrouter
        // (openai/text-embedding-3-small).
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.key', null);
        config()->set('ai.providers.gemini.key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');
        config()->set('ai.providers.openrouter.key', 'or-test');

        $manager = new AiManager();

        $this->assertSame('openrouter', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_picks_regolo_when_only_non_1536_dim_keys_present(): void
    {
        // No 1536-dim-default provider keyed → operator deliberately runs
        // on a non-stock dim layout; pick regolo before gemini (Qwen3 has
        // a richer model catalogue than `text-embedding-004`).
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.key', null);
        config()->set('ai.providers.openrouter.key', null);
        config()->set('ai.providers.gemini.key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');

        $manager = new AiManager();

        $this->assertSame('regolo', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_throws_when_no_keys_configured(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.key', null);
        config()->set('ai.providers.gemini.key', null);
        config()->set('ai.providers.regolo.key', null);
        config()->set('ai.providers.openrouter.key', null);

        $manager = new AiManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no fallback embeddings provider is configured/i');

        $manager->embeddingsProvider();
    }

    public function test_embeddings_uses_openrouter_when_default_and_only_keyed(): void
    {
        config()->set('ai.default', 'openrouter');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openrouter.key', 'or-test');

        $manager = new AiManager();

        $this->assertSame('openrouter', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_skips_anthropic_even_if_keyed(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        // anthropic is on the SDK config shape (key, not api_key) since v8.16/W2.
        config()->set('ai.providers.anthropic.key', 'ak-test');
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.gemini.key', null);
        config()->set('ai.providers.regolo.key', null);

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }

    // ---------------------------------------------------------------------
    // FinOps metering gate (v8.16/W2) — the AiCallMeter bridge must NOT fire
    // on a call that already went through the laravel/ai SDK (double-count
    // guard), and MUST fire on the residual raw-Http with-tools turn (R26).
    // ---------------------------------------------------------------------

    public function test_bridge_skips_metering_for_openai_no_tools_sdk_chat(): void
    {
        config()->set('ai.default', 'openai');
        // SDK no-tools chat → /responses; metered by the finops lifecycle hook.
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'r', 'model' => 'gpt-4o', 'status' => 'completed',
            'output' => [[
                'type' => 'message', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'hi']],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $meter = Mockery::mock(AiCallMeter::class);
        $meter->shouldNotReceive('meterChat');
        $this->app->instance(AiCallMeter::class, $meter);

        (new AiManager())->chat('s', 'u');
    }

    public function test_bridge_meters_openai_with_tools_http_chat(): void
    {
        config()->set('ai.default', 'openai');
        // With-tools chat → raw Http:: /chat/completions; the SDK hook does NOT
        // fire, so the bridge must record it.
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'x'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ])]);

        $meter = Mockery::mock(AiCallMeter::class);
        $meter->shouldReceive('meterChat')->once();
        $this->app->instance(AiCallMeter::class, $meter);

        (new AiManager())->chat('s', 'u', ['tools' => [['type' => 'function', 'function' => ['name' => 'x']]]]);
    }

    public function test_bridge_skips_metering_for_openai_sdk_embeddings(): void
    {
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'text-embedding-3-small',
            'data' => [['index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 3],
        ])]);

        $meter = Mockery::mock(AiCallMeter::class);
        $meter->shouldNotReceive('meterEmbeddings');
        $this->app->instance(AiCallMeter::class, $meter);

        (new AiManager())->generateEmbeddings(['x']);
    }

    public function test_bridge_skips_metering_for_openrouter_no_tools_sdk_chat(): void
    {
        // openrouter is HYBRID since W2 commit 4: no-tools chat → SDK
        // /chat/completions (metered by the finops hook), so the bridge must skip.
        config()->set('ai.default', 'openrouter');
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'openai/gpt-4o-mini',
            'choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ])]);

        $meter = Mockery::mock(AiCallMeter::class);
        $meter->shouldNotReceive('meterChat');
        $this->app->instance(AiCallMeter::class, $meter);

        (new AiManager())->chat('s', 'u');
    }

    public function test_bridge_meters_openrouter_with_tools_http_chat(): void
    {
        config()->set('ai.default', 'openrouter');
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'openai/gpt-4o-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'x'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ])]);

        $meter = Mockery::mock(AiCallMeter::class);
        $meter->shouldReceive('meterChat')->once();
        $this->app->instance(AiCallMeter::class, $meter);

        (new AiManager())->chat('s', 'u', ['tools' => [['type' => 'function', 'function' => ['name' => 'x']]]]);
    }
}
