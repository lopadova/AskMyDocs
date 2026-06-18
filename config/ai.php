<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The provider used for chat completions. Embeddings can use a different
    | provider via AI_EMBEDDINGS_PROVIDER (useful when chat is on a provider
    | that has no embeddings endpoint — e.g. Anthropic — or when you want
    | to keep chat and embeddings on separate models / cost tiers).
    | OpenRouter exposes an OpenAI-compatible /v1/embeddings endpoint
    | routing both openai/text-embedding-3-small (default, 1536 dims) and
    | qwen/qwen3-embedding-4b (2560 dims).
    |
    | Supported: "openai", "anthropic", "gemini", "openrouter", "regolo"
    |
    */

    'default' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Embeddings Provider
    |--------------------------------------------------------------------------
    |
    | Provider used specifically for generating embeddings. Must be a provider
    | that supports embeddings (openai, gemini, regolo, openrouter). Anthropic
    | does NOT expose an embeddings endpoint. OpenRouter exposes an OpenAI-
    | compatible `/v1/embeddings` and routes both `openai/text-embedding-3-small`
    | (default — 1536 dims, $0.02 / 1M tokens) and `qwen/qwen3-embedding-4b`
    | (2560 dims, GA Oct 2025). When `AI_PROVIDER` is anthropic and
    | `AI_EMBEDDINGS_PROVIDER` is not set, AiManager auto-selects the first
    | embeddings-capable provider with a configured API key, in this order:
    | openai → openrouter → regolo → gemini. 1536-dim defaults (openai +
    | openrouter routing `openai/text-embedding-3-small`) come first so the
    | stock pgvector schema stays consistent under auto-selection (R14).
    | Regolo serves Qwen3-Embedding-8B at 4096 dims and Gemini's default
    | `text-embedding-004` is 768 dims — both REQUIRE migrating the
    | `vector(N)` column + `KB_EMBEDDINGS_DIMENSIONS` in lock-step before
    | use; set `AI_EMBEDDINGS_PROVIDER` explicitly to opt in.
    |
    */

    'embeddings_provider' => env('AI_EMBEDDINGS_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Token Cost Rates (v4.5/W7)
    |--------------------------------------------------------------------------
    |
    | USD per 1M tokens, per (provider, model). Used by the FE token/cost
    | meter on every assistant message to compute the per-turn cost from
    | the persisted prompt_tokens / completion_tokens counts.
    |
    | Format: `cost_rates[provider][model] = ['input' => float, 'output' => float]`.
    | Rates are USD per million tokens (consistent with provider pricing pages).
    | Missing models fall back to the provider's `default` entry; missing
    | providers cause the FE meter to render `—` (no cost guess).
    |
    | The FE pulls these via GET /api/chat/cost-rates (anonymous, cached).
    | Keep this list small — it's a public surface — and only list the
    | models AskMyDocs actually serves in production.
    |
    */
    'cost_rates' => [
        'openai' => [
            'default' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        ],
        'anthropic' => [
            'default' => ['input' => 3.00, 'output' => 15.00],
            'claude-sonnet-4-20250514' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-4-20250514' => ['input' => 15.00, 'output' => 75.00],
            'claude-haiku-3-5-20241022' => ['input' => 0.80, 'output' => 4.00],
        ],
        'gemini' => [
            'default' => ['input' => 0.075, 'output' => 0.30],
            'gemini-2.0-flash' => ['input' => 0.075, 'output' => 0.30],
            'gemini-2.5-pro' => ['input' => 1.25, 'output' => 5.00],
        ],
        'openrouter' => [
            // OpenRouter pricing varies per model; users see real cost
            // on OpenRouter dashboard. Default a conservative mid-range.
            'default' => ['input' => 2.00, 'output' => 6.00],
        ],
        'regolo' => [
            // Regolo is a self-hosted EU provider — no per-token cost.
            'default' => ['input' => 0.00, 'output' => 0.00],
        ],
    ],

    'providers' => [

        // OpenAI — HYBRID (v8.16/W2). No-tools chat + embeddings flow through the
        // laravel/ai SDK (native `openai` driver: /responses + /embeddings),
        // metered by the finops AgentPrompted / EmbeddingsGenerated hooks. The MCP
        // with-tools turn stays on raw Http:: /chat/completions — the SDK cannot
        // host AskMyDocs's external tool loop (see W2-sdk-migration-findings.md) —
        // and that residual path is metered by the AiCallMeter bridge. Config is
        // the SDK shape (driver/key/url/models); the Http branch reads the same
        // keys, so there is a single source of truth.
        'openai' => [
            'driver' => 'openai',
            'name' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => is_numeric($v = env('OPENAI_TIMEOUT')) ? (int) $v : 120,
            'temperature' => is_numeric($v = env('OPENAI_TEMPERATURE')) ? (float) $v : 0.2,
            'max_tokens' => is_numeric($v = env('OPENAI_MAX_TOKENS')) ? (int) $v : 4096,
            'models' => [
                'text' => ['default' => env('OPENAI_CHAT_MODEL', 'gpt-4o')],
                'embeddings' => ['default' => env('OPENAI_EMBEDDINGS_MODEL', 'text-embedding-3-small')],
            ],
        ],

        // Anthropic — laravel/ai SDK shape (v8.16/W2). The native `anthropic`
        // driver reads driver/key/url; model + sampling flow via the per-call
        // agent (SdkAnonymousAgent). No embeddings, no tool path → fully on the
        // SDK, metered by the finops AgentPrompted hook (no AiCallMeter bridge).
        'anthropic' => [
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'timeout' => is_numeric($v = env('ANTHROPIC_TIMEOUT')) ? (int) $v : 120,
            'temperature' => is_numeric($v = env('ANTHROPIC_TEMPERATURE')) ? (float) $v : 0.2,
            'max_tokens' => is_numeric($v = env('ANTHROPIC_MAX_TOKENS')) ? (int) $v : 4096,
            'models' => [
                'text' => ['default' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-4-20250514')],
            ],
        ],

        // Gemini — laravel/ai SDK shape (v8.16/W2). Native `gemini` driver; the
        // assistant→model role remap, x-goog-api-key header auth, and the
        // batchEmbedContents (768-dim) embeddings call are handled by the SDK
        // gateway. Fully on the SDK (no tool path), metered by the finops hooks.
        'gemini' => [
            'driver' => 'gemini',
            'name' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
            'timeout' => is_numeric($v = env('GEMINI_TIMEOUT')) ? (int) $v : 120,
            'temperature' => is_numeric($v = env('GEMINI_TEMPERATURE')) ? (float) $v : 0.2,
            'max_tokens' => is_numeric($v = env('GEMINI_MAX_TOKENS')) ? (int) $v : 4096,
            'models' => [
                'text' => ['default' => env('GEMINI_CHAT_MODEL', 'gemini-2.0-flash')],
                'embeddings' => ['default' => env('GEMINI_EMBEDDINGS_MODEL', 'text-embedding-004')],
            ],
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'chat_model' => env('OPENROUTER_CHAT_MODEL', 'openai/gpt-4o-mini'),
            'embeddings_model' => env('OPENROUTER_EMBEDDINGS_MODEL', 'openai/text-embedding-3-small'),
            'app_name' => env('OPENROUTER_APP_NAME', 'AskMyDocs'),
            'site_url' => env('OPENROUTER_SITE_URL'),
            'temperature' => is_numeric($v = env('OPENROUTER_TEMPERATURE')) ? (float) $v : 0.2,
            'max_tokens' => is_numeric($v = env('OPENROUTER_MAX_TOKENS')) ? (int) $v : 4096,
            'timeout' => is_numeric($v = env('OPENROUTER_TIMEOUT')) ? (int) $v : 120,
        ],

        // Regolo + Anthropic + Gemini use the laravel/ai SDK shape
        // (driver/key/url/models) because their providers delegate to the SDK.
        // OpenAI / OpenRouter above still carry the AskMyDocs legacy shape
        // (api_key/base_url/chat_model) pending their own W2 SDK migration commits.
        'regolo' => [
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => env('REGOLO_API_KEY'),
            'url' => env('REGOLO_BASE_URL', 'https://api.regolo.ai/v1'),
            'timeout' => is_numeric($v = env('REGOLO_TIMEOUT')) ? (int) $v : 120,
            // Provider-level defaults for every chat call. Per-call
            // overrides via `$options['max_tokens']` / `$options['temperature']`
            // (e.g. `ConversationController::generateTitle` capping
            // titles at 60 tokens) take precedence — see
            // `RegoloProvider::resolveMaxTokens()` /
            // `resolveTemperature()`. Both reach the SDK via
            // `RegoloAnonymousAgent::maxTokens()` /
            // `temperature()`, then propagate through
            // `Laravel\Ai\Gateway\TextGenerationOptions::forAgent()`
            // into `padosoft/laravel-ai-regolo`'s `BuildsTextRequests`.
            // `is_numeric($v = env(...)) ? cast($v) : default` falls
            // back to the default when the env var is non-numeric or
            // empty. Naked `(int) env(...)` would silently turn `'abc'`
            // or `''` into `0` and bypass `RegoloProvider::resolveMaxTokens()`'s
            // own runtime guard (which only fires on per-call $options,
            // not on this config-layer fallback). The assignment-in-
            // expression pattern is `config:cache`-safe (no closure).
            'max_tokens' => is_numeric($v = env('REGOLO_MAX_TOKENS')) ? (int) $v : 4096,
            'temperature' => is_numeric($v = env('REGOLO_TEMPERATURE')) ? (float) $v : 0.2,
            'models' => [
                'text' => [
                    'default' => env('REGOLO_CHAT_MODEL', 'Llama-3.3-70B-Instruct'),
                    'cheapest' => env('REGOLO_CHAT_MODEL_CHEAPEST', 'Llama-3.1-8B-Instruct'),
                    'smartest' => env('REGOLO_CHAT_MODEL_SMARTEST', 'Llama-3.3-70B-Instruct'),
                ],
                'embeddings' => [
                    'default' => env('REGOLO_EMBEDDINGS_MODEL', 'Qwen3-Embedding-8B'),
                    // SDK signature is `int $dimensions` so the cast is
                    // load-bearing — a string would TypeError downstream.
                    // Same `is_numeric()` fallback as max_tokens / temperature
                    // above so a non-numeric env (`'abc'` / `''`) doesn't
                    // collapse to 0 (which would later surface as a confusing
                    // pgvector dimension-mismatch on the FIRST embedding).
                    'dimensions' => is_numeric($v = env('REGOLO_EMBEDDINGS_DIMENSIONS')) ? (int) $v : 4096,
                ],
                'reranking' => [
                    'default' => env('REGOLO_RERANKING_MODEL', 'jina-reranker-v2'),
                ],
            ],
        ],

        // Deterministic OFFLINE provider for E2E / local demo — no external
        // calls, canned chat answer, constant embedding vector. Resolvable
        // ONLY in the testing/local environments: AiManager::resolveFakeProvider()
        // throws in any other environment regardless of how it is named. It is
        // selected by pointing `ai.default` / `ai.embeddings_provider` at 'fake'
        // (the E2E/local path does that via AI_PROVIDER / AI_EMBEDDINGS_PROVIDER).
        // NOT a user-facing provider — absent from the "Supported:" list above
        // on purpose. See app/Ai/Providers/FakeProvider.php + playwright.config.ts.
        'fake' => [
            'name' => 'fake',
            'dimensions' => is_numeric($v = env('KB_EMBEDDINGS_DIMENSIONS')) ? (int) $v : 1536,
            // Authoritative model strings — FakeProvider reads these (via
            // modelName()) when stamping its AiResponse / EmbeddingsResponse,
            // and EmbeddingCacheService::resolveModelName() reads
            // `embeddings_model` for its lookup key. Single source of truth, so
            // streaming turns never record 'unknown' and cache reads always hit.
            'chat_model' => 'fake-deterministic',
            'embeddings_model' => 'fake-deterministic',
        ],

    ],

];
