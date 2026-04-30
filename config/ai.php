<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The provider used for chat completions. Embeddings can use a different
    | provider via AI_EMBEDDINGS_PROVIDER (useful when using Anthropic or
    | OpenRouter for chat, since they don't offer an embeddings endpoint).
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
    | Provider used specifically for generating embeddings. If null, the
    | default provider is used. Must be a provider that supports embeddings
    | (openai, gemini, regolo). Anthropic and OpenRouter do NOT support
    | embeddings. Regolo serves Qwen3-Embedding-8B at 4096 dims — see the
    | KB_EMBEDDINGS_DIMENSIONS warning in `.env.example` if you switch.
    |
    */

    'embeddings_provider' => env('AI_EMBEDDINGS_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
            'embeddings_model' => env('OPENAI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
            'temperature' => env('OPENAI_TEMPERATURE', 0.2),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
            'timeout' => env('OPENAI_TIMEOUT', 120),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'chat_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-4-20250514'),
            'temperature' => env('ANTHROPIC_TEMPERATURE', 0.2),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
            'timeout' => env('ANTHROPIC_TIMEOUT', 120),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-2.0-flash'),
            'embeddings_model' => env('GEMINI_EMBEDDINGS_MODEL', 'text-embedding-004'),
            'temperature' => env('GEMINI_TEMPERATURE', 0.2),
            'max_tokens' => env('GEMINI_MAX_TOKENS', 4096),
            'timeout' => env('GEMINI_TIMEOUT', 120),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'chat_model' => env('OPENROUTER_CHAT_MODEL', 'openai/gpt-4o-mini'),
            'app_name' => env('OPENROUTER_APP_NAME', 'AskMyDocs'),
            'site_url' => env('OPENROUTER_SITE_URL'),
            'temperature' => env('OPENROUTER_TEMPERATURE', 0.2),
            'max_tokens' => env('OPENROUTER_MAX_TOKENS', 4096),
            'timeout' => env('OPENROUTER_TIMEOUT', 120),
        ],

        // Regolo entry uses the laravel/ai SDK shape because AskMyDocs's
        // RegoloProvider delegates to the SDK + padosoft/laravel-ai-regolo
        // package. The other four providers above stay on the AskMyDocs
        // legacy shape until their SDK migration lands (W2.B.full follow-up).
        'regolo' => [
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => env('REGOLO_API_KEY'),
            'url' => env('REGOLO_BASE_URL', 'https://api.regolo.ai/v1'),
            'timeout' => env('REGOLO_TIMEOUT', 120),
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
            'max_tokens' => (int) env('REGOLO_MAX_TOKENS', 4096),
            'temperature' => (float) env('REGOLO_TEMPERATURE', 0.2),
            'models' => [
                'text' => [
                    'default' => env('REGOLO_CHAT_MODEL', 'Llama-3.3-70B-Instruct'),
                    'cheapest' => env('REGOLO_CHAT_MODEL_CHEAPEST', 'Llama-3.1-8B-Instruct'),
                    'smartest' => env('REGOLO_CHAT_MODEL_SMARTEST', 'Llama-3.3-70B-Instruct'),
                ],
                'embeddings' => [
                    'default' => env('REGOLO_EMBEDDINGS_MODEL', 'Qwen3-Embedding-8B'),
                    // env() returns a string from .env files; cast to int
                    // because the SDK signature is `int $dimensions` and a
                    // string value would crash on a strict TypeError when
                    // the embeddings gateway is invoked.
                    'dimensions' => (int) env('REGOLO_EMBEDDINGS_DIMENSIONS', 4096),
                ],
                'reranking' => [
                    'default' => env('REGOLO_RERANKING_MODEL', 'jina-reranker-v2'),
                ],
            ],
        ],

    ],

];
