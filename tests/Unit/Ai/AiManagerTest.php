<?php

namespace Tests\Unit\Ai;

use App\Ai\AiManager;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\RegoloProvider;
use InvalidArgumentException;
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
        config()->set('ai.providers.openai.api_key', 'sk-test');
        config()->set('ai.providers.gemini.api_key', null);
        config()->set('ai.providers.regolo.key', null);
        config()->set('ai.providers.openrouter.api_key', null);

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
        config()->set('ai.providers.openai.api_key', 'sk-test');
        config()->set('ai.providers.gemini.api_key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');
        config()->set('ai.providers.openrouter.api_key', 'or-test');

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_picks_openrouter_when_openai_missing(): void
    {
        // openai missing → next 1536-dim-default candidate is openrouter
        // (openai/text-embedding-3-small).
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.api_key', null);
        config()->set('ai.providers.gemini.api_key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');
        config()->set('ai.providers.openrouter.api_key', 'or-test');

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
        config()->set('ai.providers.openai.api_key', null);
        config()->set('ai.providers.openrouter.api_key', null);
        config()->set('ai.providers.gemini.api_key', 'gem-test');
        config()->set('ai.providers.regolo.key', 'rgl-test');

        $manager = new AiManager();

        $this->assertSame('regolo', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_throws_when_no_keys_configured(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openai.api_key', null);
        config()->set('ai.providers.gemini.api_key', null);
        config()->set('ai.providers.regolo.key', null);
        config()->set('ai.providers.openrouter.api_key', null);

        $manager = new AiManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no fallback embeddings provider is configured/i');

        $manager->embeddingsProvider();
    }

    public function test_embeddings_uses_openrouter_when_default_and_only_keyed(): void
    {
        config()->set('ai.default', 'openrouter');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.openrouter.api_key', 'or-test');

        $manager = new AiManager();

        $this->assertSame('openrouter', $manager->embeddingsProvider()->name());
    }

    public function test_embeddings_auto_fallback_skips_anthropic_even_if_keyed(): void
    {
        config()->set('ai.default', 'anthropic');
        config()->set('ai.embeddings_provider', null);
        config()->set('ai.providers.anthropic.api_key', 'ak-test');
        config()->set('ai.providers.openai.api_key', 'sk-test');
        config()->set('ai.providers.gemini.api_key', null);
        config()->set('ai.providers.regolo.key', null);

        $manager = new AiManager();

        $this->assertSame('openai', $manager->embeddingsProvider()->name());
    }
}
