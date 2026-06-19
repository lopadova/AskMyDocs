<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use App\FinOps\ChatTurnCost;
use App\FinOps\ChatTurnCostResolver;
use Tests\TestCase;

/**
 * v8.16/W3 — the server-side cost resolver runs the finops pricing cascade and
 * stays healthy in BOTH states (finops on AND off, R43). Pricing feeds are
 * disabled so the cascade never HTTPs on a cache miss; cost resolves to 0 in the
 * base currency — we assert the SHAPE, not the price.
 */
final class ChatTurnCostResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Cost resolution requires metering ON (the hook warms the price cache);
            // feeds disabled so resolution never HTTPs on a cache miss.
            'ai-finops.metering' => true,
            'ai-finops.pricing.litellm.enabled' => false,
            'ai-finops.pricing.openrouter.enabled' => false,
        ]);
    }

    public function test_resolves_a_cost_when_finops_enabled(): void
    {
        config()->set('ai-finops.enabled', true);

        $cost = app(ChatTurnCostResolver::class)->resolve('openai', 'gpt-4o', 1000, 250, 'hello', 'world');

        $this->assertInstanceOf(ChatTurnCost::class, $cost);
        // Cost is a fixed-precision decimal STRING (8 dp), not a float (money).
        $this->assertIsString($cost->cost);
        $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $cost->cost);
        // Currency follows the configurable base, not a hard-coded literal.
        $this->assertSame((string) config('ai-finops.currency.base', 'USD'), $cost->currency);
        // method is one of the finops CostMethod values.
        $this->assertContains($cost->method, ['actual', 'computed', 'estimated', 'covered']);
    }

    public function test_returns_null_when_finops_disabled(): void
    {
        config()->set('ai-finops.enabled', false);

        $cost = app(ChatTurnCostResolver::class)->resolve('openai', 'gpt-4o', 1000, 250);

        $this->assertNull($cost);
    }

    public function test_skips_synthetic_turns_with_none_or_empty_provider_or_model(): void
    {
        config()->set('ai-finops.enabled', true);

        // Refusal / error logs record provider/model as `none` (no LLM call) — there
        // is nothing to price, and the pair was never warmed in the price cache, so
        // resolving would risk a cold-cache feed fetch on the response path. Skip.
        $this->assertNull(app(ChatTurnCostResolver::class)->resolve('none', 'none', 100, 50));
        $this->assertNull(app(ChatTurnCostResolver::class)->resolve('', '', 100, 50));
        $this->assertNull(app(ChatTurnCostResolver::class)->resolve('openai', 'none', 100, 50));
        $this->assertNull(app(ChatTurnCostResolver::class)->resolve('NONE', 'gpt-4o', 100, 50));
    }

    public function test_handles_null_token_counts_without_throwing(): void
    {
        config()->set('ai-finops.enabled', true);

        // Same known provider/model as the enabled test — vary ONLY the tokens so
        // the assertion can't depend on whether the registry prices a given model.
        $cost = app(ChatTurnCostResolver::class)->resolve('openai', 'gpt-4o', null, null);

        // Null tokens clamp to 0 → a valid (zero) resolution, never an exception.
        $this->assertInstanceOf(ChatTurnCost::class, $cost);
    }
}
