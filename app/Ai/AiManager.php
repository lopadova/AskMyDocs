<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\RegoloProvider;
use App\Ai\Support\ToolTurnDetector;
use App\FinOps\AiCallMeter;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AiManager
{
    /**
     * Auto-fallback search order when the chat provider doesn't support
     * embeddings AND `AI_EMBEDDINGS_PROVIDER` is not set.
     *
     * Order is chosen to minimise the risk of a silent dimension mismatch
     * against the default `KB_EMBEDDINGS_DIMENSIONS=1536` pgvector schema
     * (R14 — surface failures loudly; never silently write the wrong shape):
     *
     *   1. openai      — text-embedding-3-small (1536, matches schema default)
     *   2. openrouter  — openai/text-embedding-3-small (1536, matches schema default)
     *   3. regolo      — Qwen3-Embedding-8B (4096, REQUIRES pgvector resize +
     *                    `KB_EMBEDDINGS_DIMENSIONS=4096` already configured)
     *   4. gemini      — text-embedding-004 (768, REQUIRES pgvector resize +
     *                    `KB_EMBEDDINGS_DIMENSIONS=768` already configured)
     *
     * If `KB_EMBEDDINGS_DIMENSIONS` was not migrated in lock-step with the
     * picked provider's default model, ingest writes will fail loudly at the
     * vector-cast layer rather than silently corrupting retrieval. Operators
     * who deliberately run on 4096 / 768 dims must set `AI_EMBEDDINGS_PROVIDER`
     * explicitly to make the choice auditable instead of relying on
     * auto-selection.
     */
    private const EMBEDDINGS_FALLBACK_ORDER = ['openai', 'openrouter', 'regolo', 'gemini'];

    /**
     * Hybrid (tool-capable) providers MIGRATED to the laravel/ai SDK in v8.16/W2:
     * their no-tools chat + embeddings flow through the SDK (metered by the finops
     * AgentPrompted / EmbeddingsGenerated hooks), while their MCP with-tools chat
     * turn stays on raw `Http::` (metered by the {@see AiCallMeter} bridge).
     *
     * The bridge therefore meters these providers ONLY on the with-tools call
     * (`array_key_exists('tools', $options)`); a no-tools call or an embeddings
     * call is already SDK-metered and bridging it would DOUBLE-COUNT. Mirrors
     * `McpToolCallingService::TOOL_CAPABLE_PROVIDERS` — both openai + openrouter
     * are now migrated. Any provider NOT listed here is bridged unconditionally
     * and filtered by `AiCallMeter::shouldMeter()` (which skips the fully-SDK
     * providers).
     */
    private const SDK_HYBRID_TOOL_PROVIDERS = ['openai', 'openrouter'];

    /** @var array<string, AiProviderInterface> */
    private array $resolved = [];

    public function provider(?string $name = null): AiProviderInterface
    {
        $name ??= $this->tenantDefaultProviderName();

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * v8.22 (Ciclo 3) — the chat provider for the active tenant: a runtime
     * `ai.provider` governance override (app_settings) when set, else
     * `config('ai.default')`. Resolved lazily (no constructor change) and
     * fully guarded — a governance/DB hiccup must never break the chat path,
     * so any failure falls back to the config default (R43 OFF path = the
     * pre-v8.22 behaviour exactly).
     */
    private function tenantDefaultProviderName(): string
    {
        $configDefault = (string) config('ai.default', 'openai');

        try {
            $tenant = app(\App\Support\TenantContext::class)->current();
            $override = app(\App\Services\Admin\AppSettingsResolver::class)
                ->effective('ai.provider', $tenant);

            return is_string($override) && $override !== '' ? $override : $configDefault;
        } catch (\Throwable) {
            return $configDefault;
        }
    }

    public function embeddingsProvider(): AiProviderInterface
    {
        $explicit = config('ai.embeddings_provider');

        if ($explicit !== null && $explicit !== '') {
            $provider = $this->provider($explicit);

            if (! $provider->supportsEmbeddings()) {
                throw new InvalidArgumentException(
                    "Provider [{$explicit}] does not support embeddings. "
                    . 'Set AI_EMBEDDINGS_PROVIDER to openai, gemini, regolo, or openrouter.'
                );
            }

            return $provider;
        }

        $defaultName = config('ai.default', 'openai');
        $defaultProvider = $this->provider($defaultName);

        if ($defaultProvider->supportsEmbeddings()) {
            return $defaultProvider;
        }

        $fallback = $this->autoSelectEmbeddingsProvider();

        if ($fallback !== null) {
            // M5 — warning, not info: an auto-selected embeddings provider
            // can have a DIFFERENT vector dimension than the configured
            // pgvector column (e.g. gemini 768 vs the 1536-dim schema). That
            // silently corrupts ingest writes, so this must be visible at
            // the default log level, with the expected dimension surfaced
            // for the operator to cross-check against KB_EMBEDDINGS_DIMENSIONS.
            Log::warning('ai.embeddings_provider auto-selected fallback — verify the vector dimension matches the pgvector column.', [
                'chat_provider' => $defaultName,
                'embeddings_provider' => $fallback->name(),
                'reason' => "chat provider [{$defaultName}] does not support embeddings",
                'expected_dimensions' => config('kb.embeddings_dimensions'),
            ]);

            return $fallback;
        }

        throw new InvalidArgumentException(
            "Provider [{$defaultName}] does not support embeddings and no "
            . 'fallback embeddings provider is configured. '
            . 'Set AI_EMBEDDINGS_PROVIDER=openai|gemini|regolo|openrouter and provide '
            . 'the matching API key (OPENAI_API_KEY, GEMINI_API_KEY, REGOLO_API_KEY, OPENROUTER_API_KEY).'
        );
    }

    private function autoSelectEmbeddingsProvider(): ?AiProviderInterface
    {
        foreach (self::EMBEDDINGS_FALLBACK_ORDER as $name) {
            if (! $this->hasApiKey($name)) {
                continue;
            }

            $provider = $this->provider($name);

            if ($provider->supportsEmbeddings()) {
                return $provider;
            }
        }

        return null;
    }

    private function hasApiKey(string $provider): bool
    {
        $key = match ($provider) {
            // openai + gemini + regolo are on the SDK config shape (key, not api_key).
            'openai' => config('ai.providers.openai.key'),
            'gemini' => config('ai.providers.gemini.key'),
            'regolo' => config('ai.providers.regolo.key'),
            'openrouter' => config('ai.providers.openrouter.key'),
            default => null,
        };

        return is_string($key) && $key !== '';
    }

    /**
     * Whether the AiCallMeter bridge should meter this chat call.
     *
     * Hybrid providers (SDK_HYBRID_TOOL_PROVIDERS) meter their no-tools chat via
     * the SDK lifecycle hook, so the bridge fires ONLY on a raw-Http turn — which
     * the provider routes when `tools` is present OR the history carries a tool
     * turn (the MCP loop's final answer turn has tool history but no `tools`).
     * The gate MUST mirror that routing or the final turn is under-metered. Every
     * other provider is bridged unconditionally and AiCallMeter::shouldMeter()
     * filters out the fully-SDK providers.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, mixed>  $options
     */
    private function bridgeShouldMeterChat(string $provider, array $messages, array $options): bool
    {
        if (in_array($provider, self::SDK_HYBRID_TOOL_PROVIDERS, true)) {
            return array_key_exists('tools', $options)
                || ToolTurnDetector::historyHasToolTurn($messages);
        }

        return true;
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $response = $this->provider()->chat($systemPrompt, $userMessage, $options);

        // FinOps full-coverage metering (R44). Non-blocking + SDK-skipping; see
        // App\FinOps\AiCallMeter. No-op when finops metering is disabled. The
        // bridge is invoked ONLY when this call did NOT go through the SDK path
        // (the SDK lifecycle hook meters that) — double-count guard.
        // chat() has no prior history, so only `tools` in options can make this a
        // raw-Http turn.
        if ($this->bridgeShouldMeterChat($response->provider, [], $options)) {
            app(AiCallMeter::class)->meterChat($response, $userMessage);
        }

        return $response;
    }

    /**
     * Multi-turn chat with conversation history.
     *
     * History may also carry the MCP tool-loop shape (a `role:'tool'` result
     * message, or an assistant message with `tool_calls`) — see
     * {@see AiProviderInterface::chatWithHistory()} and McpToolCallingService.
     *
     * @param  list<array{
     *   role: 'user'|'assistant'|'tool',
     *   content: string,
     *   tool_calls?: mixed,
     *   tool_call_id?: mixed,
     *   name?: mixed,
     *   id?: mixed,
     * }>  $messages
     */
    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $response = $this->provider()->chatWithHistory($systemPrompt, $messages, $options);

        if ($this->bridgeShouldMeterChat($response->provider, $messages, $options)) {
            app(AiCallMeter::class)->meterChat($response, $messages);
        }

        return $response;
    }

    /**
     * Multi-turn streaming chat. See `AiProviderInterface::chatStream()`
     * for the chunk-event protocol.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return \Generator<int, StreamChunk, void, void>
     */
    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // NOTE: streaming responses are NOT yet metered here — capturing the
        // terminal usage of a Generator without wrapping the live SSE path is a
        // scoped follow-up (FinOps streaming coverage). Sync chat +
        // chatWithHistory + embeddings (the bulk of token spend) are covered.
        return $this->provider()->chatStream($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $response = $this->embeddingsProvider()->generateEmbeddings($texts);

        // Hybrid providers embed via the SDK (metered by the EmbeddingsGenerated
        // hook); bridging them would double-count. Everything else is bridged and
        // filtered by AiCallMeter::shouldMeter() (which skips fully-SDK providers).
        if (! in_array($response->provider, self::SDK_HYBRID_TOOL_PROVIDERS, true)) {
            app(AiCallMeter::class)->meterEmbeddings($response);
        }

        return $response;
    }

    private function resolve(string $name): AiProviderInterface
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("AI provider [{$name}] is not configured.");
        }

        return match ($name) {
            'openai' => new OpenAiProvider($config),
            'anthropic' => new AnthropicProvider($config),
            'gemini' => new GeminiProvider($config),
            'openrouter' => new OpenRouterProvider($config),
            'regolo' => new RegoloProvider($config),
            // Deterministic offline provider for E2E / local demo. Hard-gated
            // to testing/local so a production misconfig (AI_PROVIDER=fake)
            // can NEVER silently ship canned answers — it throws loudly
            // instead. The earlier "never in prod" comment was only a
            // convention; this makes it an enforced invariant.
            'fake' => $this->resolveFakeProvider($config),
            default => throw new InvalidArgumentException("Unknown AI provider [{$name}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveFakeProvider(array $config): AiProviderInterface
    {
        if (! app()->environment(['testing', 'local'])) {
            throw new InvalidArgumentException(
                'The [fake] AI provider is only available in the testing/local '
                . 'environments. Unset AI_PROVIDER / AI_EMBEDDINGS_PROVIDER=fake '
                . '(it ships canned answers + a constant embedding vector and must '
                . 'never run in production).'
            );
        }

        return new \App\Ai\Providers\FakeProvider($config);
    }
}
