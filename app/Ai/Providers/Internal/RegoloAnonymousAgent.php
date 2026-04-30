<?php

namespace App\Ai\Providers\Internal;

use Laravel\Ai\AnonymousAgent;

/**
 * Per-call agent that exposes `maxTokens()` and `temperature()` so
 * `\Laravel\Ai\Gateway\TextGenerationOptions::forAgent()` picks them
 * up. Plain `AnonymousAgent` exposes neither, which silently drops
 * caller-supplied `max_tokens` / `temperature` overrides — see the
 * docblock on `RegoloProvider::makeAgent()` for the failure mode.
 *
 * Internal to `App\Ai\Providers\RegoloProvider`; not part of the
 * public DTO surface.
 */
final class RegoloAnonymousAgent extends AnonymousAgent
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
