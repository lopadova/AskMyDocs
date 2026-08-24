<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AiManager;
use App\Ai\Providers\OpenAiProvider;
use App\Services\Admin\AppSettingsResolver;
use InvalidArgumentException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the AI provider/base-URL policy (SEC-LLM-001 gate #2, F-08):
 *  - the provider name is a code-owned allow-list (`AiManager::resolve` match);
 *    an unknown or unconfigured name fails closed;
 *  - the only runtime knob (the `ai.provider` app-settings override) can select
 *    ONLY a configured provider — an unknown value falls back to the config
 *    default, never reaches the client;
 *  - the `fake` provider is refused outside testing/local so a production
 *    misconfig can never ship canned answers.
 *
 * Base URLs are sourced from `config('ai.providers.*')` (env), never from a
 * request or an app-setting, so there is no runtime-settable endpoint to police.
 */
class AiProviderAllowlistTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function resolve(string $name): object
    {
        $method = new ReflectionMethod(AiManager::class, 'resolve');

        return $method->invoke(app(AiManager::class), $name);
    }

    private function tenantDefaultProviderName(): string
    {
        $method = new ReflectionMethod(AiManager::class, 'tenantDefaultProviderName');

        return (string) $method->invoke(app(AiManager::class));
    }

    public function test_unknown_provider_name_is_rejected(): void
    {
        // Give the name a config block so we pass the "configured" check and
        // reach the code-owned match — proving the match itself is the gate.
        config(['ai.providers.bogus-provider' => ['key' => 'x']]);

        $this->expectException(InvalidArgumentException::class);
        $this->resolve('bogus-provider');
    }

    public function test_unconfigured_provider_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolve('not-in-config-at-all');
    }

    public function test_a_configured_known_provider_resolves(): void
    {
        $this->assertInstanceOf(OpenAiProvider::class, $this->resolve('openai'));
    }

    public function test_fake_provider_is_refused_outside_testing_local(): void
    {
        config(['ai.providers.fake' => ['key' => 'x']]);
        $original = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->resolve('fake');
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_app_settings_override_rejects_an_unconfigured_provider(): void
    {
        $resolver = Mockery::mock(AppSettingsResolver::class);
        $resolver->shouldReceive('effective')->andReturn('totally-unknown-provider');
        $this->app->instance(AppSettingsResolver::class, $resolver);

        // Falls back to the config default rather than honouring the bad value.
        $this->assertSame((string) config('ai.default'), $this->tenantDefaultProviderName());
    }

    public function test_app_settings_override_honours_a_configured_provider(): void
    {
        $configured = config('ai.providers.anthropic') !== null ? 'anthropic' : (string) config('ai.default');
        $resolver = Mockery::mock(AppSettingsResolver::class);
        $resolver->shouldReceive('effective')->andReturn($configured);
        $this->app->instance(AppSettingsResolver::class, $resolver);

        $this->assertSame($configured, $this->tenantDefaultProviderName());
    }
}
