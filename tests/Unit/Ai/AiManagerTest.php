<?php

namespace Tests\Unit\Ai;

use App\Ai\AiManager;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
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
}
