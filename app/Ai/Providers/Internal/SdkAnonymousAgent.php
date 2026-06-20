<?php

namespace App\Ai\Providers\Internal;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * Per-call agent that exposes `maxTokens()` and `temperature()` so
 * `\Laravel\Ai\Gateway\TextGenerationOptions::forAgent()` picks them up.
 * Plain `AnonymousAgent` exposes neither, which silently drops
 * caller-supplied `max_tokens` / `temperature` overrides — see the docblock
 * on `RegoloProvider::makeAgent()` for the failure mode.
 *
 * Also implements `HasProviderOptions` so a provider can inject
 * provider-specific request-body fields — used by OpenRouter to set
 * `usage: { include: true }` so the response carries the real billed
 * `usage.cost` the finops actual-cost capture reads (v8.16/W2).
 *
 * Shared by the native-driver SDK providers (OpenAI / Anthropic / Gemini /
 * OpenRouter) migrated off raw `Http::` in v8.16/W2. The Regolo provider keeps
 * its own `RegoloAnonymousAgent` (predates this class). Internal to
 * `App\Ai\Providers\*`; not part of the public DTO surface.
 */
class SdkAnonymousAgent extends AnonymousAgent implements HasProviderOptions
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
        private readonly array $providerOptions = [],
    ) {
        parent::__construct($instructions, $messages, $tools);
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
