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
    | Supported: "openai", "anthropic", "gemini", "openrouter"
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
    | (openai, gemini). Anthropic and OpenRouter do NOT support embeddings.
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
            'chat_model' => env('OPENROUTER_CHAT_MODEL', 'anthropic/claude-sonnet-4-20250514'),
            'app_name' => env('OPENROUTER_APP_NAME', 'Enterprise KB'),
            'site_url' => env('OPENROUTER_SITE_URL'),
            'temperature' => env('OPENROUTER_TEMPERATURE', 0.2),
            'max_tokens' => env('OPENROUTER_MAX_TOKENS', 4096),
            'timeout' => env('OPENROUTER_TIMEOUT', 120),
        ],

    ],

];
