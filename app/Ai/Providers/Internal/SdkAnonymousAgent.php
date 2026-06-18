<?php

namespace App\Ai\Providers\Internal;

use Laravel\Ai\AnonymousAgent;

/**
 * Per-call agent that exposes `maxTokens()` and `temperature()` so
 * `\Laravel\Ai\Gateway\TextGenerationOptions::forAgent()` picks them up.
 * Plain `AnonymousAgent` exposes neither, which silently drops
 * caller-supplied `max_tokens` / `temperature` overrides — see the docblock
 * on `RegoloProvider::makeAgent()` for the failure mode.
 *
 * Shared by the native-driver SDK providers (OpenAI / Anthropic / Gemini /
 * OpenRouter) migrated off raw `Http::` in v8.16/W2. The Regolo provider keeps
 * its own `RegoloAnonymousAgent` (predates this class). Internal to
 * `App\Ai\Providers\*`; not part of the public DTO surface.
 */
class SdkAnonymousAgent extends AnonymousAgent
{
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
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
}
